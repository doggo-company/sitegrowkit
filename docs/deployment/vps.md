# VPS（Docker）へ出す手順

Docker の入った Linux の VPS 1 台に、SiteGrowKit のサイトを出す手順。共用サーバー（XServer）へ出す手順は [xserver.md](xserver.md)。設計の理由は ADR-0021（kit の docs/adr/）。

> **確かめた範囲**：この道は、手元の Docker の中に立てた「VPS の代わり」（ssh で入れる Linux の中で Docker が動くもの）で、setup → deploy → 更新 → 切り戻し → 2 つ目のサイトまで通してある（`scripts/vps-e2e.mjs`）。**本物の VPS の会社のサーバーと、Let's Encrypt の本物の証明書では、まだ試していない。** 初めて出すときは staging から始めること。

## 何ができあがるか

```
                    インターネット（80 / 443）
                              │
 ┌──────────── VPS 1 台 ───────┼──────────────────────────────────────┐
 │  Caddy（HTTPS の証明書を Let's Encrypt から取り、自動で更新する）    │
 │    ├─ サイト A の行 → サイト A の PHP-FPM のコンテナ                │
 │    └─ サイト B の行 → サイト B の PHP-FPM のコンテナ                │
 │  MySQL 8.4（1 つ。サイトごとに DB と利用者を分ける・外へポートを出さない）│
 │  各サイトの scheduler のコンテナ（毎分 schedule:run・cron は使わない） │
 └─────────────────────────────────────────────────────────────────────┘
```

| 置き場所（サーバー） | 中身 |
| --- | --- |
| `/srv/agentcms/edge/` | Caddy と MySQL の compose・Caddyfile・`sites/<サイト名>.caddy`・`secrets/mysql-root` |
| `/srv/agentcms/sites/<サイト名>/` | `releases/<id>`・`shared/.env`・`shared/storage`・`backups/`・`current`・`compose.yml`・`releases.history`（公開の記録）・`run/php-fpm.sock`（PHP-FPM はここでだけ待つ） |

**秘密（DB パスワード・APP_KEY・MySQL の root パスワード）は `agentcms deploy setup` がサーバーの上で作り、サーバーのファイル（600）にだけ置く。** 手元・Git・設定ファイル・ログ・画面・コマンドの引数・`docker inspect` には出さない。ただし動いているプロセスの中には値がある（PHP は `.env` を読み、MySQL は root パスワードを読んで起動する）。サーバーの root と docker グループの利用者は、それを読める。

## 1. 用意するもの

- Linux の VPS（Ubuntu・Debian など、Docker Engine が公式に入るもの）。メモリは 2GB 以上を勧める。試験（アクセスの無い状態）では MySQL が約 500MB、Caddy が約 35MB、サイト 1 つの PHP-FPM が 40〜90MB を使った。何サイト置けるかは測っていない
- ドメイン（またはサブドメイン）。DNS の A レコードを VPS の IP へ向けられること
- 手元に SiteGrowKit のプロジェクト（`agentcms dev` で動いている状態。サーバーへ出す物は手元の Docker で作る）

## 2. サーバーの準備（一度だけ・root で）

VPS に root で入って、次の 4 つを済ませる。

1. **Docker Engine と compose プラグインを入れる**：Docker の公式手順（https://docs.docker.com/engine/install/ ）に沿う
2. **出す人の利用者を作り、docker グループに入れる**（例：`deploy`）
   ```bash
   adduser --disabled-password --gecos "" deploy
   usermod -aG docker deploy
   install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
   # 手元で作った公開鍵（例 ~/.ssh/vps_staging.pub の 1 行）を貼る。持ち主を deploy にし、600 にする
   echo 'ssh-ed25519 AAAA…（公開鍵の 1 行）' > /home/deploy/.ssh/authorized_keys
   chown deploy:deploy /home/deploy/.ssh/authorized_keys
   chmod 600 /home/deploy/.ssh/authorized_keys
   ```
   > docker グループに入った利用者は、実質 root と同じことができる。この利用者の鍵は大切に扱う。
3. **置き場所を作る**
   ```bash
   install -d -o deploy -g deploy /srv/agentcms
   ```
4. **ファイアウォール**：22（ssh）・80・443 を開ける。80 は証明書を取るのに使うので閉じない

手元から `ssh -i ~/.ssh/vps_staging deploy@<VPS の IP> docker version` が通れば準備はできている（このとき一度だけホスト鍵を受け入れる。`agentcms` は鍵を聞き返さない設定で ssh するため）。

## 3. DNS

出すドメインの A レコード（IPv6 があれば AAAA も）を VPS へ向ける。`agentcms deploy staging` の最後の確かめ（health check）は、そのドメインへ手元から HTTPS でつなぐので、**DNS が向いてから**出す。

## 4. デプロイ設定（手元）

```bash
cp .agentcms/deploy/vps.example.json .agentcms/deploy/staging.json
```

| 項目 | 書くこと |
| --- | --- |
| `provider` | `"vps"` |
| `target` | ファイル名と同じ（`staging.json` なら `"staging"`。本番は `production.json` に `"production"`） |
| `ssh` | VPS の IP（またはホスト名）・ポート・利用者（`deploy`）・秘密鍵の場所 |
| `app.url` | `https://ドメイン`（パスは付けない） |
| `vps.site` | サイト名。英小文字で始まる英小文字・数字・ハイフン 2〜28 文字（`_` は使えない）。**1 台の中で重ならない名前**（コンテナ・置き場所の名前になり、DB と DB の利用者は `sgk-<サイト名>`） |
| `vps.root` | 置き場所を変えるときだけ（既定 `/srv/agentcms`）。**1 台の中のサイトはみな同じ値にする**（Caddy と MySQL は 1 台に 1 つなので、違う値の設定は doctor と setup が止める） |
| `vps.tls` | 既定 `"auto"`（Let's Encrypt）。`"internal"` は Caddy の自前の認証局（試験用。ブラウザは警告を出す） |

このファイルは Git に入らない（`.gitignore` 済み）。**パスワードや鍵の値は書かない**（書くと読み込みで止まる）。`remote` は書かない（`vps.site` から決まる）。

## 5. サーバーの中身を作る：`agentcms deploy setup`

```bash
agentcms deploy setup -t staging
```

足りないものだけを作る（**作るだけ・何度流しても同じ・既にあるものは上書きしない**）。実行の前に、何をするかを並べて確認を取る。

1. edge の設定を置く（compose・Caddyfile・MySQL の root パスワードをサーバーで生成）
2. Caddy と MySQL を起動する
3. サイトの PHP のイメージを build する（初回は数分かかる）
4. このサイトの DB・利用者・`shared/.env` を作る。DB パスワードと APP_KEY はサーバーで生成し、表示しない

`shared/.env` は `APP_ENV`（= target）・`APP_DEBUG=false`・`APP_TIMEZONE=Asia/Tokyo`・DB の接続まで埋まった状態でできる。**メール・Turnstile・Web Push の値は、ssh で入って書き足す**（手元へは持ってこない）:

```bash
ssh -i ~/.ssh/vps_staging deploy@<VPS>
vi /srv/agentcms/sites/<サイト名>/shared/.env
```

本番（staging 以外の target）は `MAIL_MAILER=smtp` で作られる。メールの設定を埋めるまで、問い合わせの通知は送れない（送れなかったことは管理画面に残る）。

## 6. 出す

```bash
agentcms deploy doctor -t staging    # Docker・edge・PHP のイメージ・.env・ドメインの重なりを確かめる（何も変えない）
agentcms deploy plan -t staging      # 何が起きるかを見る（何も変えない・秘密なし）
agentcms deploy staging -t staging   # 出す
```

`deploy staging` の流れ：手元で artifact を作る（vite build と composer install --no-dev）→ doctor → **Caddy の行**（別の場所で `caddy validate` してから差し替える。同じドメインを別のサイトが使っていれば何も変えずに止まる）→ 送って展開 → **サイトのコンテナ**（PHP-FPM と scheduler）→ **DB バックアップ** → migration → キャッシュ → `current` の切り替え → health check。

- health check が落ちたら、**自動で前の release へ戻す**（DB は戻さない。戻すなら §8）
- 途中で落ちたら、`current` は切り替わっていないので前の版のまま動く。**同じコマンドをもう一度流してよい**
- 本番は `--confirm-target production` を付けないと押せない（打ち間違い防止・xserver と同じ）

初めて出したあと、管理者を作る。パスワードは `.env` にもコマンドの引数にも残さず、その場で打つ:

```bash
ssh -i ~/.ssh/vps_staging deploy@<VPS>
read -rs ADMIN_PASSWORD; export ADMIN_PASSWORD     # 管理者のパスワードを打つ（画面に出ない）
docker exec -e ADMIN_EMAIL=you@example.com -e ADMIN_PASSWORD -e APP_CONFIG_CACHE=/tmp/agentcms-seed-config.php \
  -w /srv/agentcms/sites/<サイト名>/current agentcms-<サイト名>-app php artisan db:seed --class=AdminUserSeeder --force
unset ADMIN_PASSWORD
```

`-e ADMIN_PASSWORD` のように値を書かないと、docker はいまのシェルの値を渡す（引数に値が載らない）。`APP_CONFIG_CACHE` は、deploy が作った設定のキャッシュを使わずに、この 1 回だけ渡した値を読ませるため。できたら `https://ドメイン/login` からログインする。

## 7. 更新と切り戻し

```bash
agentcms deploy staging -t staging    # 更新（毎回 DB バックアップ → 新しい release → 切り替え → health check）
agentcms deploy rollback -t staging   # health まで通った 1 つ前の release へ（DB は触らない）
```

health まで通った release には印が付き、公開した順に `releases.history` へ書かれる。rollback はこの記録で 1 つ前に出ていた release へ戻る（release の番号＝手元の時計の時刻の順ではない。migration などで途中で落ちた release には戻らない）。戻した先の health が通らなければ、元の release に戻して止まる。古い release は、印のあるものを `keepReleases`（既定 3）まで残し、途中で落ちたものは片付ける。同じサイトへの deploy と rollback は同時に 1 つしか動かない（2 つ目は「別の deploy / rollback が動いています」で止まる）。PHP は `current` の symlink を Caddy が実際のパスへ解いてから受け取るので、切り替えた直後から新しい（戻したら古い）コードが動く。

## 8. DB を戻す（手で）

deploy のたびに `backups/<release id>-db.sql.gz` を取っている（その release を出す**直前**の DB）。横の `backups/<release id>-db.release` には、そのとき出ていた release の番号が入っている。戻すのは人が判断する（自動ではやらない）。

次の中身をサーバーに `restore-db.sh` として保存し、`bash restore-db.sh <サイト名> <release id>` で流す（`<release id>` は `ls backups` で見る）。1 行ずつ貼り付けずに、ファイルにして流すこと（途中で失敗したらそこで止まるように書いてある）。

```bash
#!/bin/bash
# 使い方: bash restore-db.sh <サイト名> <release id>
set -euo pipefail
site="$1"; id="$2"
dir="/srv/agentcms/sites/$site"   # vps.root を変えたなら、ここも変える
cd "$dir"
src="backups/$id-db.sql.gz"
# 1. 何も変える前に確かめる（無い・壊れている なら、ここで止まる。アプリは動いたまま）
[ -f "$src" ] || { echo "バックアップがありません: $src"; exit 1; }
gzip -t "$src"
db=$(grep -E '^DB_DATABASE=' shared/.env | cut -d= -f2-)
user=$(grep -E '^DB_USERNAME=' shared/.env | cut -d= -f2-)
pw() { grep -E '^DB_PASSWORD=' shared/.env | cut -d= -f2-; }
# パスワードは標準入力の 1 行目で MySQL のコンテナへ渡す（コマンドの引数に載せない）
in_mysql() { docker exec -i agentcms-mysql sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec "$@"' sh "$@"; }
trap 'echo "途中で止まりました。アプリは止めたままです。backups/before-restore-* に戻す前の DB があります。直してからもう一度流すか、docker compose -f $dir/compose.yml start で起こしてください"' ERR
# 2. アプリと scheduler を止める（書き込みを止める）
docker compose -f compose.yml stop
# 3. いまの DB を取っておく（取れなければここで止まる。DB はまだ変えていない）
safe="backups/before-restore-$(date +%Y%m%d%H%M%S)-db.sql.gz"
pw | in_mysql mysqldump --single-transaction --no-tablespaces -h 127.0.0.1 -u "$user" "$db" | gzip > "$safe"
gzip -t "$safe"
[ "$(wc -c < "$safe")" -ge 200 ]
# 4. DB を空にしてから戻す（バックアップの後に増えた表も残らない）
{ pw; printf 'DROP DATABASE `%s`;\nCREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" "$db"; } | in_mysql mysql -h 127.0.0.1 -u "$user"
{ pw; gunzip -c "$src"; } | in_mysql mysql -h 127.0.0.1 -u "$user" "$db"
# 5. コードを、この DB を取ったときに出ていた release へ合わせてから起こす
if [ -f "backups/$id-db.release" ] && [ -d "releases/$(cat "backups/$id-db.release")" ]; then
  ln -sfn "$dir/releases/$(cat "backups/$id-db.release")" current
fi
docker compose -f compose.yml start
echo "戻しました: DB は $id の直前 / current は $(basename "$(readlink current)")"
```

- 戻したあと、手元で `agentcms verify staging`（本番なら health を見る）で確かめる
- コードを合わせる元の release が掃除で消えていたら、current はそのまま（最後の 1 行に出る番号を見て、必要なら `agentcms deploy rollback`）
- 戻す前の DB は `backups/before-restore-*.sql.gz` に残る。同じスクリプトで、その番号を指定すれば元に戻せる（`ls backups` に出る `before-restore-<時刻>-db.sql.gz` の `before-restore-<時刻>` を `<release id>` の代わりに渡す）

## 9. 毎日のバックアップ

scheduler のコンテナが毎分 `schedule:run` を流すので、`shared/.env` に `AGENTCMS_BACKUP_SCHEDULE=03:00` を入れれば毎日取る。別の置き場所へ写す設定は [MAINTENANCE.md](../MAINTENANCE.md) の「毎日のバックアップを別の置き場所へ」。`.env` を変えたら `agentcms deploy staging` を流し直す（設定のキャッシュを作り直すため）。

## 10. 2 つ目のサイトを足す

別のプロジェクト（または別の target）で、**別の `vps.site` と別のドメイン**の設定を作り、§5・§6 を流す。edge（Caddy・MySQL・PHP のイメージ）は共有し、サイトごとに分かれるのは次のもの:

- 置き場所（`/srv/agentcms/sites/<サイト名>/`）・コンテナ（`agentcms-<サイト名>-app` / `-scheduler`）
- DB と DB の利用者（自分の DB にしか権限がない）
- Caddy の行（`edge/sites/<サイト名>.caddy`）

**同じドメインや同じサイト名の設定で出そうとすると、何も変えずに止まる。** Caddy の行は、動いている Caddy の外で確かめてから差し替え、reload が落ちたら元の行に戻すので、ほかのサイトは止まらない。

## 11. サイトを外す（手で）

自動ではやらない（消したものは戻せない）。DB を残すかどうかを決めてから:

```bash
cd /srv/agentcms
docker compose -f sites/<サイト名>/compose.yml down
mv edge/sites/<サイト名>.caddy edge/sites/<サイト名>.caddy.removed
docker exec agentcms-caddy caddy reload --adapter caddyfile --config /etc/caddy/Caddyfile
# DB も消すなら（root のパスワードはコンテナの中のファイルから読む。引数に載せない）:
docker exec -it agentcms-mysql sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysql -uroot'
#   mysql> DROP DATABASE `sgk-<サイト名>`; DROP USER 'sgk-<サイト名>'@'%';
```

## 12. うまくいかないとき

| 症状 | 見るところ |
| --- | --- |
| doctor で Docker が FAIL | 出す利用者が docker グループに入っているか（入れたあと ssh を入り直す） |
| doctor で置き場所が FAIL | §2-3 の `install -d -o deploy -g deploy /srv/agentcms` |
| health check が「接続できませんでした」 | DNS が VPS を向いているか・80/443 が開いているか。`docker logs agentcms-caddy` に証明書の取得の失敗が出る |
| 500 が出る | `/srv/agentcms/sites/<サイト名>/shared/storage/logs/` と `docker logs agentcms-<サイト名>-app` |
| setup が「DB か利用者 … が既にある」で止まる | 同じサイト名の DB が、別の置き場所か前の setup の途中で作られている。自分のものなら §11 の手順で DB と利用者を消してから setup をもう一度。他人のものならサイト名を変える |
| deploy が「別の deploy / rollback が動いています」で止まる | 終わるのを待つ。止まったまま残っているなら、ほかに動いていないことを確かめてから `/srv/agentcms/sites/<サイト名>/.agentcms-deploy.lock` を消す |
| setup が途中で止まった | もう一度流す。DB を作ったあと `.env` を置く前に止まったときは、`shared/.env.pending`（そのとき作った値・600）を使って続きから作る |
| setup の PHP のイメージで止まる | 時間切れ（30 分）か、VPS から Docker Hub へつながらない。もう一度流せば続きから |

## 試験（kit を直す人向け）

```bash
node scripts/vps-e2e.mjs --project <agentcms dev で動いているプロジェクト>
```

手元の Docker に「VPS の代わり」を立て、setup・deploy・更新・切り戻し・自動の切り戻し・2 つ目のサイト・ぶつかる設定・秘密が出力やコンテナ設定に出ていないこと、を確かめてから、立てたものを消す。初回は PHP のイメージの build で 10 分ほどかかる。
