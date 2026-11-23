# ADR-0021: VPS（Docker）へのデプロイ（provider "vps"）

- Status: Accepted
- Date: 2026-11-24

## Context

ADR-0008 は XServer の共用サーバーへ出す道を決め、「VPS などほかの行き先は後の段で」とした。共用サーバーは 1 契約で置けるサイトの数と構成に限りがあり、何十ものサイトを同じ形で動かし、バックアップ・更新・切り戻しを揃えるには、自分で管理できる Linux の VPS 1 台の方が向いている。

条件：

- 本体は特定の VPS の会社に依存しない（Docker Engine が入る Linux なら同じ手順）
- 秘密（DB パスワード・APP_KEY・MySQL の root パスワード）はサーバーの `shared/.env` だけに置く。手元・Git・設定ファイル・ログ・コマンドの引数に出さない
- 1 台に複数のサイトを置き、1 つを足す・更新する・失敗することで、ほかのサイトが止まらない
- ADR-0008 の release directory 方式・DB バックアップ・health check と自動の切り戻しは、そのまま使う

## Decision

### 1. 構成：Caddy 1 つ・MySQL 1 つ・サイトごとの PHP-FPM

- **edge**（サーバーに 1 つ）：Caddy（Let's Encrypt の証明書を自動で取得・更新）と MySQL 8.4。MySQL はホストへポートを出さない。サイトごとに DB と利用者を分け、利用者は自分の DB にしか権限を持たない。名前は `sgk-<サイト名>`（`mysql`・`sys` と重ならない）。サイト名に `_`・`%` を許さない — MySQL の DB 単位の GRANT では `_`・`%` がワイルドカードになり、`shop_a` の権限が `shopxa` にも効くため
- 1 台の置き場所（`vps.root`）は 1 つ。違う値の設定は doctor と setup が止める（edge は 1 つなので、別の置き場所の同じ名前の DB を「無い」と読み違えないため）
- **Caddy はサイトのファイルを見ない**（転送だけ）。全サイトのディレクトリを Caddy に見せると、あるサイトの中に置かれた symlink を辿って、別のサイトの `.env`・設定のキャッシュ・バックアップを配信できる（Caddy の root は sandbox ではない — 公式の file_server の説明）
- **サイト**：PHP-FPM のコンテナ、web（nginx。root ではない利用者で動く公式の nginx-unprivileged）のコンテナ、scheduler のコンテナ（毎分 `current` に入り直して `schedule:run`。`schedule:work` のように入ったまま動き続けると、切り替えたあとも古い release で動くため）。どちらも ssh の利用者と同じ uid:gid で動く（`shared/.env` は 600）
- PHP-FPM は TCP（9000）で待たず、サーバーだけが触る置き場所の Unix ソケット（`control/<site>/run/php-fpm.sock`）でだけ待つ。そのサイトの web だけがつなぐ。TCP で待たせると、同じ Docker ネットワークの別のサイトから FastCGI を直接送れる（PHP 公式の注意）
- web は `current/public` を配信し、PHP へは `$realpath_root` で**symlink を実際の release のパスへ解いてから**渡す。ADR-0008 の entry stub が `realpath()` しているのと同じ理由（同じパスに見えると OPcache が古い release のコードを返す）。前に立つのは Caddy の HTTPS だけなので PHP には HTTPS・443 と伝え、利用者の IP は X-Forwarded-For から読む（Docker の内側の宛先だけを信じる）
- サイトのディレクトリはコンテナへ**同じ絶対パス**で入れる。`current`・`storage`・`.env` の symlink がそのまま解ける

### 2. 出す道：既存の release 方式に 3 つだけ差し替える

`deploy/release.ts` の工程（レイアウト・展開・shared リンク・migration・キャッシュ・切り替え・掃除）はそのまま使い、次の 3 つを provider "vps" で差し替える。

| 工程 | xserver | vps |
| --- | --- | --- |
| PHP を流す場所 | ssh の先で `cd release && php8.x artisan` | `docker exec -w release <サイト>-app php artisan`（`inDir()`） |
| 公開 | docroot へ同期 + index.php スタブ | Caddy のサイトの行（`siteRoute()`）とサイトの web（nginx） |
| DB バックアップ | ホストの mysqldump | MySQL のコンテナの中の mysqldump（パスワードは標準入力） |

### 3. Caddy の行は「確かめてから差し替える」

1. 同じドメインを別のサイトが使っていないか（各行の `# agentcms-domain:` の印）、同じサイト名を別のドメインが使っていないか（`control/<サイト>/site`）を見る。重なれば**何も変えずに止める**
2. 新しい行を入れた設定を、動いている Caddy の外（コンテナの /tmp）で `caddy validate`
3. 通ったら差し替えて `caddy reload`。reload が落ちたら元の行へ戻す（Caddy は reload に失敗すると前の設定のまま動く）

この順で、2 つ目のサイトの設定の誤りが 1 つ目のサイトを止めない。

deploy の中でこの差し替えをするのは、送信・展開・サイトのコンテナ（web の設定の確認まで）が済んだあと、DB に触る前。先に差し替えると、そのあとで落ちたとき current は前の版のままなのに入口だけがまだ無い web を向く。

### 4. 秘密はサーバーの上で作る：`agentcms deploy setup`

- MySQL の root パスワード・各サイトの DB パスワード・APP_KEY は、サーバーの `/dev/urandom` から作り、ファイル（600）にだけ書く
- 値はパイプ（標準入力）でだけ渡す。MySQL の root パスワードはコンテナにファイルで渡し（`MYSQL_ROOT_PASSWORD_FILE`）、`docker inspect` に値が出ない。新しいパスワードは SQL ごと標準入力で `mysql` へ渡す。`.env` の差し替えは builtin の `printf` で行い、`sed` などの引数に値を載せない
- edge の compose はキットが持つ。キットを上げて形が変わったら、setup が「そろえる」を確認つきで出す（前のものは `compose.yml.prev`）。Caddy は 2.11 系（FastCGI の CVE-2026-27590 の直った版。いまの形では Caddy は FastCGI を使わない）
- 作るだけ・何度流しても同じ。既にある `shared/.env`・edge の設定・root パスワードは上書きしない（パスワードを回さない）。`.env` が無いのに同じ名前の DB か利用者があれば、作らずに止める（ALTER USER しない — 別のサイトのパスワードを変えない）
- MySQL のデータがあるのに root パスワードのファイルが無いときは、作り直さずに止める
- 値はコマンドの引数・`docker inspect` に出ない。動いているプロセスの環境と読んだファイルの中には値がある（PHP・mysql クライアント・MySQL の entrypoint）。サーバーの root と docker グループの利用者は読める
- 設定ファイル（`.agentcms/deploy/<target>.json`）に `remote` と鍵・パスワードらしい名前は書かせない。`vps.site` と `app.url` のホスト名は形を絞る（シェル・Caddy・MySQL の名前にそのまま入るため）

### 5. 失敗したときと、同時に流したとき

- health まで通った release に印（`.agentcms-healthy`）を付け、公開した順に `control/<site>/releases.history` へ書く。rollback と掃除はこの記録の順で選ぶ（release の番号は手元の時計の時刻なので、build の長さや時計のずれで公開の順と食い違う）。途中で落ちた release へは戻らず、掃除で消す。`keepReleases` は 1〜20 の整数だけ受け取る
- 手での rollback で戻した先の health が通らなければ、元の release に戻して止める
- サイトごとの鍵（`mkdir` で取る `control/<site>/deploy.lock`）を、current を読む前から health・掃除が終わるまで持つ。edge の変更（edge の設定の初回作成・Caddy の行・DB の作成）も `mkdir` の鍵で順番に流す（flock は BusyBox と macOS で使い方が違うため使わない）
- Caddy の行の退避・差し替え・戻しは、それぞれ結果を確かめる。戻せなかったときは手で直す場所を出す
- DB を戻すのは手で、手順書のスクリプト（docs/deployment/vps.md §8）を流す。壊す前に確かめ（無い・壊れている）、アプリを止め、いまの状態を取り、DB を空にしてから戻し、コードを DB を取ったときの release（`backups/<id>-db.release`）に合わせてから起こす。途中で落ちたらアプリは止めたまま。通し試験はこのスクリプトを手を加えずに流す
- setup は「同じ名前の DB・利用者が無い」と確かめてから、作る値を `control/<site>/env.pending`（600・サイトのコンテナから見えない）に置き、それから DB に触る。途中で止まったら次の setup がその値で続け（`IF NOT EXISTS`）、その値で本当に入れるかを確かめてから権限を足す。入れなければ他人のものなので止める。既にある利用者のパスワードは変えない
- release の番号（手元の時計の秒）が既にあれば、鍵を取ったあとで止める（同じ名前の展開先を消さない）
- いまの release（current）を読めなかったときは「初回」と読まずに止める（戻す先が無いまま進まない）
- edge をそろえるとき、前の形（Caddy が直接配信する）のサイトの行が残っていれば、edge を変える前に止める。各サイトを今のキットで deploy し直すと行が今の形になり、そのあとでそろえられる
- DB を戻すスクリプトも同じサイトの鍵を取り、戻す前の DB と release を `before-restore-*` に残す。DB を取ったときの release が無ければ、壊す前に止まる

### 7. サイトの置き場所の中は、サーバーの処理の入力にしない

サイトのコンテナ（PHP）は `sites/<site>` を書き換えられる（値も、ほかの場所を指す symlink も置ける）。setup・deploy は docker グループの利用者（実質 root）と MySQL の root で動くので、そこから読んだ値や、そこを通る読み書きは、ほかのサイトやサーバーの秘密に届く。だから:

- サーバーが決める物（compose・web の設定・PHP-FPM のソケット・このサイト名のドメインの印・公開の記録・deploy の鍵・setup の途中の値）は `control/<site>`（700・どのコンテナにも見せない）に置く。compose の mount の元もそこから取る
- MySQL の root で流す SQL には、設定のサイト名から作った `sgk-<site>`（形を台本の中でも確かめる）と、setup がサーバーで作った英数字 32 文字のパスワードだけを入れる。DB バックアップも DB 名は同じ規則の名前だけ（サイトの `.env` は読まない）
- サイトの置き場所の中のファイル操作（レイアウト・展開・リンク・current の切り替え・掃除・バックアップを書く・`.env` を置く）は、その置き場所だけを入れた使い捨てのコンテナ（ネットワークなし・capability なし・サイトと同じ利用者）の中で流す。symlink はコンテナの中で解けるので外へ届かない。xserver と同じ工程の中身を、実行する場所だけ差し替える（`siteFsExecutor`）
- DB を戻す手順も同じ（戻すのはサイトの利用者で。バックアップの中身はサイトが書き換えられるので root では流さない。MySQL のクライアントは秘密の入っていない使い捨てのコンテナで `--binary-mode` を付けて流し、`\!`・`source` などクライアントのコマンドを止める）
- 1 サイトがサーバーを使い切らないよう、サイトのコンテナに上限（メモリ 768MB・CPU 1・プロセス 256・ログ 10MB×3）、DB の利用者に同時接続の上限（30）を付ける。ディスクの容量はサイトごとには区切っていない（同じ利用者の uid で動くので、区切るにはホストの project quota などが要る）
- サイトの web は、そのサイトの web と Caddy だけが入るネットワーク（外へ出られない）に置く。ほかのサイトのコンテナから web へ直接届かないので、`X-Forwarded-For` を偽って送信元 IP を変えることもできない

### 6. 試験

- 単体：`packages/cli/tests/deploy-vps.test.ts`（偽の ssh。設定の検査・置くファイルの中身・台本の形）
- 通し：`scripts/vps-e2e.mjs`（手で流す）。手元の Docker に「VPS の代わり」（docker:dind に sshd と一般の利用者を足したもの）を立て、本物の CLI で setup → deploy → 更新 → rollback → 自動の切り戻し → 2 つ目のサイト → ぶつかる設定 → 秘密が出力・`docker inspect`・置いたファイル・`ps` に出ていないこと、を確かめる。kit-verify には入れない（特権コンテナと数分の build が要るため）

## Consequences

- 本物の VPS の会社のサーバーと、Let's Encrypt の本物の証明書では、まだ試していない（「VPS の代わり」は Caddy の自前の認証局 `tls internal` で通している）
- Caddy はサイトのファイルを見ないが、すべてのサイトの通信の通り道ではある。Caddy・MySQL のコンテナや Docker そのものが乗っ取られた場合は、サイトの分離は保てない
- docker グループの利用者は実質 root。出す人の ssh の鍵はそのつもりで扱う
- サイトを外す・DB を消す・DB を戻すは自動にしない（ADR-0009 と同じ。手順は docs/deployment/vps.md §8・§11）
- 1 台に何サイト置けるかは測っていない
