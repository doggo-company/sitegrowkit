# AI エージェントと開発する

SiteGrowKit は「AI がプロジェクトを毎回探索し直さなくてよい」ことを前提に設計されています。
生成されたプロジェクトには、AI エージェント向けコンテキストが最初から含まれています。

## コンテキストの仕組み（単一情報源）

```
.agentcms/            ← ルール・アーキテクチャ・コマンドの単一情報源（SSoT）
   ↓ agentcms sync-agents（決定論的生成）
CLAUDE.md / AGENTS.md ← 各エージェントが最初に読む入口ファイル
```

- **編集するのは `.agentcms/` だけ**です。CLAUDE.md / AGENTS.md は生成物なので手編集しないでください（`agentcms sync-agents` で再生成）
- `.agentcms/local/` は**例外**: 管理者パスワード等のローカル秘密情報で、AI コンテキストではありません。エージェントに読ませない・出力させないでください

## 各エージェントでの使い方

プロジェクトのフォルダで、次の 1 行を打って起動します。

| エージェント | 起動コマンド | MCP 設定の自動生成 |
| --- | --- | --- |
| **Claude Code** | `claude` | 対応（`agentcms setup`） |
| **Codex** | `codex` | 対応（`agentcms setup`） |
| **Gemini CLI** | `gemini` | 手動 |
| **opencode** | `opencode` | 手動 |
| **Grok Build** | `grok` | 手動（下記） |
| **Cursor**（CLI ではありません） | — | 対応（`agentcms setup`） |

- **Claude Code** はプロジェクトルートで起動するだけで `CLAUDE.md` を自動で読みます。**`AGENTS.md` 対応ツール**（Codex 系）は同梱の `AGENTS.md` を規約どおり参照します
- **Cursor** はリポジトリ内ファイルとして `.agentcms/` / `AGENTS.md` を参照できます（専用の自動読み込み機能を保証するものではありません。ルールとして登録する場合も本文は `.agentcms/` を指すだけにしてください）
- **Grok Build** は MCP にネイティブ対応しているので、`agentcms setup` が Claude Code 用に書き出した MCP 設定が**そのまま使えます**。「自動生成の対象ではない」ことと「つながらない」ことは別です
  （2026-08-20 時点で x.ai / docs.x.ai の記載を確認。提供元の仕様は変わることがあります）
- ここに無いエージェントでも、**MCP に対応していれば同じようにつなげます**

どのエージェントでも原則は同じです: **入口ファイル → `.agentcms/` の順で読ませる。ルールの複製を作らない。**

> 管理画面の「はじめに → AI でカスタマイズする」は、この表と下の「最初のプロンプト」を**そのまま見せているだけ**です。
> 正本はこのファイルで、ずれた場合は kit の検査が赤くなります。

## 最初のプロンプト（推奨）

プロジェクト作成直後、最初のタスクを頼むときの例:

```
.agentcms/ のルールに従って作業してください。
まず .agentcms/architecture.md と rules/boundaries.md を読み、
変更前に対象を調査し、core/ は編集せず、
作業後は `agentcms verify` で検証してください。
タスク: <あなたの依頼内容>
```

短く保つのが重要です。詳細ルールはリポジトリ側（`.agentcms/`）が持っているので、プロンプトに長い説明を書く必要はありません。

## エージェントが従うべき境界（人間も知っておく）

- `core/` は**読み取り専用**。拡張は公開されている拡張点（`core/src/Contracts/`・`core/config/`）経由のみ
- サイト固有の実装は `app/` / `modules/` / `content/schemas/` / `resources/`
- コンテンツ型はスキーマ駆動: `content/schemas/*.json` が唯一の定義場所
- 完了条件は「AI ができたと言った」ではなく `agentcms verify` の全ゲート通過
- 秘密情報（`.env`、`.agentcms/local/`）をコード・ログ・コミットへ出さない

## コンテキストが壊れた・古くなったとき

```bash
agentcms sync-agents
```

いつでも安全に再生成できます（冪等）。
