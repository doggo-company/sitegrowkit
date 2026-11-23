# Architecture

リポジトリ構成の決定経緯は [ADR-0003](adr/0003-repository-architecture.md)、技術選定は [ADR-0001](adr/0001-technology-baseline.md)。

## Repository Layout

```
agent-cms-kit/            # スターターキット本体（このリポジトリ）
├── .agentcms/            # AI 向け SSoT + metrics（人間向け長文は置かない）
├── packages/cli/         # create-agent-cms CLI（Node/TS, Mac/Windows WSL2）
├── template/             # 生成される CMS プロジェクトの原本
├── examples/html-template/  # template inspect 検証用静的サイト
└── docs/                 # 人間向けドキュメント・ADR・ベンチマーク
```

「キット本体」と「生成される CMS プロジェクト（template/）」は分離されており、CLI の `create` は template/ から新案件を生成する。

## CMS Project Layout (template/)

```
template/
├── core/                 # CMS Core — agentcms/core (composer path package)
│   ├── src/              #   Schema registry, schema-driven admin 基盤,
│   │                     #   Contact pipeline, Extension Points, upload 検証
│   └── config/
├── app/                  # Project 層（薄い glue: Controller, Provider）
├── modules/              # 案件固有モジュール（コンテンツタイプ・公開テンプレート）
│   └── <Name>/           #   PSR-4: Modules\<Name>\。Models / database / views / tests を同居
├── content/schemas/      # Content Type Schema 定義（JSON）
├── resources/views/      # 公開サイト Blade（Tailwind 非依存）
├── resources/js/         # 管理画面 Inertia + Vue + TS
├── docker-compose.yml    # PHP / MySQL / Mailpit（ホストへ直接インストールしない）
└── .agentcms/            # 生成プロジェクト用 AI SSoT
```

## Core / Project Boundary（最重要）

| 層 | 場所 | 変更してよい者 |
| --- | --- | --- |
| CMS Core | `template/core/` | キット開発（このリポジトリ）のみ。生成プロジェクト内の AI・人間は編集禁止 |
| Project | `app/`, `modules/`, `content/schemas/`, `resources/` | 案件開発の AI・人間 |

案件固有の拡張は必ず以下を使う。Core の直接改造は Core Update を阻害するため禁止。

- **Content Schema**（`content/schemas/*.json`）— コンテンツタイプの追加・変更の唯一の入口
- **Modules**（`modules/<name>/`）— コンテンツタイプの実装・公開側テンプレート
- **Extension Points** — Core が公開する interface / event / config（`core/src/Contracts/`）

この境界は `.agentcms/rules/boundaries.md` として AI ルールにも同内容が定義される（そちらが AI 向け正本）。

## Schema First

管理画面定義の中心は Content Schema（`content/schemas/*.json`）。

```
Schema (JSON) ──▶ Admin UI（schema-driven form / list）
            ├──▶ Validation rules
            ├──▶ Model casts / fillable
            └──▶ （将来）Migration / API / MCP / Tests 生成
```

Phase 1 では完全自動コード生成は行わないが、Admin の form / list / validation は Schema から動的に構成される。

## Public Site Rendering

- Blade 中心の SSR。公開側 CSS はプレーン CSS（Tailwind 固定禁止）
- インタラクションが必要な箇所（Contact form の Turnstile 等）だけ最小 JS
- 理由: 既存デザイン尊重（Preserve First）/ SEO / Lighthouse / JS 量削減

## Token Efficiency Architecture

- **Locality of Context** — 各 Module は schema / admin / public / validation / tests を自分の境界内に近接配置する
- **.agentcms/context/** — project map / modules / commands / content types / component registry を簡潔にキャッシュし、AI はリポジトリ全探索の前にここを読む
- **Manifest First** — HTML テンプレート取り込みは生ファイル群を読む前に `template inspect` の Manifest を読む
- 巨大な自動生成ドキュメントは禁止。目的は「AI が読む必要のある量を減らす」こと

## Component Registry（Figma / Visual Editor Future Proofing）

公開 HTML から作ったコンポーネントにも安定 ID と metadata を持たせる。
`.agentcms/registry/components.json` に `id / name / kind (blade|vue) / path / props / origin` を登録し、将来 Figma Component ↔ Registry ↔ Blade/Vue の対応関係に使う。

## Multi-Agent Design

`.agentcms/` が唯一の正本。`CLAUDE.md` / `AGENTS.md` は `packages/cli` の `agentcms sync-agents` で生成される入口ファイルであり、直接編集禁止（ヘッダーに明記）。将来 Cursor / Codex / Grok 用設定も同じ仕組みで生成する。

## Future Proofing（API / MCP / Visual Editor）

- Content Schema / Component Registry / Page 構造を安定した機械可読データとして持つ（上記）
- 公開 API は Phase 1 では作らないが、Core の Service 層（Schema registry, Content query）を経由してのみデータへアクセスする構造にし、将来 REST / MCP を Core の上に足せるようにする
