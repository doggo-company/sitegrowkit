# VPS（Docker）へ出す手順

Docker の入った Linux の VPS 1 台に、SiteGrowKit のサイトを出す手順。共用サーバー（XServer）へ出す手順は [xserver.md](xserver.md)。設計の理由は ADR-0021（kit の docs/adr/）。

> **確かめた範囲**：この道は、手元の Docker の中に立てた「VPS の代わり」（ssh で入れる Linux の中で Docker が動くもの）で、setup → deploy → 更新 → 切り戻し → 2 つ目のサイトまで通してある（`scripts/vps-e2e.mjs`）。**本物の VPS の会社のサーバーと、Let's Encrypt の本物の証明書では、まだ試していない。** 初めて出すときは staging から始めること。

> **既知の限界**（使う前に読むこと）:
> - **ディスクと MySQL の負荷は、サイトごとに区切っていない。** サイトのコンテナは同じ利用者で動くので、1 つのサイトが `shared/storage` と自分の DB を育て続けると、同じディスクのほかのサイトと MySQL も書けなくなる。共有の MySQL の CPU・I/O も分けていない（重い SQL を流し続けるサイトは、ほかのサイトを遅くできる）。区切り方は §8b
> - **出し直し・戻しのたびに、数秒〜十数秒 502 になる。** キャッシュを作る前にサイトのコンテナを止め、切り替えてから起こすため（乗っ取られたサイトが残したプロセスと storage の中の PHP を消すため）。「VPS の代わり」で見た時間で、本物の VPS では測っていない
> - **本物の VPS・Let's Encrypt・再起動では、まだ試していない**（上の「確かめた範囲」）
> - doctor が気づけるのは、流したときだけ。乗っ取られたサイトが DB に残した物（管理者の追加など）は、出し直し・戻しでは消えない（§7）

## 何ができあがるか

```
                    インターネット（80 / 443）
                              │
 ┌──────────── VPS 1 台 ───────┼──────────────────────────────────────────┐
 │  Caddy（HTTPS の証明書を Let's Encrypt から取り、自動で更新する・転送だけ） │
 │    ├─ サイト A の行 → サイト A の web（nginx）→ サイト A の PHP-FPM         │
 │    └─ サイト B の行 → サイト B の web（nginx）→ サイト B の PHP-FPM         │
 │  MySQL 8.4（1 つ。サイトごとに DB と利用者を分ける・外へポートを出さない）    │
 │  各サイトの scheduler のコンテナ（毎分 schedule:run・cron は使わない）       │
 │  各サイトの backup のコンテナ（毎日 DB とファイルの写しを control に取る）    │
 └─────────────────────────────────────────────────────────────────────────┘
```

Caddy にはサイトのファイルを見せない（転送だけ）。サイトの web と PHP は、そのサイトの置き場所しか見えない。PHP-FPM は TCP で待たず、ソケットでだけ待つ。だから、あるサイトが乗っ取られても、ほかのサイトのファイルや PHP には届かない（DB の利用者も分かれている）。

**サイトのコンテナ（app・scheduler）は、置き場所（`sites/<サイト名>/`）を読むだけで入れ、書けるのは `shared/storage` だけ。** コード（`releases/`・`current`・`bootstrap/cache`）と `shared/.env` を書き換えられると、乗っ取られたあとに出し直しても戻しても乗っ取りが残るため。config / view のキャッシュと migration は、deploy がその release の `bootstrap/cache` と `shared/storage` だけを書ける使い捨てのコンテナで流す。

それでも、`shared/storage` の中と、前の版のキットで出したサイトの置き場所には、サイトの PHP が書いた物（値も、ほかの場所を指す symlink も）がありうる。そこで、サーバーの処理（setup・deploy・MySQL の root）は置き場所の中身を入力にしない:

- サーバーが決める物（compose・web の設定・夜のバックアップの台本と写し・公開の記録・鍵・途中で止まった setup の値）は `control/<サイト名>/` に置く。サイトのコンテナには見せない
- MySQL の root で流す SQL には、設定のサイト名から作った名前（`sgk-<サイト名>`）とサーバーで作ったパスワードだけを入れる。サイトの `.env` の値は入れない
- サイトの置き場所の中のファイル操作（展開・リンク・切り替え・掃除・バックアップを書く・`.env` を置く）は、**その置き場所だけを入れた使い捨てのコンテナの中**で、サイトと同じ利用者で流す。symlink を置かれても、その先はコンテナの中でしか解けない
- サイトのコンテナの根は読むだけ。書けるのは `shared/storage` と、大きさを決めたメモリの上の場所（`/tmp` 64MB・`/dev/shm` 16MB・PHP-FPM のソケットの置き場所 1MB）だけ。サイトの置き場所の外のディスク（Docker の置き場所・`control/`）を埋めさせない
- 夜のバックアップの写しと成否は、サーバーの backup のコンテナ（サイトのコードは動かさない）が `control/<サイト名>/backup` に書く。サイトの中のアプリの記録（`auto.json`）は、乗っ取られたサイトなら「成功」と書き換えられるので、doctor は読まない（§9）

| 置き場所（サーバー） | 中身 |
| --- | --- |
| `/srv/agentcms/edge/` | Caddy と MySQL の compose・Caddyfile・`sites/<サイト名>.caddy`・`secrets/mysql-root` |
| `/srv/agentcms/sites/<サイト名>/` | サイトのコンテナが見る物（読むだけ。書けるのは `shared/storage` だけ）：`releases/<id>`・`shared/.env`・`shared/storage`・`backups/`・`current` |
| `/srv/agentcms/control/<サイト名>/` | サーバーだけが触る物（700・サイトのコンテナには見せない）：`compose.yml`・`nginx.conf`・`backup.sh` と `db-password`（夜のバックアップの台本とこのサイトの DB 利用者のパスワード・600）・`backup/`（夜のバックアップの写しと成否 `status.json`）・`artifacts/`（送った artifact と checksum・画面の部品の checksum `<release>.views`・rollback の作り直しと doctor の比べ物）・`site`（このサイト名のドメイン）・`releases.history`（公開の記録）・`deploy.lock`・`env.pending`（setup の途中の値・600） |

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
| `vps.backup` | 省いてよい。サーバーが取る夜のバックアップ（§9）の `at`（時刻・**UTC**・既定 `"18:00"`＝日本時間 03:00）・`keep`（残す数・既定 7）・`maxMB`（1 つのファイルの上限・既定 2048） |

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

`deploy staging` の流れ：手元で artifact を作る（vite build と composer install --no-dev）→ doctor → サイトの鍵を取る → 送って展開 → **サイトのコンテナ**（PHP-FPM・web・scheduler。web の設定を確かめる）→ **Caddy の行**（サイトの web が起きてから、別の場所で `caddy validate` して差し替える。同じドメインを別のサイトが使っていれば何も変えずに止まる）→ **DB バックアップ** → migration → キャッシュ → `current` の切り替え → health check。Caddy の行を変えるのはサイトの web が起きたあとなので、それより前（送信・展開など）で落ちても入口は前のまま。

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

health まで通った release には印が付き、公開した順に `releases.history` へ書かれる。rollback はこの記録で 1 つ前に出ていた release へ戻る（release の番号＝手元の時計の時刻の順ではない。migration などで途中で落ちた release には戻らない）。戻した先の health が通らなければ、元の release に戻して止まる。古い release は、印のあるものを `keepReleases`（既定 3）まで残し、途中で落ちたものは片付ける。同じサイトへの deploy と rollback は同時に 1 つしか動かない（2 つ目は「別の deploy / rollback が動いています」で止まる）。PHP は `current` の symlink をサイトの web（nginx）が実際のパスへ解いてから受け取るので、切り替えた直後から新しい（戻したら古い）コードが動く。

**送った artifact は `control/<サイト名>/artifacts/<release id>.tar.gz`（checksum つき・サイトのコンテナには見えない）に、残す release の分だけ残す。** 展開はそこから標準入力で流す（サイトの置き場所の `tmp/` を経由しないので、展開の前に中身を差し替えられない）。

- **rollback は、戻す先を置き場所の中の物のまま使わず、残した artifact から作り直してから切り替える。** 前の版のキットで出したサイトでは、置き場所の中の古い release を乗っ取られたサイトが書き換えられた（バックドアを置けた）ため。作り直す間は app と scheduler を止め、current を切り替えてから起こす（切り替える前にいまのコードを起こし直さない・中のプロセスは全部新しくなる）。共有の storage の中で PHP が読み込む物（メンテナンスの印 `storage/framework/maintenance.php`・コンパイル済みの view）を片付ける。artifact の checksum が合わない・artifact が無い（前の版のキットで出した release）ときは、何も変えずに止まる
- サイトの web は `/storage/`（上げられたファイル）と `/build/` の中の `.php` を PHP として動かさない。`/storage/` は `shared/storage/app/public` へ直に向け、その中のリンクは辿らない（`disable_symlinks`。サイトが `.env` などへのリンクを置いても配信しない）
- **コードの出し直し・戻しだけでは、乗っ取りからの復旧は終わらない。** 乗っ取られたサイトは自分の DB（管理者の追加・パスワードの変更など）と `shared/storage` の中（上げられたファイル）を書き換えられ、`shared/.env` の秘密を読める。乗っ取られたと分かったら、①原因を直したコードを出し直す ②DB をサーバーの写し（乗っ取られる前の時刻・§8 の `server-<時刻>`）から戻す（全員のログインも切れる）③`shared/.env` の APP_KEY・DB のパスワード（`control/<サイト名>/db-password` も）と管理者のパスワードを替える ④`shared/storage/app` を確かめる、までを 1 組でやる
- **deploy と rollback は、config / view のキャッシュを作る前にサイトのコンテナ（app・scheduler）を止め、`shared/storage` の中で PHP が読み込む物（メンテナンスの印 `framework/maintenance.php`・コンパイル済みの view・`framework`・`views` がリンクならそのリンク）を片付け、current を切り替えてから起こす。** 乗っ取られたサイトがコンテナの中に残したプロセスはコンテナを止めるまで動き続け、storage はどの release からも同じ物が見えるため（止めてから起こすまで 502。キャッシュを作る数秒〜十数秒）。片付けられなければ起こさずに止まる。health が落ちたときの自動の切り戻しも、手での rollback と同じく送った artifact から作り直してから切り替える（artifact の無い release へは戻さない）。DB を戻す手順（§8）も、コードを合わせるときは artifact から作り直す
- 前の版のキットで出したサイトを出し直すときは、展開より前に、置き場所全体を書ける形の app・scheduler を止める（新しい release を書き換えさせない。そのあいだは 502）
- **サイトのコンテナが書けるのは `shared/storage` だけ。** そこ（上げられたファイル・コンパイル済みの view・ログ）と DB は、乗っ取られたサイトが書き換えられる。乗っ取られたと分かったら、原因（脆弱性）を直したコードを出し直し、`shared/.env` の秘密（APP_KEY・DB のパスワード）と管理者のパスワードを替え、storage と DB はサーバーの写し（§9）から戻すかを決めること。前の版のキットで出したサイトは、出し直すまでコンテナが置き場所全体を書ける形のまま（doctor が WARN を出す）
- **`agentcms deploy doctor` は、いま出ている release を送った artifact と比べる**（使い捨てのコンテナの中の GNU tar・サイトのコードは動かさない）。中身の違うファイル・足されたファイルがあれば WARN（数だけ・名前は出さない）。shared へのリンク（`storage`・`.env`・`public/storage`）と deploy が作る `bootstrap/cache` は比べない（PASS の行にもそう出す。そこに置かれた PHP には、この比べでは気づけない）。サイトのコンテナはいまはコードを書けないが、前の版のキットで出したあいだに書き換えられていないかの手がかりになる
- **`agentcms deploy doctor` は、画面の部品（`shared/storage/framework/views` のコンパイル済みの view）と `bootstrap/cache` を、deploy のときに残した checksum と比べる。** コンパイル済みの view はどのページでも PHP として読み込まれ、置き場所が storage なのでサイトのコンテナから書ける。deploy はキャッシュを作ったあと（サイトのコンテナを止めている間）に一覧と checksum を `control/<サイト名>/artifacts/<release>.views`（サイトから見えない）に残し、doctor はいまの一覧をそれと比べる（使い捨てのコンテナの中・サイトのコードは動かさない）。書き換えられた物・足された物があれば WARN（数だけ・名前は出さない）。消えた物は WARN にしない（アプリが読むだけのソースから作り直すため）
  - view:cache では作られず、最初のページとメールでアプリが作る view（Inertia の外枠の部品・通知メールの部品）は、deploy と rollback がキャッシュを作るときに前もって作り、記録に入れる。公開したあとに見つかった物を記録へ足すことはしない（そのあいだに置かれた物を信じることになるため）
  - **足された物は、区別できないことがある。** アプリはメールを送るときなどに、deploy のときに作らない view（メールの部品）を後から作る。足された物だけの WARN は、それのこともある。出し直すと消えて記録し直す
  - 気づけるのは doctor を流したときだけ（見張りは別に置く）。気づいたあとは上の「乗っ取られたと分かったら」と同じ

## 8. DB を戻す（手で）

deploy のたびに `backups/<release id>-db.sql.gz` を取っている（その release を出す**直前**の DB）。横の `backups/<release id>-db.release` には、そのとき出ていた release の番号が入っている。戻すのは人が判断する（自動ではやらない）。

**サイトが乗っ取られたかもしれないときは、サーバーが取った夜の写し（§9・`control/<サイト名>/backup`）から戻す。** `backups/` はサイトの置き場所の中にあり、乗っ取られたサイトは中身を書き換えられる。`server-<時刻>` を渡すと、スクリプトは写しを checksum で確かめ、サイトの置き場所を通さずに直接戻す（コードはそのまま＝`keep-code` と同じ。コードは `agentcms deploy` か `rollback`（送った物から作り直す）で入れ直す）。

どちらから戻しても、戻したあとは**全員のログインを切る**（`users.session_epoch` を進め、`remember_token` と `sessions` の表を消す。管理画面からの復元と同じ）。乗っ取られていた間のログインを、戻した DB で生き返らせないため。

次の中身をサーバーに `restore-db.sh` として保存し、`bash restore-db.sh <サイト名> <release id>`（または `server-<時刻>`）で流す（`<release id>` は `ls backups`、`<時刻>` は `ls /srv/agentcms/control/<サイト名>/backup` で見る）。1 行ずつ貼り付けずに、ファイルにして流すこと（途中で失敗したらそこで止まるように書いてある）。

```bash
#!/bin/bash
# 使い方: bash restore-db.sh <サイト名> <release id | server-<時刻>> [keep-code]
#   keep-code … DB を取ったときの release が分からない・消えているときに、コードはそのままで DB だけ戻す
#   server-<時刻> … サーバーが取った夜の写し（control/<サイト名>/backup・サイトは書けない）から戻す（コードはそのまま）
set -euo pipefail
site="$1"; id="$2"; mode="${3:-}"
root=/srv/agentcms   # vps.root を変えたなら、ここも変える
[[ "$site" =~ ^[a-z][a-z0-9-]{0,26}[a-z0-9]$ ]] || { echo "サイト名の形が違います: $site"; exit 1; }
[[ "$id" =~ ^([0-9]{14}|before-restore-[0-9]{14}|server-[0-9]{14})$ ]] || { echo "release id の形が違います: $id"; exit 1; }
dir="$root/sites/$site"; ctl="$root/control/$site"
# サーバーの写しは control の中（サイトのコンテナには見えない）。コードの release の記録は無いので、コードはそのまま
src=""
if [[ "$id" == server-* ]]; then src="$ctl/backup/${id#server-}-db.sql.gz"; mode=keep-code; fi
db="sgk-$site"; user="sgk-$site"   # DB 名・利用者名はサイト名から決まる（.env の値は使わない）
# サイトの置き場所の中は、サイトの PHP が書き換えられる（symlink も置ける）。
# 中のファイルは、その置き場所だけを入れた使い捨てのコンテナの中で読み書きする
# どちらの使い捨てのコンテナにも、メモリ・プロセス・CPU・時間の上限を付け、ログを残さない。
# 時間はコンテナの中の timeout で切るので、この端末や ssh が切れてもサーバーの上で終わり、--rm で消える
# （--init で PID 1 を Docker の init にする。timeout の対象が PID 1 だと KILL が届かない）
in_site() { docker run --rm -i --init --pull never --network none --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp:size=64m \
  --memory 512m --memory-swap 512m --pids-limit 128 --cpus 1.0 --log-driver none \
  --user "$(id -u):$(id -g)" -v "$dir:$dir" -w "$dir" --entrypoint timeout agentcms-php:8.5-2 -s KILL 600 sh -c "$1"; }
pw() { in_site 'grep -E "^DB_PASSWORD=" shared/.env | tail -1 | cut -d= -f2- | head -c 128' < /dev/null; }
# MySQL のクライアントは、MySQL のコンテナではなく使い捨てのコンテナで流す（秘密も他のサイトのデータも
# 入っていない）。バックアップの中身はサイトが書き換えられるので:
#  - サイトの利用者で流す（root では流さない）
#  - --binary-mode でクライアントのコマンド（\! でシェルを動かす・source など）を止める
# 利用者のパスワードは標準入力の 1 行目で渡す（コマンドの引数に載せない）
in_mysql() { docker run --rm -i --init --pull never --network agentcms --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp:size=64m \
  --memory 512m --memory-swap 512m --pids-limit 64 --cpus 1.0 --log-driver none \
  --entrypoint timeout mysql:8.4 -s KILL 3600 sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec "$@"' sh "$@"; }
# deploy・rollback と同時に動かさない。最初に鍵を取る（確かめたあとに release を消されないように。取れなければ何も変えずに止まる）
mkdir "$ctl/deploy.lock" 2>/dev/null || { echo "別の deploy / rollback / 戻す作業が動いています: $(cat "$ctl/deploy.lock/info" 2>/dev/null)"; exit 1; }
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) restore-db $id" > "$ctl/deploy.lock/info"
trap 'rm -rf "$ctl/deploy.lock"' EXIT
# 1. 鍵を取ったあと、何も変える前に確かめる（ここで止まれば、アプリは動いたまま・DB もそのまま）
if [ -n "$src" ]; then
  s="${id#server-}"
  ( cd "$ctl/backup" && [ ! -L "$s-db.sql.gz" ] && gzip -t "$s-db.sql.gz" && grep " $s-db.sql.gz\$" "$s.sha256" | sha256sum -c --quiet - ) \
    || { echo "サーバーの写しがありません（無いか、壊れているか、checksum が合いません）: $src"; exit 1; }
else
  in_site "gzip -t backups/$id-db.sql.gz" < /dev/null || { echo "バックアップがありません（無いか、壊れています）: backups/$id-db.sql.gz"; exit 1; }
fi
rel=$(in_site "head -c 32 backups/$id-db.release 2>/dev/null | head -1 || true" < /dev/null)
if [ "$mode" != keep-code ]; then
  [[ "$rel" =~ ^[0-9]{14}$ ]] && in_site "test -d releases/$rel" < /dev/null || {
    echo "この DB を取ったときの release（${rel:-不明}）がありません。コードを合わせられないので止めます。分かったうえで DB だけ戻すなら、最後に keep-code を付けてください"
    exit 1
  }
fi
trap 'echo "途中で止まりました。アプリは止めたままです。backups/before-restore-* に戻す前の DB があります。直してからもう一度流すか、docker compose -f $ctl/compose.yml start で起こしてください"; rm -rf "$ctl/deploy.lock"' ERR
# 2. アプリ・web・scheduler を止める（書き込みを止める）。compose はサイトから見えない control の物
docker compose -f "$ctl/compose.yml" stop
# 3. いまの DB と、いま出ている release を取っておく（取れなければここで止まる。DB はまだ変えていない）
safe="before-restore-$(date +%Y%m%d%H%M%S)"
pw | in_mysql mysqldump --single-transaction --no-tablespaces -h agentcms-mysql -u "$user" "$db" | gzip \
  | in_site "set -e; t=\$(mktemp backups/.db.XXXXXX); cat > \"\$t\"; gzip -t \"\$t\"; [ \$(wc -c < \"\$t\") -ge 200 ]; mv -f \"\$t\" backups/$safe-db.sql.gz; basename \"\$(readlink current)\" > backups/$safe-db.release"
# いまのログインの世代（戻したあとに、これより大きくして全員のログインを切る）
epoch=$( { pw; echo "SELECT COALESCE(MAX(session_epoch), 0) FROM users;"; } | in_mysql mysql --binary-mode -N -h agentcms-mysql -u "$user" "$db" 2>/dev/null || true)
[[ "$epoch" =~ ^[0-9]{1,15}$ ]] || epoch=0
# 4. DB を空にしてから戻す（バックアップの後に増えた表も残らない）
{ pw; printf 'DROP DATABASE `%s`;\nCREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" "$db"; } | in_mysql mysql --binary-mode -h agentcms-mysql -u "$user"
if [ -n "$src" ]; then
  { pw; gunzip -c "$src"; } | in_mysql mysql --binary-mode -h agentcms-mysql -u "$user" "$db"
else
  { pw; in_site "gunzip -c backups/$id-db.sql.gz" < /dev/null; } | in_mysql mysql --binary-mode -h agentcms-mysql -u "$user" "$db"
fi
# 4b. 全員のログインを切る（戻した DB の中のログインの状態を使わせない。表が無い古い DB なら飛ばす）
q() { { pw; echo "$1"; } | in_mysql mysql --binary-mode -N -h agentcms-mysql -u "$user" "$db"; }
if [ "$(q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_epoch';")" = 1 ]; then
  q "UPDATE users SET session_epoch = GREATEST($epoch, (SELECT m FROM (SELECT COALESCE(MAX(session_epoch), 0) AS m FROM users) t)) + 1, remember_token = NULL;" > /dev/null
fi
if [ "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sessions';")" = 1 ]; then q "DELETE FROM sessions;" > /dev/null; fi
# 5. コードを、この DB を取ったときに出ていた release へ合わせてから起こす。置き場所の中の release は
#    前の版のキットではサイトが書き換えられたので、送った artifact（control の中・checksum つき）から作り直してから向ける。
#    artifact が無ければ（前の版のキットで出した release）、コードは変えずに止める
if [ "$mode" != keep-code ]; then
  ( cd "$ctl/artifacts" && [ ! -L "$rel.tar.gz" ] && grep " $rel.tar.gz\$" "$rel.sha256" | sha256sum -c --quiet - ) \
    || { echo "release $rel の送った artifact が無いか、checksum が合いません。DB は戻しました。コードは変えていません（アプリは止めたまま）。agentcms deploy で出し直してから docker compose -f $ctl/compose.yml start"; exit 1; }
  r="releases/$rel"; t="releases/.rebuild-$rel"
  in_site "set -e; rm -rf $t; mkdir $t; tar -xzf - -C $t; test -f $t/artisan; rm -rf $t/storage $t/.env; ln -sfn $dir/shared/storage $t/storage; ln -sfn $dir/shared/.env $t/.env; mkdir -p $t/public $t/bootstrap/cache; ln -sfn $dir/shared/storage/app/public $t/public/storage; touch $t/.agentcms-healthy; rm -rf $r; mv $t $r" < "$ctl/artifacts/$rel.tar.gz"
  # キャッシュを作る前に storage の view の置き場所を片付ける（リンクのまま作ると、サイトが選んだ場所が出力先になる）
  in_site 's=shared/storage; f=$s/framework; [ -d "$s" ] && [ ! -L "$s" ] && { if [ -L "$f" ] || { [ -e "$f" ] && [ ! -d "$f" ]; }; then rm -rf "$f"; fi; } && mkdir -p "$f" && { chmod -R u+rwX "$f" 2>/dev/null || true; } && { for d in views cache sessions; do if [ -L "$f/$d" ] || { [ -e "$f/$d" ] && [ ! -d "$f/$d" ]; }; then rm -rf "$f/$d"; fi; done; } && mkdir -p "$f/views" "$f/cache/data" "$f/sessions" && rm -rf "$f/maintenance.php" "$f/down" && find "$f/views" -mindepth 1 \( -type l -o \( -name "*.php" ! -type d \) \) -delete && [ ! -L "$f" ] && [ -d "$f/views" ] && [ ! -L "$f/views" ] && [ ! -e "$f/maintenance.php" ] && [ ! -L "$f/maintenance.php" ] && [ -z "$(find "$f/views" -mindepth 1 \( -type l -o -name "*.php" \) | head -1)" ]' < /dev/null
  # キャッシュは、その release の bootstrap/cache と shared/storage だけを書ける使い捨てのコンテナで作る（途中にリンクがあれば入れない）
  b=$(cd -P -- "$dir" && pwd)
  [ "$(cd -P -- "$dir/$r/bootstrap/cache" && pwd)" = "$b/$r/bootstrap/cache" ] && [ "$(cd -P -- "$dir/shared/storage" && pwd)" = "$b/shared/storage" ]
  docker run --rm --init --pull never --network agentcms --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp:size=64m \
    --memory 768m --memory-swap 768m --pids-limit 256 --cpus 1.0 --log-driver none --user "$(id -u):$(id -g)" \
    -v "$dir:$dir:ro" -v "$dir/$r/bootstrap/cache:$dir/$r/bootstrap/cache" -v "$dir/shared/storage:$dir/shared/storage" -w "$dir/$r" \
    --entrypoint timeout agentcms-php:8.5-2 -s KILL 900 sh -c "php artisan config:cache && php artisan view:clear && php artisan view:cache" < /dev/null
  in_site "ln -sfn $dir/$r current" < /dev/null
fi
# storage の中で PHP が読み込む物（メンテナンスの印・コンパイル済みの view）は、起こす前に消す（サイトが置ける）
#    （framework・views がリンクなら本物を作り直す・権限を外されていても戻して消す・片付いたことを確かめる。できなければ起こさずに止まる）
in_site 's=shared/storage; f=$s/framework; [ -d "$s" ] && [ ! -L "$s" ] && { if [ -L "$f" ] || { [ -e "$f" ] && [ ! -d "$f" ]; }; then rm -rf "$f"; fi; } && mkdir -p "$f" && { chmod -R u+rwX "$f" 2>/dev/null || true; } && { for d in views cache sessions; do if [ -L "$f/$d" ] || { [ -e "$f/$d" ] && [ ! -d "$f/$d" ]; }; then rm -rf "$f/$d"; fi; done; } && mkdir -p "$f/views" "$f/cache/data" "$f/sessions" && rm -rf "$f/maintenance.php" "$f/down" && find "$f/views" -mindepth 1 \( -type l -o \( -name "*.php" ! -type d \) \) -delete && [ ! -L "$f" ] && [ -d "$f/views" ] && [ ! -L "$f/views" ] && [ ! -e "$f/maintenance.php" ] && [ ! -L "$f/maintenance.php" ] && [ -z "$(find "$f/views" -mindepth 1 \( -type l -o -name "*.php" \) | head -1)" ]' < /dev/null
# 画面の部品（コンパイル済みの view）は上で消したので、deploy が残した記録（control）はもう合わない。消しておく
# （doctor は「記録が無い」と WARN を出す。agentcms deploy で出し直すと記録し直す）
cid=$(basename "$(readlink "$dir/current")")
[[ "$cid" =~ ^[0-9]{14}$ ]] && rm -f "$ctl/artifacts/$cid.views"
if [ "$mode" != keep-code ]; then rm -f "$ctl/artifacts/$rel.views"; fi
docker compose -f "$ctl/compose.yml" start
echo "戻しました: DB は $id の${src:+（サーバーの写し）}直前 / current は $(basename "$(readlink "$dir/current")") / ログインは全員切りました"
```

- 戻したあと、手元で `agentcms verify staging`（本番なら health を見る）で確かめる
- 戻したあとの `agentcms deploy doctor` は「画面の部品の記録が無い」と WARN を出す（このスクリプトはコンパイル済みの view を消して、アプリが使うときに作り直させるため）。`agentcms deploy` で出し直すと記録し直して PASS になる
- 戻す前の DB と release は `backups/before-restore-<時刻>-db.sql.gz`・`-db.release` に残る。同じスクリプトに `before-restore-<時刻>` を `<release id>` の代わりに渡せば、DB もコードも戻す前の状態へ戻る

## 8b. 1 台に置くサイトの上限（ほかのサイトを止めないために）

サイトの PHP が乗っ取られても、ほかのサイトを止めにくいように、次の上限を付けている:

- サイトのコンテナ（app・web・scheduler）それぞれ：メモリ 768MB・CPU 1・プロセス 256・ログ 10MB×3。根は読むだけで、`/tmp` は 64MB・`/dev/shm` は 16MB・PHP-FPM のソケットの置き場所は 1MB の tmpfs（どれもメモリの上限にも数える）。置き場所の中で書けるのは `shared/storage` だけ（web は全部読むだけ）
- backup のコンテナ：メモリ 512MB・CPU 0.5・プロセス 64・ログ 10MB×3。写しは 1 つ `vps.backup.maxMB` まで・`keep` 個まで（サーバーのディスクを使うのは最大で `(keep + 1) × 2 × maxMB`。新しい回を書いてから古い回を消すため）。上限の 2 倍を書いても `control/` のファイルシステムの 15% 以上が空いたまま残るときだけ取り、残らなければ取らずに `no-space` と書く（乗っ取られたサイトが自分のファイルを大きくしても、写しでサーバーのディスクを使い切らせない。ほかのサイトの写しが `no-space` で落ちることはある）
- サイトの DB 利用者：同時接続 30（deploy のたびに付け直す）
- サイトの web は、そのサイトの web と Caddy だけが入るネットワーク。ほかのサイトのコンテナから直接は届かない

**付けていないもの（サーバーの側で決める）:**

- **ディスクの容量。** サイトのコンテナは同じ利用者（uid）で動くので、利用者ごとの quota では区切れない。1 つのサイトが置き場所を書き足し続けると、同じファイルシステムの MySQL とほかのサイトも書けなくなる。サイトを置く場所（`/srv/agentcms/sites`）を、サイトごとに容量を区切ったファイルシステム（XFS の project quota・サイトごとのディスクイメージなど）にするか、少なくとも Docker の置き場所（MySQL のデータ）と別のディスクにする。`agentcms deploy doctor` は、同じファイルシステムなら WARN を出す
- **共有の MySQL の CPU・メモリ・I/O。** 重い SQL を流し続けるサイトは、ほかのサイトの DB を遅くできる（接続の数は上の 30 まで）。区切るなら、サイトごとに MySQL を分ける

## 9. 毎日のバックアップ

**サーバーが取る写し（設定なしで動く）。** サイトごとの backup のコンテナ（`agentcms-<サイト名>-backup`）が、毎日 `vps.backup.at`（UTC）に DB（このサイトの DB 利用者で `mysqldump`）と `shared/storage/app`（画像など。アプリの zip は入れない・中のリンクはリンクのまま）を取り、`/srv/agentcms/control/<サイト名>/backup/` に置く。最初の deploy で立ち上がったときにも 1 回取る。

```
control/<サイト名>/backup/
  <時刻>-db.sql.gz  <時刻>-files.tar.gz  <時刻>.sha256   … 新しい順に keep 個
  status.json   … 最後の回の成否（state: ok / failed / running・失敗の言葉・最後の成功の時刻）
```

- **成否はサイトの手が届かない場所にサーバーが書く。** サイトの PHP はアプリの記録（`auto.json`）も zip も書けるので、乗っ取られたサイトは「成功」を装える。`agentcms deploy doctor` は `status.json` だけを読み、失敗・30 時間成功なし・途中で止まった・コンテナが動いていない、を WARN で出す（deploy は止めない）
- 失敗の言葉：`db-auth`（このサイトの DB 利用者で入れない。DB のパスワードを変えたら `control/<サイト名>/db-password` も同じ値にする）・`db-connect`・`db-dump`・`files-link`（`shared`・`storage`・`app` がリンクに差し替わっている）・`files`・`too-large`・`no-space`・`no-db-password`・`write`。詳しい理由は `docker logs agentcms-<サイト名>-backup`
- 手で 1 回取る：`docker exec agentcms-<サイト名>-backup bash /agentcms/backup.sh now`
- この写しから DB を戻す：§8 のスクリプトに `server-<時刻>` を渡す（写しを checksum で確かめ、サイトの置き場所を通さずに戻す。コードはそのまま）。`bash restore-db.sh <サイト名> server-<時刻>`
- この写しはサーバーの中にしかない。サーバーごと失ったときのために、`control/<サイト名>/backup/` を別の置き場所へ写す（サーバーの cron と rclone など）

**アプリの自動バックアップ（zip・管理画面から戻せる）。** scheduler のコンテナが毎分 `schedule:run` を流すので、`shared/.env` に `AGENTCMS_BACKUP_SCHEDULE=03:00` を入れれば毎日取る。別の置き場所へ写す設定は [MAINTENANCE.md](../MAINTENANCE.md) の「毎日のバックアップを別の置き場所へ」。`.env` を変えたら `agentcms deploy staging` を流し直す（設定のキャッシュを作り直すため）。こちらの成否（管理画面・`auto.json`）はサイトの中の表示なので、サイトが乗っ取られたときはあてにしない。

## 10. 2 つ目のサイトを足す

別のプロジェクト（または別の target）で、**別の `vps.site` と別のドメイン**の設定を作り、§5・§6 を流す。edge（Caddy・MySQL・PHP のイメージ）は共有し、サイトごとに分かれるのは次のもの:

- 置き場所（`/srv/agentcms/sites/<サイト名>/`）・コンテナ（`agentcms-<サイト名>-app` / `-web` / `-scheduler`）。ほかのサイトのコンテナからは、この置き場所も PHP-FPM も見えない
- DB と DB の利用者（自分の DB にしか権限がない）
- Caddy の行（`edge/sites/<サイト名>.caddy`）

**同じドメインや同じサイト名の設定で出そうとすると、何も変えずに止まる。** Caddy の行は、動いている Caddy の外で確かめてから差し替え、reload が落ちたら元の行に戻すので、ほかのサイトは止まらない。

## 11. サイトを外す（手で）

自動ではやらない（消したものは戻せない）。DB を残すかどうかを決めてから:

```bash
cd /srv/agentcms
docker compose -f control/<サイト名>/compose.yml down
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
| 500 / 502 が出る | `/srv/agentcms/sites/<サイト名>/shared/storage/logs/` と `docker logs agentcms-<サイト名>-app`・`docker logs agentcms-<サイト名>-web` |
| setup が「edge をこの版のキットの形にそろえる」と言う | キットを上げて Caddy や MySQL の版・設定が変わったとき。確認して流すと、Caddy と MySQL を作り直す（全サイトが数秒止まる。データと証明書は残る）。前の compose は `edge/compose.yml.prev` |
| setup が「DB か利用者 … が既にある」で止まる | 同じサイト名の DB が、別の置き場所か前の setup の途中で作られている。自分のものなら §11 の手順で DB と利用者を消してから setup をもう一度。他人のものならサイト名を変える |
| deploy が「別の deploy / rollback が動いています」で止まる | 終わるのを待つ。止まったまま残っているなら、ほかに動いていないことを確かめてから `/srv/agentcms/control/<サイト名>/deploy.lock` を消す |
| setup が途中で止まった | もう一度流す。DB を作ったあと `.env` を置く前に止まったときは、`control/<サイト名>/env.pending`（そのとき作った値・600・サイトのコンテナからは見えない）を使って続きから作る。サイトの置き場所の `shared/.env.pending` は読まない |
| doctor で「画面の部品」が WARN | 書き換えがあれば乗っ取りを疑う（§7 の「乗っ取られたと分かったら」）。足された物だけなら、メールを送ったあとのこともある。どちらも `agentcms deploy` で出し直すと消えて記録し直す |
| doctor で「夜のバックアップ」が WARN | 出ている失敗の言葉（§9）を見る。`docker logs agentcms-<サイト名>-backup` に詳しい理由。直したら `docker exec agentcms-<サイト名>-backup bash /agentcms/backup.sh now` |
| setup の PHP のイメージで止まる | 時間切れ（30 分）か、VPS から Docker Hub へつながらない。もう一度流せば続きから |

## 試験（kit を直す人向け）

```bash
node scripts/vps-e2e.mjs --project <agentcms dev で動いているプロジェクト>
```

手元の Docker に「VPS の代わり」を立て、setup・deploy・更新・切り戻し・自動の切り戻し・2 つ目のサイト・ぶつかる設定・秘密が出力やコンテナ設定に出ていないこと、を確かめてから、立てたものを消す。初回は PHP のイメージの build で 10 分ほどかかる。
