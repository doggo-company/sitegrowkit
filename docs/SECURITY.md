# Security

原則: 「Laravel だから安全」という判断は禁止。Laravel 標準機能を優先し、不要な独自実装を避ける。
AI 向けの強制ルールは `.agentcms/rules/security.md`（そちらが AI 向け正本）。

## Threat Coverage（Phase 1 設計対象）

| 項目 | 実装方式 | 検証 |
| --- | --- | --- |
| CSRF | Laravel 標準（VerifyCsrfToken / Inertia 統合） | Pest feature test |
| XSS | Blade エスケープ既定 / `{!! !!}` 原則禁止 / Vue 既定エスケープ | コーディングルール + テスト |
| SQL Injection | Eloquent / Query Builder のみ。生 SQL への文字列結合禁止 | コーディングルール + 静的解析 |
| 認証 | Fortify（Starter Kit 標準）。独自認証禁止 | Pest |
| 2FA | Fortify two-factor | Pest |
| Login rate limiting | Fortify 標準の rate limiter | Pest |
| Authorization | Policy ベース。admin ルートは auth + verified 必須 | Pest |
| Session | secure / http_only / same_site=lax、本番 https 強制 | config + テスト |
| Security headers | ミドルウェアで X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP（公開側） | Pest + E2E |
| File upload | 拡張子・MIME 許可リスト（jpg/png/webp/avif）。**SVG 禁止（Phase 1）**。保存先は storage/（実行可能領域外）。ファイル名再生成 | Core の UploadValidator + Pest |
| Bot / Spam | Cloudflare Turnstile（サーバー側 siteverify 必須） | Pest（テストキー） |
| Secret leakage | .env 系を .gitignore、tracked ファイルへの秘密混入を verify で検査 | CLI verify + テスト |
| Dependency vulnerabilities | composer audit / npm audit（verify に含む） | CLI verify |

## Secrets

- 秘密情報は .env のみ。コード・Git へのハードコード禁止
- 本番 Turnstile 秘密鍵はコードに含めない。development / test は Cloudflare 公式テストキーを .env.example に記載（公開情報のため安全）
- .env / *.key / DB ファイルを公開ディレクトリ（public/ 配下）に置かない

## Mail Safety

- ローカルは Mailpit のみに送信
- 問い合わせ保存はメール送信より先に行い、送信失敗でもデータを失わない（Contact pipeline 設計）
- ステージングで実顧客へ誤送信しない仕組みは Phase 5 で providers 抽象と共に追加予定（[ROADMAP.md](ROADMAP.md)）

## Reporting

脆弱性を発見した場合は公開 Issue にせず、リポジトリオーナーへ直接連絡すること。
