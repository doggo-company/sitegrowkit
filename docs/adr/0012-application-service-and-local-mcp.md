# ADR-0012: Application Service Layer and Local stdio MCP

- Status: Accepted
- Date: 2026-08-12
- Phase: 9.1B

## Context

Agent CMS Kit の製品定義は「育てるCMS。」であり、その中核要件のひとつが
**「管理画面で人間ができることは、AI でも同じ安全性で実行できる」**ことである。

Phase 9.1A の実測（`docs/PHASE9_1A_SPEC.md` §A）で以下が確認された。

- 業務ロジックは Controller に置かれ、戻り値は `RedirectResponse` / `Inertia\Response`。
  **HTTP / Inertia に密着しており AI からは呼べない**（F-A6）
- 認可は route middleware（`auth` / `verified`）だけで、**操作単位の認可が無い**（F-A3）
- **監査が無い**（F-A4）
- 同じ業務手順が **Controller と Artisan Command に二重実装**されていた（F-A1 = 実例）
- 破壊的操作の概念が無く、`content.delete` には削除保護が無かった（F-A7）

インターフェースを増やす（API / MCP）前にこれを解消しないと、経路ごとに検証・認可・
監査が分岐し、**AI が管理画面より強い権限を持つ**状態を作ってしまう。

## Decision

### 1. Application Service（1 操作 = 1 クラス）

`core/src/Application/` に操作クラス群を置き、Controller / API / MCP は
**入力の取り出しと結果の描画だけ**を行う。CQRS・Command Bus・DDD 一式は採用しない
（呼び出し元は 4 つで非同期要件が無く、抽象の追加コストに見合わない）。

戻り値は `Result` 1 種類に統一し、アダプタごとに成功・失敗の解釈を作らない。
`OperationClassification`（READ / WRITE / DESTRUCTIVE / EXTERNAL_EFFECT）が
**監査要否と確認要否を決める唯一の軸**である。

Media / Contact の操作クラスは **module 側**（`modules/*/Application/`）に置く。
core がモジュールのモデルへ依存すると `.agentcms/rules/boundaries.md` の
project → core 一方向依存に反するため。core の contract を implement する形にする。

### 2. 破壊的操作の確認は ConfirmationGate が SSoT

トークンは **操作 ID + 引数ハッシュ + actor + 期限**へ束縛し、**1 回で使い切る**。
管理画面もブラウザの `confirm()` をやめ、この gate を通す。
**Admin と AI で破壊的操作のルールを分けない。**

### 3. 認証は同一オリジンのセッション + ローカル stdio（G1 = Option A）

`/api/v1` は `web` ミドルウェア（セッション + CSRF）の内側に置く。
Sanctum / OAuth / リモートトークン認証は実装しない。

根拠（MCP 仕様 2026-07-28 の一次資料）: authorization は **OPTIONAL** であり、
**stdio transport は本仕様に従うべきでなく（SHOULD NOT）、資格情報は環境から取得する**と
明記されている。ローカル stdio を選ぶ限り OAuth 一式なしで仕様準拠できる。
HTTP transport を選ぶと RFC 9728 の実装が MUST となり、v1 の実装量とリスクが跳ね上がる。

### 4. MCP protocol は公式 SDK に委ねる

`@modelcontextprotocol/server` 2.0.0（MIT）を採用する。
protocol version 互換・lifecycle・tool discovery・stdio framing・将来の仕様変更を
本製品で保守しない。

```
MCP protocol / transport → official MCP SDK
CMS business logic       → Agent CMS Kit Application Service
```

推移的依存は `@modelcontextprotocol/core`（MIT）と `zod`（MIT）のみ。
旧 `@modelcontextprotocol/sdk`（v1）は express / hono / cors / jose 等を引くため採用しない。

### 5. MCP → Laravel は Artisan bridge（stdin JSON）

```
MCP client ──stdio──▶ agentcms mcp ──stdin JSON──▶ php artisan agentcms:operation <id>
```

- payload を **argv に載せない**: shell escaping / OS 差 / argv 長制限 /
  プロセス一覧への入力値露出 / command injection を避けるため
- 子プロセスは `shell: false` で「実行ファイル + 引数配列」を分離して起動する
- 実行できる Artisan コマンドは `agentcms:operation` **1 つだけ**

### 6. 公開範囲は allowlist

`app/Support/OperationCatalog.php` の 15 操作のみ。以下は**公開しない**:
任意 shell / 任意 Artisan / 生 SQL / 無制限のファイルシステム / 任意プロセス実行 /
secret・`.env`・ローカル資格情報の読み取り。

`media_replace` はサーバー上のファイルパスを受け取らず base64 のみを受ける
（任意ファイル読み取りの入口を作らないため）。

### 7. tool は schema 駆動の汎用ツール

Content Type ごとに tool を増やさない。型は `content_schema_get` で実行時に取得する。
Application 層の検証が **authoritative** で、MCP の inputSchema は discovery と早期エラー用。

### 8. transport は stdio のみ

Phase 9.1B では stdio のみ出荷する。HTTP MCP（Streamable HTTP）と WebMCP は実装しない。
将来 adapter を追加できるよう、business logic は transport から独立させておく。

## Consequences

**良くなること**

- 検証・認可・確認・監査が 1 箇所に集まり、経路が増えても分岐しない
- 「AI が管理画面より強い」状態を allowlist と分類で構造的に防げる
- Phase 9.2 で field type が増えても MCP tool 定義は変わらない

**受け入れるコスト**

- GUI 配布物の同梱依存が 1.50 MB → 13.12 MB（非圧縮）に増える
- 削除の UX がサーバー往復 1 回分増える（影響範囲を提示できる利点と引き換え）
- 2025 era の MCP クライアントでは破壊的操作を実行できない（fail safe として意図的）

## References

- `docs/PHASE9_1A_SPEC.md` — 調査・設計・owner decision gates
- `docs/PHASE9_1B_REPORT.md` — 実装記録と受け入れ結果
- MCP 仕様 2026-07-28 / `@modelcontextprotocol/server` 2.0.0
