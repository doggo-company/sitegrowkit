# Troubleshooting — 症状から引く

まず `agentcms doctor` を実行してください。多くの問題は doctor が原因と修正手順を表示します。
ここでは症状ベースで対処をまとめます。

## インストール・CLI

### zip を展開できない（`unzip: command not found`）
- Ubuntu/WSL2: `sudo apt install unzip` を実行してから `unzip agent-cms-kit-v<version>.zip`
- 代替: `python3 -m zipfile -e agent-cms-kit-v<version>.zip .`（Python 標準機能）
- WSL2 では **Linux filesystem 側（~/ 配下）に展開**してください（/mnt/c 配下は性能・permission の問題があります）

### `agentcms: command not found`
- `npm install -g ./agent-cms-kit-<version>.tgz` を実行しましたか？
- `npm bin -g`（または `npm prefix -g`）のパスが PATH に含まれているか確認してください
- nvm 利用時は、インストールした Node バージョンと現在のバージョンが同じか確認（`nvm use`）

### `agentcms --version` がエラーになる / モジュールが見つからない
- グローバルインストールをやり直してください: `npm uninstall -g agent-cms-kit && npm install -g ./agent-cms-kit-<version>.tgz`

## Docker

### `Docker daemon に接続できません`
- Docker Desktop を起動してください
- Windows: Docker Desktop → Settings → Resources → WSL Integration を有効化

### `ポート XXXX が使用中です`
- 別プロセスを特定して停止: macOS は `lsof -i :XXXX` / WSL2・Linux は `ss -ltnp | grep :XXXX`
- または `.env` の `APP_PORT` / `DB_FORWARD_PORT` / `MAILPIT_*_PORT` を変更して `agentcms dev` を再実行
- 新規作成時は `agentcms create` が空きポートを自動選択します

### コンテナが起動しない / MySQL が ready にならない
- 初回は MySQL 初期化に数十秒かかります。`docker compose logs mysql` を確認し、少し待って `agentcms dev` を再実行してください

## 依存・ビルド

### `composer install に失敗しました`
- 一時的なネットワーク・展開エラーのことがあります。**もう一度 `agentcms dev` を実行**してください（冪等・安全です）
- 途中失敗で不完全な vendor/ が残っても、`agentcms dev` が自動で検出して vendor/ を作り直し、composer install を再実行します。手動で vendor/ を削除する必要はありません

### `vendor/autoload.php` が無い（`Failed opening required '.../vendor/autoload.php'`）
- **何が起きているか**: 前回の composer install が途中で失敗し、不完全な vendor/ が残っています
- **修正**: もう一度 `agentcms dev` を実行してください。不完全な vendor/（autoload.php 欠落）を自動で検出し、削除してから composer install をやり直します
- 高度な手動回復（通常は不要）: vendor/ が symlink 等の特殊構成で自動削除が安全のため停止した場合のみ、表示される案内に従ってプロジェクト直下の vendor/ を手動で削除し、`agentcms dev` を再実行してください

### `ERR_PNPM_IGNORED_BUILDS` / `Ignored build scripts`
- ネットワークの問題ではありません。pnpm の version が project の前提（`package.json` の `packageManager` = pnpm 10 系）と一致していないことが原因です
- 修正: `corepack enable pnpm` を実行し、project ディレクトリ内で `pnpm --version` が pin と一致することを確認 → `agentcms dev` を再実行
- build 承認は `pnpm-workspace.yaml` の `onlyBuiltDependencies` で確定済みです。`pnpm approve-builds` の手動実行は不要な設計です。エラーに未知のパッケージ名が表示された場合のみ、その名前を `onlyBuiltDependencies` に追加してください

### `pnpm install / pnpm build に失敗しました`（上記以外）
- ネットワークを確認して `agentcms dev` を再実行
- pnpm 未導入の場合: `corepack enable pnpm`

### 公開サイト / 管理画面（/login）が 500 エラー
- **`Permission denied` / `tempnam(): Permission denied` / `file_put_contents ... Permission denied` がログにある場合（WSL2 で典型）**: container の実行ユーザーと host のファイル所有者（UID/GID）が一致していません。`agentcms dev` を再実行してください（`.env` の `HOST_UID`/`HOST_GID` でイメージを再ビルドし、container 側の書き込み検査も行います）。**`chmod -R 777` や `sudo chown` での回避はしないでください**（所有権が壊れます）
- フロントエンド build（Vite manifest）不足の場合: `agentcms dev` が自動 build します。手動で壊した場合は `pnpm build`
- 詳細ログ: `storage/logs/laravel.log`

## 設定・認証

### 管理画面のログイン情報が分からない
- `.agentcms/local/admin-credentials.json` を開いてください（プロジェクトごとに自動生成・Git 対象外)
- パスワードを変えたい場合: `.env` の `ADMIN_PASSWORD` を変更 → `agentcms dev`（seed が更新します）

### `ADMIN_PASSWORD が設定されていません` と表示される
- `agentcms dev` を実行すると安全なランダム値が自動生成されます

### `.env` が無い / APP_KEY が無い
- `agentcms dev` が `.env.example` からの生成と `APP_KEY` 生成を自動で行います

## 検証（verify）

### 復元のあと公開サイトと管理画面が「整備中」（503）のまま

復元中にプロセスが強制終了すると、DBと画像の状態を確定するまでアクセスを止めます。プロジェクトのディレクトリで次を実行してください。Dockerを使うローカル環境ではMac・WindowsのWSLとも同じ手順です。

```sh
docker compose exec -T app php artisan agentcms:backup-recover --status
docker compose exec -T app php artisan agentcms:backup-recover
```

本番のSSH環境では、同じプロジェクトのディレクトリで `php artisan agentcms:backup-recover --status` → `php artisan agentcms:backup-recover` を実行します。DBの確定前なら元の状態へ戻し、確定後なら復元後の状態を維持して後始末します。中断後の再実行にも対応しています。

「手で確認が必要」と出た場合は、表示された理由を保守担当者へ伝えてください。復元前バックアップ・退避した画像・復旧記録は保全されます。`backups/.restore/journal.json` を削除して整備中表示だけを解除すると、DBと画像が不一致のまま公開されるため削除しないでください。正常に復旧したら公開ページと管理画面へ再ログインして確認します。

### 作成したサイトの表示性能を測りたい

サイトを起動した状態でプロジェクト内の `pnpm lighthouse` を実行します。`.env` の `APP_URL` とサイトマップから対象を選び、スマホ・PCをそれぞれ3回ずつ測定します。12ページを超える場合は先頭12ページの測定であることを表示します。全ページの合格を意味しません。

```sh
APP_URL=http://localhost:8181 LHCI_PATHS=/,/about,/contact pnpm lighthouse
```

`LHCI_PATHS` で対象を明示できます。404・ログイン画面への転送・別サイトのURLは成功として測定しません。結果は `.lighthouseci/mobile` と `.lighthouseci/desktop` に保存し、外部へアップロードしません。測定対象の確認だけなら `pnpm lighthouse --list` を使います。

### `Secret scan を実行できません（Git リポジトリではありません）`
- secret scan は Git 管理対象ファイルを走査します。`git init && git add -A` を実行してから再実行してください
- **秘密情報が検出されたわけではありません**。鍵のローテーションは不要です
- 通常 `agentcms create` が git init まで行います

### verify のゲートが失敗する
- 各ゲートの失敗表示に修正ヒントが付いています。テストを弱めて通すことは禁止です

## AI エージェント

### CLAUDE.md / AGENTS.md が無い・古い
- `agentcms sync-agents` を実行してください（`.agentcms/` から再生成されます。手編集は禁止）

## デプロイ（XServer）

### deploy 系の失敗
- `agentcms deploy doctor` を実行してください（SSH / PHP / 権限を診断します）
- 手順全体: [deployment/xserver.md](deployment/xserver.md)

## それでも解決しないとき

1. `agentcms doctor` の全文
2. 失敗したコマンドと出力
3. `storage/logs/laravel.log` の該当部分（秘密情報は伏せて）

を添えて、リポジトリの Issue で知らせてください（秘密の値は伏せてください）。セキュリティに関わるものは Issue に書かず、リポジトリ直下の SECURITY.md に書いた連絡先へ送ってください。
