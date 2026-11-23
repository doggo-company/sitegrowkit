# SiteGrowKit

**育てるCMS。** Site（サイトを）Grow（育てる）Kit（道具一式）。

Laravel で作った CMS のテンプレートと、環境の診断・プロジェクトの生成・起動・検証・サーバーへの反映までをまとめた `agentcms` CLI のセットです。
AI エージェント（Claude Code・Codex・Cursor など）と一緒にサイトを作り、育てていく前提で、ルールや構成の説明を `.agentcms/` に持っています。

> 旧名は Agent CMS Kit です。パッケージ名・CLI コマンド（`agentcms`）・`.agentcms/` ディレクトリは改名していません。

## WordPress を使っている方へ

SiteGrowKit は、WordPress に置き換わるものではなく、案件によって選べる**もう 1 つの選択肢**です。
管理画面には「投稿」「固定ページ」「メディア」「メニュー」「カスタムフィールド」など、WordPress で見慣れた名前を並べています。WordPress から書き出したファイル（WXR）から記事を取り込む手順は [docs/MIGRATE_FROM_WORDPRESS.md](docs/MIGRATE_FROM_WORDPRESS.md) にあります。

## 構成

- `agentcms` CLI（`packages/cli/`）— create / doctor / dev / stop / test / verify / sync-agents / template import / figma import / deploy
- プロジェクトのテンプレート（`template/`）— Laravel 13 / PHP 8.5（Docker）/ MySQL 8.4 / Inertia + Vue 3（管理画面）/ Blade + plain CSS（公開サイト）
- `.agentcms/` — AI エージェント向けのルール・構成の説明の置き場所（`agentcms sync-agents` が CLAUDE.md / AGENTS.md を作ります）

## 自分でホストする

### 1. 配布物（zip と .tgz）を作る

必要なもの: Node.js 20 以上・pnpm・Git

```bash
git clone <このリポジトリの URL> sitegrowkit
cd sitegrowkit
pnpm -C packages/cli install --frozen-lockfile
node scripts/package-release.mjs
```

`release/` に `agent-cms-kit-v<version>.zip`（と `.zip.sha256`）ができます。
`package-release.mjs` は、作業ツリーに commit していない変更があると止まります（配布物は commit した状態から作るため）。
同じ版で作り直すときは、前の回に `release/` へ出たもの（記録の `SHA256SUMS`・`release-manifest.json` も含む）を片づけてから流してください。残っていると「作業ツリーが汚れている」「既に在る」で止まります。

### 2. 手元で起動する

必要なもの: Docker Desktop（起動しておく）・Node.js 20 以上

zip を展開したフォルダの README_FIRST.md の手順で進めます。おおまかには次のとおりです。

```bash
cd agent-cms-kit-v<version>
npm install -g ./agent-cms-kit-<version>.tgz

agentcms doctor        # 環境の診断（直し方も表示します）
agentcms create my-site
cd my-site
agentcms dev           # Docker の起動から依存・build・DB・health check まで
```

最初のサイトの起動は [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md)、Windows は [docs/WINDOWS_WSL2.md](docs/WINDOWS_WSL2.md) を見てください。

### 3. サーバーに出す

共用サーバーの XServer へ出す手順は [docs/deployment/xserver.md](docs/deployment/xserver.md)、Docker の入った Linux の VPS へ出す手順は [docs/deployment/vps.md](docs/deployment/vps.md) です。

### 動作を確かめた環境

- macOS（Apple Silicon）+ Docker Desktop
- Windows 11 + WSL2 + Docker Desktop（確かめた範囲は [docs/WINDOWS_WSL2.md](docs/WINDOWS_WSL2.md)）
- Windows のネイティブ（PowerShell / cmd）には対応していません

## こちらで動かすホスティング

DOGGO Company株式会社がサーバーを用意して動かすホスティング（有料）は準備中です。

## ドキュメント

| 目的 | ドキュメント |
| --- | --- |
| 最初のサイトを起動する | [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) |
| 管理画面で記事・項目・URL を編集する | [docs/EDITOR_QUICKSTART.md](docs/EDITOR_QUICKSTART.md) |
| AI エージェントと開発する | [docs/AI_AGENTS.md](docs/AI_AGENTS.md) |
| プロジェクトの構造を知る | [docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md) |
| 困ったとき | [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) |
| アップデート | [docs/UPGRADING.md](docs/UPGRADING.md) |
| 設計の記録 | docs/adr/ の各ファイル |
| 変更履歴 | [CHANGELOG.md](CHANGELOG.md) |

## 不具合の報告・セキュリティ

- 不具合や要望は Issue へ。受け付け方はリポジトリ直下の `CONTRIBUTING.md` に書いています
- セキュリティの問題は Issue に書かず、リポジトリ直下の `SECURITY.md` の連絡先へ送ってください

## ライセンス

Copyright (C) 2026 DOGGO Company株式会社

GNU General Public License v3.0 or later で公開しています。本文は [LICENSE](LICENSE)、著作権の表示は [COPYRIGHT](COPYRIGHT) にあります。
同梱・取得される第三者のソフトウェアとアセットは、それぞれのライセンスに従います（[THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md)）。
