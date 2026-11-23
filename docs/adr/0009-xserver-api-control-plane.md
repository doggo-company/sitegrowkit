# ADR-0009: XServer API を Control Plane に採用（API + SSH の責務分離）

- Status: Accepted
- Date: 2026-08-08
- Supersedes: ADR-0008 の「Cron / DB 作成 / SSL はサーバーパネル手動のみ」という前提を部分的に更新

## Context

ADR-0008 時点では「サーバーパネルが唯一の公式設定手段」としていたが、XServer は 2026-04-16 に**公式 XServer API**（https://developer.xserver.ne.jp/api/server/）、2026-05-12 に公式 CLI（`xserver-cli`）/ MCP Server / AI Skill を提供開始していた。

API の要点（公式確認済み）:

- 認証: `Authorization: Bearer xs_...`。キーは XServer アカウント管理画面で発行し、**有効期限・対象サーバー・権限スコープ（全操作 / 読み取り専用 / カスタム）**を設定可能
- ベース: `https://api.xserver.ne.jp/v1/server/{servername}/...`、JSON
- サブドメイン（docroot 指定可）/ 無料SSL（status: pending→active をポーリング可）/ MySQL DB・ユーザー・grant / Cron（crontab 互換 CRUD）/ SSH 有効化・公開鍵登録 / PHP バージョン切替 / 自動バックアップ取得・リストア / ログ / WAF・アクセス拒否 が REST で操作可能
- **ファイル操作 API は存在しない** — デプロイは SSH（公式 Skill も rsync over SSH を採用）
- 削除系に確認パラメータは無い（ガードはキーのスコープのみ）
- レート制限: スタンダード 60 req/min・同時 5

## Decision

### 1. 責務分離（公式 AI Skill と同一構成）

```
Control Plane   = XServer API（サーバー設定: subdomain / SSL / DB / Cron / PHP / SSH 鍵）
Application     = SSH（artifact 転送・release 切替・artisan・backup 実行）
```

ADR-0008 の SSH Deployment 機構はそのまま維持し、「パネルで人間が設定」としていた操作のうち API で可能なものを Provider Adapter 経由の自動化に変更する。

### 2. 実装形態: 薄い自前クライアント（公式 CLI / MCP は採用しない）

- `providers/xserver-api.ts` に fetch ベースの薄いクライアント（`XserverApi` インターフェイス + HTTP 実装 + テスト用 Fake）
- 公式 CLI 不採用の理由: npm グローバル依存の追加・サブプロセス出力パースより、既に持つ fetch + 型 + Fake 境界の方が保守・テスト性が高い。API 自体が Bearer 1 本の素直な REST で薄いクライアントのコストが低い
- 公式 MCP 不採用の理由: 対話エージェント向けローカル stdio 形態であり、決定論的な CLI からの利用に不向き
- 公式 AI Skill は「設計の答え合わせ」として採用（API+SSH 構成・rsync dry-run→本実行・env 経由キー・破壊的操作の事前合意 — 本キットの既存方針と一致）

### 3. API キーの扱い

- 環境変数 **`XSERVER_API_KEY`** のみ（公式 CLI と同名・相互運用）。設定ファイル・Git・ログ・plan 出力・ベンチマークへは一切出さない（クライアントがヘッダーを組み立て、エラー表示でも redact）
- 推奨スコープ: doctor 用途は読み取り専用キーで足りる。setup（書き込み）は必要時のみカスタムスコープキー
- `servername` は設定 `api.servername`（省略時 ssh.user）

### 4. 操作の自動化範囲と confirmation boundary

| 操作 | 従来 | 変更後 |
| --- | --- | --- |
| SSL 状態 / DB 有無 / SSH 状態 / PHP ver の**確認** | 不可（パネル目視） | doctor が API で読み取り検査（キーがあれば） |
| サブドメイン作成 / SSL 発行 / DB・ユーザー・grant 作成 / Cron 登録 / PHP 切替 | パネル手動 | **`agentcms deploy setup`** が API で実行。実行前に対象一覧を提示して確認（`--yes` で省略可）。既存リソースはスキップ（冪等） |
| リソース**削除** / バックアップ**リストア** | パネル手動 | **自動化しない**（API に確認パラメータが無く、誤爆の被害が大きい。手動 or 将来の明示コマンドで） |
| API キー発行 | — | 手動のみ（API に存在しない） |

- `XSERVER_API_KEY` 未設定・API エラー時は**従来どおり手動手順の案内へ fallback**（setup は手順を表示して終了、doctor は WARN）
- DB ユーザーのパスワードは setup がローカルで生成し、**サーバー上の shared/.env にのみ SSH 経由で書き込む**（ローカル保存・表示・ログ出力なし）

### 5. 境界の維持

XServer API のパス・パラメータは `providers/xserver-api.ts` に閉じ込める。deploy コア（plan/release/health）は API の存在を知らない。Core（template/core）には一切触れない。

## Consequences

- 「SSL をパネルで有効化してください」型の人間往復が、キーがあれば `deploy setup` 1 コマンドに置き換わる（SSL は pending→active のポーリングまで自動）
- SSL POST のボディとログ系 API の返却形式は公式リファレンスで詳細未確認 — 初回実行時に実測で検証し、本 ADR と docs を更新すること
- **実測知見（2026-08-08）**: `{servername}` は素のサーバー ID（例: `xs000000`）ではなく**初期ドメイン形式（例: `xs000000.xsrv.jp`）**。サーバー ID 指定は 403「このAPIキーでは指定されたサーバーを操作できません」になる（権限エラーに見えるが実は名前解決の問題）。既定値は `<ssh.user>.xsrv.jp` とした
- レート制限（60/min）があるためポーリングは間隔を空ける（SSL は 10 秒間隔・上限付き）
- 公式エコシステム（API/CLI/MCP/Skill）の更新は https://developer.xserver.ne.jp/changelog/ を確認する
