# 公開後の定期保守

管理画面の「公開・保守チェック」で、PHP依存ライブラリとHTTPS証明書の最終確認日時を見られます。コードを自動更新する機能ではありません。問題が見つかったら、修正を検証して新しい版を配布します。

## 最初の設定

サーバー上のアプリディレクトリで、Webサーバーと同じ利用者として次を実行してください。Nodeや有料プラグインは不要です。PHP CLI、Composer、OpenSSLと外向きのネットワーク接続が必要です。

```sh
php artisan agentcms:maintenance
```

ローカルのDocker環境では、生成プロジェクトのディレクトリから実行できます。

```sh
docker compose exec app php artisan agentcms:maintenance
```

公開環境のCronに、毎分 `php artisan schedule:run` を設定してください。XServerのデプロイ設定が既にこのCronを作成している場合、重複して追加しないでください。Laravelのスケジューラが、production/staging環境で毎日03:20（アプリのタイムゾーン）に保守確認を行います。ローカル環境では手動確認のみです。

`APP_URL` には実際のHTTPS公開URLを設定してください。HTTPのローカルURLでは証明書は「未確認」になります。サーバー側でComposerが使えないときも「未確認」となります。確認を完了できていない結果を合格として扱いません。

## 確認する内容

- **PHP依存ライブラリ**：実際にインストールされているvendorを `composer --no-plugins --no-scripts audit --no-dev --format=json --no-interaction` で確認します。lockファイルだけを監査すると、古いvendorを見逃すため、稼働中の依存を使います。無視設定に入っている脆弱性も検出された場合は要対応にします。
- **HTTPS証明書**：`APP_URL` のホストへTLS接続し、証明書とホスト名の検証に通った場合だけ期限を表示します。残り30日未満は注意、確認できない通信や証明書は未確認です。
- **結果の鮮度**：48時間を超えた記録、公開URLや依存ファイルの変更後の記録は「再確認が必要」と表示します。確認中に構成が変わった場合も新しい構成の結果として保存しません。

結果は `storage/app/private/maintenance/report.json` に保存し、公開ディレクトリへ置きません。表示できるのはサイト設定の管理権限を持つ利用者だけです。画面を開くたびに外部通信することはありません。複数の確認が重なった場合は一方を止めます。

終了コード0は今回の全確認が通った場合、1は要対応・注意・未確認・保存失敗のいずれかがある場合です。定期実行が止まっても48時間後に古い結果の表示へ変わるので、最新の合格と取り違えません。

## 毎日のバックアップと別の置き場所

バックアップ（DB とメディア）は、既定では人が実行したときだけ取得し、このサーバーの `storage/app/private/backups` にだけ置きます。サーバーが壊れると、バックアップも一緒に失われます。公開環境では、次の 2 つを設定してください。

```sh
# shared/.env（または .env）
AGENTCMS_BACKUP_SCHEDULE=03:40          # 毎日この時刻（アプリのタイムゾーン）に取得する。空なら自動では取らない
AGENTCMS_BACKUP_OFFSITE_DISK=s3         # 写しを置く場所（config/filesystems.php の disk 名）
AGENTCMS_BACKUP_OFFSITE_PREFIX=example-com   # 1 つの置き場所を複数サイトで使うときの、このサイトの場所
AGENTCMS_BACKUP_OFFSITE_KEEP=14         # 置き場所に残す世代（手元は AGENTCMS_BACKUP_KEEP の 5 世代のまま）
```

- 自動の取得は、毎分の `php artisan schedule:run`（上の Cron）が動かします。Cron が無いと時刻を入れても取得しません
- 置き場所は 2 種類から選べます
  - **S3 互換**（Cloudflare R2・Backblaze B2・MinIO など）：`config/filesystems.php` の `s3` disk に `AWS_ACCESS_KEY_ID`・`AWS_SECRET_ACCESS_KEY`・`AWS_DEFAULT_REGION`・`AWS_BUCKET`・`AWS_ENDPOINT`（必要なら `AWS_USE_PATH_STYLE_ENDPOINT=true`）を設定し、プロジェクトで `composer require league/flysystem-aws-s3-v3` を実行します。キットにはこのパッケージを同梱していません
  - **別のディレクトリ**（別のディスク・ネットワークのマウント）：`AGENTCMS_BACKUP_OFFSITE_DISK=backup-offsite` と `AGENTCMS_BACKUP_OFFSITE_ROOT=<絶対パス>`。手元のバックアップと同じ場所の中を指すと送信を止めます
- 送った後に大きさを読み直し、`<id>.zip.sha256` を横に置きます。送れなかったときは `agentcms:backup-auto` が終了コード 1 で終わり、手元のバックアップは残します
- 世代の整理は、このサイトの prefix の中だけで行います

```sh
php artisan agentcms:backup-auto                 # 取得 → 写す → 古い世代の整理（自動実行と同じ処理）
php artisan agentcms:backup-offsite --list       # 設定と、置き場所にある世代。未設定・読めない時刻・読めない置き場所は終了コード 1
php artisan agentcms:backup-offsite [<id>]       # 手元のバックアップを写す（省略時はいちばん新しいもの）
```

### 置き場所から戻す

サーバーを作り直したときなど、手元にバックアップが無くても 1 コマンドで戻せます。

```sh
php artisan agentcms:backup-restore --from-offsite --latest
```

置き場所から手元へ取り込み（checksum が一致したときだけ）、管理画面の復元と同じ検証（中身の一致・DB の形の一致）を通してから戻します。戻す直前の状態は `pre-restore-…` として残り、全員のログインが切れます。確認を省くのは `--force` を付けたときだけです。`id` を指定すると、その世代を戻します。

バックアップには `.env` が含まれません。`APP_KEY` などの設定は、別に保管してください。

## 対象外と更新時の手順

この確認でアプリ全体の安全性を証明するものではありません。JavaScript依存は配布前の `agentcms verify` のpnpm auditで検査します。業務コード、権限、フォーム、バックアップ復旧、ブラウザ操作の検証も別に必要です。

脆弱性が報告されたら開発環境で修正版へ更新し、`agentcms verify` と影響する実操作を通します。現在のサイトのバックアップを取り、デプロイ計画を確認して配布します。更新後もこの保守確認を実行してください。CMS画面から本番のコードを自己書き換えする操作は提供していません。
