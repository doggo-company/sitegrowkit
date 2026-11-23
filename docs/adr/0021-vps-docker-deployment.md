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
- **サイト**：PHP-FPM のコンテナと scheduler のコンテナ（毎分 `current` に入り直して `schedule:run`。`schedule:work` のように入ったまま動き続けると、切り替えたあとも古い release で動くため）。どちらも ssh の利用者と同じ uid:gid で動く（`shared/.env` は 600）
- PHP-FPM は TCP（9000）で待たず、サイトの置き場所の中の Unix ソケット（`<site>/run/php-fpm.sock`）でだけ待つ。Caddy はサイトの置き場所を読めるのでつなげるが、別のサイトのコンテナにはその置き場所が見えない。TCP で待たせると、同じ Docker ネットワークの別のサイトから FastCGI を直接送れる（PHP 公式の注意）
- Caddy が `current/public` を直接配信し、PHP へは `php_fastcgi … { resolve_root_symlink }` で**symlink を実際の release のパスへ解いてから**渡す。ADR-0008 の entry stub が `realpath()` しているのと同じ理由（同じパスに見えると OPcache が古い release のコードを返す）
- サイトのディレクトリはコンテナへ**同じ絶対パス**で入れる。`current`・`storage`・`.env` の symlink がそのまま解ける

### 2. 出す道：既存の release 方式に 3 つだけ差し替える

`deploy/release.ts` の工程（レイアウト・展開・shared リンク・migration・キャッシュ・切り替え・掃除）はそのまま使い、次の 3 つを provider "vps" で差し替える。

| 工程 | xserver | vps |
| --- | --- | --- |
| PHP を流す場所 | ssh の先で `cd release && php8.x artisan` | `docker exec -w release <サイト>-app php artisan`（`inDir()`） |
| 公開 | docroot へ同期 + index.php スタブ | Caddy のサイトの行（`siteRoute()`） |
| DB バックアップ | ホストの mysqldump | MySQL のコンテナの中の mysqldump（パスワードは標準入力） |

### 3. Caddy の行は「確かめてから差し替える」

1. 同じドメインを別のサイトが使っていないか（各行の `# agentcms-domain:` の印）、同じサイト名を別のドメインが使っていないか（`<サイト>/.agentcms-site`）を見る。重なれば**何も変えずに止める**
2. 新しい行を入れた設定を、動いている Caddy の外（コンテナの /tmp）で `caddy validate`
3. 通ったら差し替えて `caddy reload`。reload が落ちたら元の行へ戻す（Caddy は reload に失敗すると前の設定のまま動く）

この順で、2 つ目のサイトの設定の誤りが 1 つ目のサイトを止めない。

### 4. 秘密はサーバーの上で作る：`agentcms deploy setup`

- MySQL の root パスワード・各サイトの DB パスワード・APP_KEY は、サーバーの `/dev/urandom` から作り、ファイル（600）にだけ書く
- 値はパイプ（標準入力）でだけ渡す。MySQL の root パスワードはコンテナにファイルで渡し（`MYSQL_ROOT_PASSWORD_FILE`）、`docker inspect` に値が出ない。新しいパスワードは SQL ごと標準入力で `mysql` へ渡す。`.env` の差し替えは builtin の `printf` で行い、`sed` などの引数に値を載せない
- 作るだけ・何度流しても同じ。既にある `shared/.env`・edge の設定・root パスワードは上書きしない（パスワードを回さない）。`.env` が無いのに同じ名前の DB か利用者があれば、作らずに止める（ALTER USER しない — 別のサイトのパスワードを変えない）
- MySQL のデータがあるのに root パスワードのファイルが無いときは、作り直さずに止める
- 値はコマンドの引数・`docker inspect` に出ない。動いているプロセスの環境と読んだファイルの中には値がある（PHP・mysql クライアント・MySQL の entrypoint）。サーバーの root と docker グループの利用者は読める
- 設定ファイル（`.agentcms/deploy/<target>.json`）に `remote` と鍵・パスワードらしい名前は書かせない。`vps.site` と `app.url` のホスト名は形を絞る（シェル・Caddy・MySQL の名前にそのまま入るため）

### 5. 失敗したときと、同時に流したとき

- health まで通った release に印（`.agentcms-healthy`）を付け、公開した順に `releases.history` へ書く。rollback と掃除はこの記録の順で選ぶ（release の番号は手元の時計の時刻なので、build の長さや時計のずれで公開の順と食い違う）。途中で落ちた release へは戻らず、掃除で消す。`keepReleases` は 1〜20 の整数だけ受け取る
- 手での rollback で戻した先の health が通らなければ、元の release に戻して止める
- サイトごとの鍵（`mkdir` で取る `.agentcms-deploy.lock`）を、current を読む前から health・掃除が終わるまで持つ。edge の変更（edge の設定の初回作成・Caddy の行・DB の作成）も `mkdir` の鍵で順番に流す（flock は BusyBox と macOS で使い方が違うため使わない）
- Caddy の行の退避・差し替え・戻しは、それぞれ結果を確かめる。戻せなかったときは手で直す場所を出す
- DB を戻すのは手で、手順書のスクリプト（docs/deployment/vps.md §8）を流す。壊す前に確かめ（無い・壊れている）、アプリを止め、いまの状態を取り、DB を空にしてから戻し、コードを DB を取ったときの release（`backups/<id>-db.release`）に合わせてから起こす。途中で落ちたらアプリは止めたまま。通し試験はこのスクリプトを手を加えずに流す
- setup は DB に触る前に、作った値を `shared/.env.pending`（600）に置く。途中で止まったら次の setup がその値で続ける。`.env.pending` が無いのに同じ名前の DB・利用者があれば止める

### 6. 試験

- 単体：`packages/cli/tests/deploy-vps.test.ts`（偽の ssh。設定の検査・置くファイルの中身・台本の形）
- 通し：`scripts/vps-e2e.mjs`（手で流す）。手元の Docker に「VPS の代わり」（docker:dind に sshd と一般の利用者を足したもの）を立て、本物の CLI で setup → deploy → 更新 → rollback → 自動の切り戻し → 2 つ目のサイト → ぶつかる設定 → 秘密が出力・`docker inspect`・置いたファイル・`ps` に出ていないこと、を確かめる。kit-verify には入れない（特権コンテナと数分の build が要るため）

## Consequences

- 本物の VPS の会社のサーバーと、Let's Encrypt の本物の証明書では、まだ試していない（「VPS の代わり」は Caddy の自前の認証局 `tls internal` で通している）
- Caddy は全サイトのディレクトリを読み取り専用で見る（配信のため）。Caddy のコンテナが乗っ取られると全サイトの `shared/.env` が読める。サイトごとの分離は DB の利用者とコンテナまで
- docker グループの利用者は実質 root。出す人の ssh の鍵はそのつもりで扱う
- サイトを外す・DB を消す・DB を戻すは自動にしない（ADR-0009 と同じ。手順は docs/deployment/vps.md §8・§11）
- 1 台に何サイト置けるかは測っていない
