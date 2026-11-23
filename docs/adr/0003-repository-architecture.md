# ADR-0003: リポジトリ構成と Core / Project 分離の実現方式

- Status: Accepted
- Date: 2026-08-08

## Context

仕様は「スターターキット本体」と「生成される CMS プロジェクト」の分離（packages/cli, template/, agent-spec/, providers/, tests/, docs/ の例示）、および CMS Core と案件固有コードの分離を要求している。将来 `npx create-agent-cms` で新規案件を生成する。

## Decision

```
agent-cms-kit/
├── .agentcms/          # AI 向け SSoT（キット本体用）+ metrics
├── packages/cli/       # Node/TS クロスプラットフォーム CLI（npx create-agent-cms）
├── template/           # 生成される CMS プロジェクトの原本（Laravel 13 アプリ全体）
│   ├── core/           # CMS Core（agentcms/core composer path package）
│   ├── app/ modules/   # 案件固有コード（Project 層）
│   └── .agentcms/      # 生成プロジェクト用 SSoT（キットが生成・同期）
├── examples/
│   └── html-template/  # template inspect 検証用の静的 HTML サイト
└── docs/               # PRODUCT / ARCHITECTURE / ROADMAP / SECURITY / BENCHMARK / adr / benchmarks
```

仕様例示の `agent-spec/` は `.agentcms/` に、`providers/` は Phase 1 スコープ外（メール/デプロイ等のプロバイダ抽象は ROADMAP へ）として採用しない。理由: 同じ情報の置き場所を 2 つ作らない（SSoT 原則）、Phase 1 で使わない空ディレクトリを作らない。

### Core / Project 分離の実現方式

- Core は `template/core/`（composer path repository, パッケージ名 `agentcms/core`）。Schema 読み込み・schema-driven admin・Contact パイプライン・Extension Point を提供
- 案件固有は `app/`（薄い glue）と `modules/`（コンテンツタイプ実装・公開側テンプレート）
- AI ルール上、`core/` は生成プロジェクト内で編集禁止（Core Update を阻害しないため）
- Phase 1 では core を同一リポジトリ内 path package とし、将来 Packagist 配布へ移行できる形にする（namespace / service provider / config publish を最初から package 構造にする）

## Consequences

- `create-agent-cms` は template/ のコピー + プレースホルダ置換 + .env 生成で新案件を作れる（ネットワーク上の core 取得が不要）
- core の変更はキットリポジトリでのみ行い、生成プロジェクトへは将来の update コマンドで配布する
