# ADR-0008: XServer Staging Deployment（Provider Adapter / Build / Release 方式）

- Status: Accepted
- Date: 2026-08-08

## Context

Phase 4 で共用レンタルサーバー XServer の Staging 環境へ、安全・再現可能・検証可能・rollback 可能な方法でデプロイできるようにする。XServer の公式仕様（2026-08 時点、出典は docs/deployment/xserver.md）:

- SSH: ポート 10022・公開鍵認証のみ（全プラン）
- PHP CLI: `/usr/bin/php8.5|8.4|8.3` のバージョン付きパスが公式。素の `php` は旧版が起動する
- Node.js: 非サポートを公式が明言（glibc が古く v18+ 不可）
- Composer: プリインストールの公式保証なし
- docroot: `/home/<ID>/<ドメイン>/public_html` 固定・変更不可
- Cron: サーバーパネルからの登録が公式手段
- MySQL: サーバーパネルで DB 作成、現行サーバーは `localhost` 接続
- symlink: SSH で `ln -s` は動作するが、`public_html` 自体を symlink へ置換する運用の公式見解はない

## Decision

### 1. Provider Adapter 境界

XServer 固有処理は `packages/cli/src/deploy/providers/xserver.ts` に閉じ込め、`deploy/`（types / config / executor / artifact / build / plan / release / health）は Provider 非依存とする。リモート操作は `RemoteExecutor` インターフェイス（実装: SshExecutor / テスト: FakeExecutor）越しのみ。将来の Lolipop / Sakura / generic SSH は providers/ に追加する（Phase 4 は XServer のみ）。ADR-0003 で見送った `providers/` 境界をここで導入する。

### 2. Build Strategy: ローカルビルド + vendor 同梱 artifact

> 2026-08-08 追記（実デプロイでの発見）: composer.lock は**最低対応 PHP（8.3）で解決**すること。`composer.json` の `config.platform.php=8.3.0` がこれを強制する。開発環境（Docker PHP 8.5）のまま解決した lock は Symfony 8.1（PHP >= 8.4.1 要求）を含み、XServer の Web PHP 8.3 で全リクエスト 500 になった。修正で Symfony は 7.4 LTS 系へ。全品質ゲート・E2E・Lighthouse で回帰なしを確認済み。rules/coding.md Dependencies 参照。

- vite build と `composer install --no-dev --optimize-autoloader` を**ローカル（Docker の PHP 8.5 = 実行系と同条件）**で実行し、`vendor/` と `public/build` を含む自己完結 tar.gz を転送する。
- 理由: XServer は Node 非サポート（サーバービルド不可能）、Composer 未保証（server 側 install は再現性がない）。artifact 側に寄せる方が「どのサーバーでも同じ物が動く」。
- 含めない: `.env*` / storage / node_modules / tests / docs / *.sqlite / 鍵ファイル（vendor 内 CA bundle の .pem は除外しない — HTTPS クライアントが壊れる）。allow-list は `deploy/artifact.ts` の `shouldInclude()` が単一情報源で、ユニットテストが secret 除外を保証する。

### 3. Release Strategy: releases/ + shared/ + current symlink、docroot は entry_stub

```
<appDir>/                       … docroot の外（Web 非公開）
  releases/<YYYYMMDDHHMMSS>/    … 展開済み artifact
  shared/.env                   … 秘密情報はサーバー上のこのファイルのみ
  shared/storage/               … release 間で共有
  backups/<id>-db.sql.gz        … デプロイ毎の DB バックアップ
  current -> releases/<id>
<docroot>/public_html/          … 実ディレクトリのまま
  index.php                     … current を参照する entry stub（usePublicPath(__DIR__)）
  build/ ほか公開アセット        … current/public から同期（index.php は除外して上書き）
  storage -> shared/storage/app/public
```

- `current` symlink 切替で release を原子的に交換し、rollback は current の付け替え + docroot 再同期のみ。
- **docroot 自体の symlink 化（public_html → current/public）は既定で行わない**。公式見解がなくサーバーパネル操作で破壊されるリスクがあるため。entry_stub は public_html が実ディレクトリという XServer の前提のまま成立する。
- 公開アセット同期は `tar | tar` で index.php を除外して行い、スタブを最後に配置（壊れた index.php が見える時間を作らない）。旧アセット（content hash 付き）は消さず残す — 切り戻し時に旧 release がそのまま動く。
- **entry stub の realpath 化（2026-08-09 追記・実機で発見）**: stub は `current` symlink を `realpath()` で実 release パスへ解決してから boot する。symlink パスのまま起動すると全 release が同一絶対パスを共有し、**OPcache / コンパイル済みビューのキーが衝突して新 release のコードが配信されない**（共用サーバーは FPM 再起動不可・`view:clear` でも解消しない）。release 方式の共用ホスティングでは必須の手法（Envoyer 等と同様）。
- **DirectoryIndex 競合（2026-08-08 追記・レビュー指摘による修正）**: XServer の既定ドキュメント優先順位は `index.html > index.htm > index.shtml > index.cgi > index.php` であり、docroot に index.html が残っていると Laravel は一切表示されない。deploy は 4 ファイルを検査し、XServer 初期生成ページ（実機確認済みマーカー `Copyright XSERVER Inc.`。付随 default_page.png も対象）のみ `appDir/backups/` へ**退避**（削除しない）、それ以外はユーザー作成の可能性があるため**自動削除せず停止**する。health check は `/` が Laravel から配信されていること（初期ページマーカー不在 + アプリ付与ヘッダー存在）を検証する。

### 4. 安全装置

- deploy 前に shared/.env の `APP_ENV=staging` / `APP_DEBUG=false` / `APP_KEY` 存在を検査し、違えば中止（値は読まない・表示しない）
- DB バックアップは mysqldump + gzip 整合性 + サイズ下限で検証し、失敗したら deploy 中止（ファイル存在だけで成功にしない）。認証情報は shared/.env から読み `MYSQL_PWD` 環境変数で渡す（引数・ログに出さない）
- migration 失敗時は current 未切替のまま停止（旧 release 稼働継続）。DB の自動復元はしない — バックアップパスを提示して人間判断に委ねる
- health check 失敗時は current を旧 release へ自動で切り戻す（初回デプロイは切り戻し先がない旨を明示）
- staging は `Mail::alwaysTo` で全メールを安全宛先へ強制、robots.txt Disallow + X-Robots-Tag noindex、HTTPS 強制
- Cron / DB 作成 / SSL 設定はサーバーパネルが公式手段のため**自動変更せず**、生成したコマンド・手順を提示する
- `config:cache` + `view:cache` のみ実行。`route:cache` は closure ルート（robots.txt）があるため使わない

### 5. Idempotency

release 展開は「rm -rf → mkdir → tar」の作り直し方式。同じ deploy の再実行は新しい releaseId を採番し、途中失敗の残骸（current が指さない release / tmp の tar）は次回成功時の cleanup が回収する。

## Consequences

- `agentcms deploy doctor|plan|staging|rollback` と `agentcms verify staging` が追加された（`verify` は引数なしでローカル品質ゲートのまま）
- デプロイ設定は `.agentcms/deploy/<target>.json`（gitignore 済み、example のみコミット）。秘密情報は設定ファイルにも持たない（SSH 鍵はパス参照、DB/SMTP はサーバー上の shared/.env のみ）
- 1 release あたり artifact 約 34MB × keepReleases(3) + backups がサーバー容量を使う（スタンダード 500GB に対して無視できる規模）
- Queue は Phase 1 方針どおり DB Queue + Cron（`schedule:run` 毎分、パネル登録）で常駐 worker を要求しない
