# ADR-0011: Figma Acquisition Architecture

- Status: Accepted
- Date: 2026-08-09
- Phase: 6

## Context

Phase 6 は Figma を正式な制作 Input として接続する。目的は Figma Raw JSON を AI へ渡すことではなく、Design Intent を決定論的に解析・正規化した Design Manifest を AI の標準経路にすること（Manifest First、Phase 5 の実証結果を Figma へ適用）。

本 ADR は 2026-08-09 時点の Figma 公式 Developer Docs（developers.figma.com）の実読結果に基づく。古いブログ・記事は根拠にしていない。

## Official API Research（2026-08 時点の実読記録）

### Authentication

- 認証方式は 3 つ: OAuth 2（推奨・他ユーザー代行）、Plan Access Token（Org/Enterprise、CI/CD 向け）、Personal Access Token（個人・ローカルツール向け）。
- PAT はヘッダ `X-Figma-Token` で送る。PAT にも scope が付き、作成時に expiration と permission を設定する（scoped PAT）。
- 2025-11-17 のプラットフォーム変更で、公開 OAuth app は再公開・審査が必須化、`file_read` / `files:read` の広域 scope は granular scope（`file_content:read` / `file_metadata:read` 等）へ移行中（deprecated but functional）。PAT も rate limit の対象。

→ Phase 6 Standard Mode は **PAT（環境変数 `FIGMA_ACCESS_TOKEN`）** を採用。OAuth UI は作らない（将来 Provider 追加可能な構造のみ確保）。

### Scopes（granular、公式 scopes ページより）

| Scope | 用途 | Phase 6 |
| --- | --- | --- |
| `file_content:read` | file / nodes / images render / image fills | **必須** |
| `file_metadata:read` | `GET /v1/files/:key/meta` | **必須** |
| `file_versions:read` | version history | 不使用（meta の `version` で足りる） |
| `library_content:read` / `library_assets:read` | published components/styles | optional（published library 利用時のみ） |
| `file_variables:read` | Variables（**Enterprise org の full member 限定**） | optional capability |
| `file_dev_resources:read` | Dev Resources | optional capability |
| `webhooks:read/write` | Webhooks V2 | Phase 6 では不使用 |
| `files:read`（deprecated） | 広域 | **依存しない** |

### Endpoints（tier / scope 実測記録）

| Endpoint | Tier | Scope | 用途 |
| --- | --- | --- | --- |
| `GET /v1/files/:key/meta` | 3 | `file_metadata:read` | Metadata First（name / version / last_touched_at / editorType） |
| `GET /v1/files/:key` | 1 | `file_content:read` | file 取得。`ids` / `depth` / `geometry` / `branch_data` パラメータあり |
| `GET /v1/files/:key/nodes?ids=` | 1 | `file_content:read` | **Node Targeting の主経路**（`ids` 必須、`version` / `depth` 指定可） |
| `GET /v1/images/:key?ids=&format=&scale=` | 1 | `file_content:read` | Frame render（Visual Baseline）。URL は 30 日で失効 |
| `GET /v1/files/:key/images` | 2 | `file_content:read` | image fills の download URL（imageRef → URL、14 日で失効） |
| `GET /v1/files/:key/variables/local` | 2 | `file_variables:read` | Variables（Enterprise 限定） |
| `GET /v1/files/:key/components` 等 | 3 | `library_content:read` | **published のみ**返す点に注意（file 内の未公開 Component は file/nodes 応答の `components` map から得る） |

### Rate Limits（2025-11-17 発効、plan × seat × tier）

- 例（Dev/Full seat、per minute）: Tier 1 = Starter 10 / Pro 15 / Org 20 / Enterprise 20。Tier 2 = 25/50/100/100。Tier 3 = 50/100/150/150。View/Collab seat はさらに低い（Tier 1 file は「月 6 回まで」級）。
- 429 時は `Retry-After` ヘッダ（秒）が返る。公式推奨: node ids のバッチ化・積極的キャッシュ・exponential backoff。

→ **Rate Limit First**: metadata（Tier 3、割当が多い）で version 確認 → 未変更なら API call 0 で Raw Cache 再利用。nodes は 1 call に ids をまとめる。429 は Retry-After 尊重・上限付き retry（無限 retry 禁止）。

### Figma MCP

- 公式 MCP Server は **beta・現在無料・将来 usage-based 課金予定**。接続クライアントは Figma MCP Catalog 掲載のものに限定（waitlist 制）。remote server（推奨）と desktop server がある。
- MCP は **write 操作をサポートする**（frame / component / variable の作成・変更）。

→ MCP は Standard Mode の依存にしない（beta / 課金予定 / client 条件 / 非決定論的 output）。Assist Mode の optional 扱いのみ。**MCP 経由でも write 系ツールは Phase 6 では使用禁止**。

### Code Connect

- Dev/Full seat の Organization / Enterprise plan 限定。`figma connect publish`（CLI）または Code Connect UI で公開。REST での mapping 読み取り API は公式 Docs に記載なし（MCP 経由で参照される）。

→ optional。存在すれば Component Registry の matching hint に使えるが、Phase 6 は publish を自動実行しない。

### Variables

- REST Variables API は **Enterprise org の full member 限定**。GET local/published は Tier 2 / `file_variables:read`。

→ Standard Mode の必須条件にしない。Token 抽出は `Styles > Node properties > repeated values` の fallback を標準とし、Variables が使える環境では最上位 confidence source（`VARIABLE`）として利用。

### Webhooks

- V2 API（`/v2/webhooks`、Tier 2、`webhooks:read/write`）。FILE_UPDATE / FILE_VERSION_UPDATE / LIBRARY_PUBLISH 等。

→ Phase 6 では実装しない（外部 callback 基盤が必要、Manual Sync で十分）。

## Decisions

### 1. Standard Mode = REST API による決定論 Pipeline

```text
connect（URL 解析 → project config）
→ inspect（Metadata First → targeted fetch → Raw Cache → Normalizer → Design Manifest）
→ diff / plan（Manifest のみから決定論生成、confidence 付き）
→ human review
→ apply（Local Project へ。Figma へは書かない）
→ verify
```

Figma への write operation は **0**。`agentcms figma apply` の適用先は Local Agent CMS Project のみ。

### 2. Provider / Adapter 境界（Phase 4 と同型）

`packages/cli/src/figma/api.ts` に `FigmaApi` interface を置き、`HttpFigmaApi`（本番、`fetch` + `X-Figma-Token`）と `FakeFigmaApi`（テスト、Synthetic Fixture 供給）が実装する。CLI ロジックは境界越しにのみ Figma へ触れる。429（Retry-After 尊重、上限付き backoff）・403（plan/scope 診断）・401（token 失効）は HttpFigmaApi 層で処理し、`{what, why, fix}` 診断へ変換する。

### 3. Token 管理

- `FIGMA_ACCESS_TOKEN` 環境変数のみ。`--token` オプションは提供しない（shell history 残留）。
- token は Git / project config / manifest / logs / CLI 出力へ保存・表示しない。エラーメッセージは `figd_` プレフィックスの redaction を通す（XSERVER_API_KEY と同型の保険）。secret-scan の CONTENT_PATTERNS へ Figma PAT パターンを追加。

### 4. Metadata First + Raw Cache

- 毎回 `GET /v1/files/:key/meta`（Tier 3）で `version` を確認。前回取得 version と同一なら Raw Cache を再利用し、Tier 1 call を発行しない。
- Raw Cache は `.agentcms/cache/figma/<file_key>/` に `meta.json` / `nodes-<version>-<selection_hash>.json` / `renders/` を保存。**gitignore**（Client Design Data）。secret scan 対象。AI は原則直接 Read しない（Raw Cache ≠ AI Context）。

### 5. Design Manifest（`.agentcms/manifest/figma.json`）

- 決定論的 Normalizer が Raw JSON から生成。`version: 1`、`source`（file_key / figma_version / selected node ids / normalized hash = fingerprint）、`screens`（Desktop/Mobile pairing 済み candidate）、`components`（分類 + variants + registry 対応）、`tokens`（source: VARIABLE / STYLE / INFERRED、confidence 順位 Variable > Style > Inferred）、`assets`（STATIC / MEDIA / ICON / DECORATION / REVIEW、hash 重複排除）、`cms_candidates`、`fonts`、`capabilities`、`warnings`。
- 全リストは安定ソート。同一 Figma version + node selection → バイト同一出力（snapshot test）。
- 座標の絶対値・transform matrix・非表示 layer 等の Raw bookkeeping は Manifest へ持ち込まない。Auto Layout は `flex-column / flex-row / grid candidate` へ正規化する（機械的 1:1 変換はしない）。
- Text layer に credential 様文字列を検出した場合は redact + warning（Manifest へ原文複製しない）。

### 6. Component Registry（`.agentcms/components/registry.json`）

Figma component ↔ Agent CMS code component の対応表。`component_id` / figma node_id + component_key / code target / type / variants / props / source hash / status（mapped / proposed / review）。Existing Component First: plan は新規生成前に registry と既存 Blade/Vue component を照合し、Reuse > Adapt > Generate New の順で提案する。

### 7. Capability Detection

Variables / Library / Code Connect / MCP は capability として検出し、`PASS / UNAVAILABLE(理由) / OPTIONAL` を Manifest と inspect 出力へ記録する。optional capability の欠如は失敗にしない（403 は「plan 制限」として診断し、INFERRED fallback へ切り替える）。

**Variables は既定で要求しない（Rev.1）**: Standard Mode の必要 scope を `file_metadata:read` + `file_content:read` の 2 つに保つため、`GET /variables/local` は `figma inspect --variables` の明示要求時のみ実行する。既定の capability 記録は `OPTIONAL / NOT REQUESTED`。capability 確認のためだけの API call（403 確認含む）を消費しない。

### 8. Read-Only Boundary と Live API 境界

- Figma への書込 API・MCP write 系ツール・Code Connect publish は Phase 6 で呼ばない。
- Synthetic Fixture で全品質ゲートを通過した後、実 Figma へ接続する前に停止し、scope / 対象 file / API call 予定 / secret handling / write=0 を報告して承認を得る（SPEC §94）。承認後も Read Only Smoke Test のみ。

### 9. Core / Project 境界

Figma 固有処理はすべて `packages/cli/src/figma/`（kit 層）。生成物は Project 層のみ（Blade / CSS / seeds / schema overrides — ADR-0010 の Apply 機構・Project Scope Schema Override を再利用）。Core（`template/core/`)への変更は行わない。

## Consequences

- Starter plan の PAT でも Tier 1 = 10 req/min で Phase 6 が成立する（metadata 確認は Tier 3 で 50/min、キャッシュヒット時は Tier 1 call 0）。
- Variables 非対応環境（Enterprise 以外）でも Token 抽出は STYLE / INFERRED fallback で機能する。confidence がその分下がることは Manifest に明示される。
- MCP / Code Connect が将来 GA・課金化しても Standard Mode は影響を受けない。
- published library を使わないプロジェクトでは `library_*` scope も不要（最小 scope = `file_metadata:read` + `file_content:read` の 2 つ）。
