# Getting Started — 最初のサイトを 30 分で

このガイドは SiteGrowKit を初めて使う人のためのものです。
アーキテクチャの説明は後回しにして、まず動かします。

## 1. 何が必要か（prerequisites）

| ツール | 必須 | 確認コマンド | 導入 |
| --- | --- | --- | --- |
| Docker Desktop | ✅ | `docker --version` | https://www.docker.com/products/docker-desktop/ |
| Node.js 20+ | ✅ | `node --version` | https://nodejs.org/（LTS 推奨。`agentcms` コマンドの実行に使います） |
| Git | ❌ 不要 | `git --version` | macOS: `xcode-select --install`。バージョン管理を使う場合だけ導入してください（セットアップにも `agentcms verify` にも要りません。無ければ `agentcms create` が `git init` を飛ばすだけです） |
| PHP / Composer / MySQL | ❌ 不要 | — | すべて Docker コンテナ内で動きます |
| pnpm | ❌ 不要 | — | フロントエンドの依存インストールと build も Docker コンテナ内で実行します（project は pnpm 10 系に pin 済み） |

Windows 11 の場合は **WSL2 内で実行**します。先に [WINDOWS_WSL2.md](WINDOWS_WSL2.md) を読んでください。

## 2. Kit の入手とインストール

配布用の zip（バンドル）に入っている `.tgz` からインストールします。zip を展開してできた `agent-cms-kit-v<version>` フォルダの**中に入ってから**実行してください。

```bash
cd agent-cms-kit-v<version>

npm install -g ./agent-cms-kit-<version>.tgz
agentcms --version
```

> ファイル名の `v` の有無に注意してください。**zip とフォルダには `v` が付き**（`agent-cms-kit-v0.9.3`）、**`.tgz` には付きません**（`agent-cms-kit-0.9.3.tgz`）。

## 3. 環境診断

```bash
agentcms doctor
```

- `✔` = 問題なし / `−` = NOT REQUIRED（あなたの構成では不要） / `▲` = 注意 / `✖` = 要修正
- `✖` には必ず「原因」と「修正」が表示されます。修正して再実行してください。doctor は診断のみを行い、システムを変更しません。

## 4. プロジェクト作成

```bash
agentcms create my-site
cd my-site
```

`create` はこの時点で以下まで済ませます（ネットワーク不要）:

- テンプレート一式のコピーと `.env` の生成
- **このプロジェクト専用の管理者パスワードを自動生成**（`.agentcms/local/admin-credentials.json`、Git 対象外）
- Docker の project identity（他プロジェクトとコンテナ・DB volume が衝突しない固有名）
- ポートの空き確認（使用中なら空きポートを選んで `.env` に記録）
- AI エージェント向けコンテキスト（`.agentcms/` + CLAUDE.md / AGENTS.md）
- `git init`（既存の Git 設定は壊しません）

## 5. 起動

```bash
agentcms dev
```

初回は Docker イメージの取得・build で数分かかります。`dev` は依存インストール（composer / pnpm）、APP_KEY 生成、DB migrate / seed、フロントエンド build、そして **公開サイトとログインページの health check** まで自動で行います。health check が通るまで成功とは表示されません。

成功時の表示例:

```
✔ 起動しました（health check 済み）
• 公開サイト:        http://localhost:8180/
• 管理画面ログイン:   http://localhost:8180/login
•   認証情報: .agentcms/local/admin-credentials.json を参照（Git 対象外）
• Mailpit UI:        http://localhost:8026
```

## 6. 健康確認

1. 公開サイト URL をブラウザで開く → トップページが表示される
2. 管理画面ログイン URL を開き、`admin-credentials.json` の email / password でログイン → 管理画面が表示される
3. お問い合わせテストメールは Mailpit UI で受信確認できます（実メールは送信されません）

## 7. AI エージェントをつなぐ

プロジェクトには AI 向けコンテキストが生成済みです。使い方と最初のプロンプト例は [AI_AGENTS.md](AI_AGENTS.md) へ。

## 8. 最初の安全な変更

例: 公開サイトの文言変更は `resources/views/`（Blade）にあります。変更したら:

```bash
agentcms verify
```

品質ゲート（format / 静的解析 / テスト / build / secret scan / audit）が一括で走ります。**`core/` は編集禁止**です（詳細: [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)）。

## 9. 停止・再開

```bash
agentcms stop   # 停止（DB データは volume に保持）
agentcms dev    # 再開（再実行は安全・冪等です）
```

## 10. うまくいかないとき

[TROUBLESHOOTING.md](TROUBLESHOOTING.md) — 症状から引けます。`agentcms doctor` の再実行も有効です。

## 公開する（サーバーに出す）

最初の 6 歩と公開前チェックを済ませたら、[XServer の手順書](deployment/xserver.md) を開きます。用意するものと、まずステージングで確かめる手順があります。本番は手順書の §12 を使ってください。
