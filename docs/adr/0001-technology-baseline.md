# ADR-0001: Phase 1 技術ベースライン

- Status: Accepted
- Date: 2026-08-08

## Context

仕様（docs/PHASE1_SPEC.md TECHNOLOGY BASELINE）は Laravel 13 / PHP 8.3+ / MySQL / Vue 3 / TypeScript / Inertia / Docker / Vite を基本方針とし、「現在の安定版を公式ドキュメントで確認した上で使用」を要求している。

確認結果（2026-08-08 時点）:

- Laravel 13: 2026-03-17 リリース、現行安定版 13.x、PHP 8.3+ 必須（laravel.com/docs/13.x/releases）
- laravel/vue-starter-kit dev-main（2026-07-30 更新）: laravel/framework ^13.17, php ^8.3, laravel/fortify ^1.37（auth + 2FA）, inertiajs/inertia-laravel ^3.0, laravel/wayfinder
- ホスト環境: macOS / Docker 29.6.1 / Compose v5.3.1 / Node 24 LTS / PHP 8.5.8 / Composer 2.10

## Decision

| 項目 | 採用 | 理由 |
| --- | --- | --- |
| Framework | Laravel 13.x | 現行安定版。仕様指定 |
| PHP | 8.5（Docker イメージ・ホストとも） | 安定版。ホスト 8.5.8 と Docker を揃え依存解決の差異を防ぐ |
| DB | MySQL 8.4 LTS | 仕様指定。LTS |
| Admin | laravel/vue-starter-kit（公式） | 仕様指定。Fortify で auth / password reset / 2FA / rate limiting が標準実装される（独自認証禁止の要件に合致） |
| 公開側 | Blade 中心 + 必要箇所のみ Vue | 仕様指定（Preserve First / SEO / Lighthouse） |
| 管理 UI | Inertia 3 + Vue 3 + TS + Tailwind + shadcn-vue | Starter Kit 同梱 |
| ローカルメール | Mailpit | 仕様指定 |
| Bot 対策 | Cloudflare Turnstile（siteverify 必須） | 仕様指定。dev/test は公式テストキー |
| CLI | Node.js 20+ / TypeScript | npx create-agent-cms 配布・Mac / Windows(WSL2) クロスプラットフォーム要件。Bash 依存禁止要件 |
| テスト | Pest / Playwright / Lighthouse CI | 仕様 QUALITY GATE |
| 静的解析 | Larastan / vue-tsc / ESLint / Pint | 仕様 QUALITY GATE |

## Consequences

- Docker イメージと CI は PHP 8.5 系に固定する（>=8.3 なので Starter Kit 要件を満たす）
- Starter Kit はタグ付きリリースではなく dev-main 配布のため、生成後にプロジェクト側で composer.lock を固定し再現性を担保する
