# SiteGrowKit — Product

## One-liner

「いつもの WordPress に加えて、とっておきのもう一つを。」

Elementor / Bricks / SWELL 等で Web サイト制作ができる人が、案件によって WordPress 以外も選べるようになるための、AI エージェント前提の Web 制作スターターキット。

## Core Value

**AI に毎回ゼロから考えさせず、コンテキスト消費・やり直し・設計のブレを減らし、Web 制作者が業務品質のサイトを完成させられること。**

- AI のトークンを、環境構築とやり直しで浪費しない
- Laravel / Docker / MySQL / CMS 設計を毎回ゼロから構築しない
- AI エージェントと一緒に拡張できる、安全で予測可能な土台

## Target User

- HTML / CSS / JavaScript や Figma を扱える Web 制作者
- WordPress を否定したいのではなく、選択肢を増やしたい人

## What Phase 1 Delivers

1 案件（コーポレートサイト: TOP / ABOUT / SERVICE / WORKS / CONTACT）をローカル環境で最初から最後まで成立させる Vertical Slice。

- CMS: News / Works / Contact submissions / Site settings
- 管理画面（Inertia + Vue、2FA まで含む標準認証）
- Cloudflare Turnstile 付き問い合わせフォーム（メール失敗でもデータを失わない）
- HTML テンプレート取り込みの基盤（決定論的 `template inspect` → Manifest）
- クロスプラットフォーム CLI（create / doctor / dev / stop / test / verify / template inspect）
- AI 向け SSoT（.agentcms/）と CLAUDE.md / AGENTS.md の生成同期

Phase 1 で作らないもの: 万能ページビルダー、完全自動 HTML 変換、MCP サーバー、Figma 連携、高度な Role/Permission。将来計画は [ROADMAP.md](ROADMAP.md)。

## Positioning Principles

- Preserve First — 既存 HTML/CSS/JS を尊重し、勝手に変換・改善しない
- Schema First — 管理画面定義の中心は Content Schema
- Token Efficiency — AI が読む必要のある量を減らす（[ARCHITECTURE.md](ARCHITECTURE.md) 参照）
- 機械的検証がすべての完了条件（[BENCHMARK.md](BENCHMARK.md) 参照）
