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
AGENTCMS_BACKUP_ALERT_MAIL=ops@example.com   # 失敗したら知らせる宛先（空なら送らない）
```

- 自動の取得は、毎分の `php artisan schedule:run`（上の Cron）が動かします。Cron が無いと時刻を入れても取得しません
- 置き場所は 2 種類から選べます
  - **S3 互換**（Cloudflare R2・Backblaze B2・MinIO など）：`config/filesystems.php` の `s3` disk に `AWS_ACCESS_KEY_ID`・`AWS_SECRET_ACCESS_KEY`・`AWS_DEFAULT_REGION`・`AWS_BUCKET`・`AWS_ENDPOINT`（必要なら `AWS_USE_PATH_STYLE_ENDPOINT=true`）を設定し、プロジェクトで `composer require league/flysystem-aws-s3-v3` を実行します。キットにはこのパッケージを同梱していません
  - **別のディレクトリ**（別のディスク・ネットワークのマウント）：`AGENTCMS_BACKUP_OFFSITE_DISK=backup-offsite` と `AGENTCMS_BACKUP_OFFSITE_ROOT=<絶対パス>`。手元のバックアップと同じ場所の中、または公開ディレクトリの中を指すと送信を止めます
- 送った後に大きさを読み直し、`<id>.zip.sha256` を横に置きます。送れなかったときは `agentcms:backup-auto` が終了コード 1 で終わります。手元のバックアップは残します
- 世代の整理は、このサイトの prefix の中だけで行います

### 失敗に気づく

Cron が動かす `php artisan schedule:run` は、中の `agentcms:backup-auto` が失敗しても **終了コード 0 で終わり、画面にも何も出しません**。そのため失敗は次の場所に残します。

- **管理画面**：サイドバーの「バックアップ」に赤い印が付き、バックアップ画面の上に理由が出ます（バックアップ画面を開ける Owner だけ）
- **状態の表示**：`php artisan agentcms:backup-offsite --list` が理由を出して終了コード 1 で終わります。Cron や外の見張りから叩けます
- **メール**：`AGENTCMS_BACKUP_ALERT_MAIL=ops@example.com`（カンマ区切りで複数）を設定したときだけ、サイトのメール設定（`MAIL_*`）で知らせます。空なら送りません。メールが送れなくても、上の 2 つは変わりません
- **ログ**：アプリのログ（error）にも 1 行残します

「失敗」に数えるのは、バックアップを作れなかった・置き場所へ送れなかった・始めた記録だけが残り 4 時間たっても終わっていない・時刻を決めているのに最後の成功から 30 時間を超えた（Cron が止まった）・記録が読めない、のどれかです。次に成功すると印は消えます。記録は `storage/app/private/backup-status/auto.json` に置き、バックアップの中には入れません。理由の文は、置き場所の disk に書かれた鍵（`key`・`secret` など）と署名の欄を伏せてから残します。

**Cron そのものが止まった・サーバーごと落ちた**ときは、サーバーの中からは知らせを出せません（メールも送れません）。管理画面と `--list` は次に開いたときに「止まっています」と出しますが、確実に気づくには、サーバーの外から置き場所の最新のバックアップの日時（または `--list` の終了コード）を見張ってください。

```sh
php artisan agentcms:backup-auto                 # 取得 → 写す → 古い世代の整理（自動実行と同じ処理）
php artisan agentcms:backup-offsite --list       # 設定・最後の自動実行の結果・置き場所にある世代。未設定・読めない時刻・読めない置き場所・最後の自動実行の失敗は終了コード 1
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
