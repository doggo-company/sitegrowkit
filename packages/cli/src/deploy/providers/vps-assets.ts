import type { DeployTargetConfig } from "../types.js";

/**
 * VPS（Docker）へ出すときにサーバーへ置くファイルの**中身だけ**を作る純関数（ADR-0021）。
 *
 * ここでは SSH もファイルも触らない。中身を試験で固定できるようにするためで、
 * どれも**秘密を含まない**（DB パスワード・APP_KEY・MySQL の root パスワードは
 * サーバーの上で生成し、サーバーの外へ出さない — `vps.ts` の setup を参照）。
 *
 * ```
 * <root>/                      … 既定 /srv/agentcms（vps.root で変えられる）
 *   edge/                      … サーバーに 1 つ。Caddy（HTTPS と転送だけ）と MySQL
 *     compose.yml  Caddyfile
 *     sites/<site>.caddy       … サイトごとの行（agentcms deploy が書く）
 *     secrets/mysql-root       … MySQL の root パスワード（サーバーで生成・600）
 *   sites/<site>/              … サイトごと（= remote.appDir）。**サイトのコンテナが書き換えられる**
 *     releases/ shared/ backups/ tmp/ current -> releases/<id>
 *   control/<site>/            … サイトごとの、サーバーだけが触る物（サイトのコンテナには見せない・700）
 *     compose.yml nginx.conf   … このサイトの PHP-FPM・web（nginx）・scheduler・backup
 *     backup.sh db-password    … 夜のバックアップの台本と、このサイトの DB 利用者のパスワード（600）
 *     backup/                  … サーバーが取った夜のバックアップと、その成否（status.json）
 *     site                     … このサイト名を使っているドメイン
 *     releases.history         … health まで通って公開した順の記録
 *     env.pending              … setup の途中で止まったときの DB パスワード・APP_KEY（600）
 *     deploy.lock/ upload/     … deploy の鍵・送ってきた artifact
 * ```
 *
 * **サイトの置き場所（sites/<site>）の中の物は、サーバーの処理の入力にしない。**
 * サイトの PHP はそこを書き換えられる（値も、ほかの場所を指す symlink も置ける）。
 * サーバーの処理（docker グループの利用者・MySQL の root）がそれを読んだり、
 * そこを通って書いたりすると、ほかのサイトやサーバーの秘密に届く。だから:
 *
 * - サーバーが決める物・秘密は control/<site> に置く
 * - サイトの置き場所の中のファイル操作は、**その置き場所だけを入れた使い捨てのコンテナの中**で
 *   サイトと同じ利用者で流す（`siteFs`・vps.ts）。symlink はコンテナの中で解けるので、外へ届かない
 *
 * **サイトのコンテナが書ける場所は、サイトの置き場所と、大きさを決めたメモリの上の場所だけ。**
 * コンテナの書ける層（/tmp など）は Docker の置き場所（MySQL と同じディスク）にあり、サイトの
 * ディスクの区切りの外になる。だから根は読むだけにし、/tmp・/dev/shm・PHP-FPM のソケットの
 * 置き場所は大きさを決めた tmpfs にする（`SITE_TMPFS`）。
 *
 * **夜のバックアップの成否は、サイトの手が届かない場所にサーバーが書く。** サイトの PHP は
 * アプリの記録（auto.json）も zip も書けるので、成功を装える。サーバーの backup のコンテナ
 * （サイトのコードを使わない・サイトの置き場所は読むだけ）が DB とファイルの写しを
 * control/<site>/backup に取り、成否を status.json に書く。doctor はそちらを読む。
 */

/** サーバーの置き場所の既定値 */
export const VPS_DEFAULT_ROOT = "/srv/agentcms";
/** Caddy・MySQL・各サイトの PHP が入る Docker ネットワーク */
export const EDGE_NETWORK = "agentcms";
export const CADDY_CONTAINER = "agentcms-caddy";
export const MYSQL_CONTAINER = "agentcms-mysql";
/** 各サイトの PHP-FPM が使うイメージ。Dockerfile を変えたら末尾の番号を上げる */
export const PHP_IMAGE = "agentcms-php:8.5-2";
/** コンテナの中の PHP-FPM のソケット（TCP では待たない） */
export const FPM_SOCKET_IN_CONTAINER = "/run/agentcms/php-fpm.sock";
export const CADDY_IMAGE = "caddy:2.11-alpine";
/** サイトの web。root ではない利用者で動かせる公式の nginx */
export const NGINX_IMAGE = "nginxinc/nginx-unprivileged:1.27-alpine";
/** サイトの web が待つポート（Docker の内側だけ。ホストへは出さない） */
export const WEB_PORT = 8080;

/**
 * サイトのコンテナ 1 つずつの上限。乗っ取られた 1 サイトが CPU・メモリ・プロセス・ログで
 * サーバーを使い切り、ほかのサイトや MySQL を止めないように（Docker の既定は上限なし）
 */
export const SITE_LIMITS = {
  memory: "768m",
  cpus: "1.0",
  pids: 256,
  logMaxSize: "10m",
  logMaxFile: "3",
} as const;
/** サイトの DB 利用者 1 人が同時に張れる接続の数（共有の MySQL の接続枠を 1 サイトで使い切らない） */
export const DB_MAX_USER_CONNECTIONS = 30;
export const MYSQL_IMAGE = "mysql:8.4";

/**
 * サイトのコンテナ（app・web・scheduler）が書ける、メモリの上の場所の大きさ。根は読むだけ。
 * どれもコンテナのメモリの上限（768MB）にも数えられる
 */
export const SITE_TMPFS = {
  /** /tmp（アップロードの途中・nginx の一時ファイル・PHP の一時ファイル） */
  tmp: "64m",
  /** /dev/shm */
  shm: "16m",
  /** PHP-FPM のソケットの置き場所（app と web で分け合う・ソケット 1 つだけ） */
  run: "1m",
} as const;

/** サーバーが取る夜のバックアップの既定（vps.backup で変えられる） */
export const BACKUP_DEFAULTS = {
  /** 毎日の時刻（UTC）。日本時間の 03:00 */
  at: "18:00",
  keep: 7,
  maxMB: 2048,
} as const;
/** vps.backup.at の形（"HH:MM"・UTC） */
export const BACKUP_AT_RE = /^([01][0-9]|2[0-3]):[0-5][0-9]$/;
/** バックアップのコンテナの上限（サイトのコードは動かさない。mysqldump・tar・gzip だけ） */
export const BACKUP_LIMITS = {
  memory: "512m",
  cpus: "0.5",
  pids: 64,
} as const;
/**
 * 最後の成功からこれを超えたら「止まっている」（1 日＋取る時間＋余裕・アプリの
 * AutoBackupStatus::STALE_AFTER_HOURS と同じ）
 */
export const BACKUP_STALE_HOURS = 30;
/** 始めてからこれを超えて終わりの記録が無ければ「途中で止まった」（台本の時間の上限は DB・ファイル 1 時間ずつ） */
export const BACKUP_RUNNING_HOURS = 3;

/**
 * サイト名。シェル・Caddy・MySQL の名前にそのまま入るので、**ここで形を絞る**。
 * 英小文字で始まり、英小文字・数字・ハイフン、2〜28 文字、ハイフンで終わらない。
 * （MySQL の利用者名は 32 文字まで。頭に `sgk-` を付けるので 28 文字）
 *
 * `_` と `%` を許さないのは、MySQL の GRANT で DB 名の `_`・`%` が
 * **ワイルドカードになる**ため（`shop_a` の権限が `shopxa` にも効く）。
 */
export const SITE_NAME_RE = /^[a-z][a-z0-9-]{0,26}[a-z0-9]$/;

/** Caddy のサイトのアドレスに入るホスト名。英小文字・数字・ハイフン・ドット、ドットを 1 つ以上 */
export const HOSTNAME_RE =
  /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/** サイトの DB 名・利用者名の頭 */
export const DB_PREFIX = "sgk-";

/** 1 行の印。ほかのサイトが同じドメインを使っていないかを grep で見るのに使う */
export const DOMAIN_MARK = "# agentcms-domain:";

export interface VpsNames {
  root: string;
  site: string;
  domain: string;
  appDir: string;
  appContainer: string;
  webContainer: string;
  schedulerContainer: string;
  /**
   * サイトの web と Caddy だけが入る Docker ネットワーク（外へ出られない）。
   * ほかのサイトのコンテナから、このサイトの web へ直接届かない（送信元 IP の偽装もできない）
   */
  webNetwork: string;
  /**
   * MySQL の DB 名と利用者名（`sgk-<サイト名>`）。
   * 頭の `sgk-` で `mysql`・`sys` などの DB と重ならない。`_`・`%` を含まないので
   * GRANT のワイルドカードにならない（バッククォートで囲んで使う）
   */
  dbName: string;
  dbUser: string;
  tls: "auto" | "internal";
  /** サーバーだけが触る物の置き場所（サイトのコンテナには見せない） */
  controlDir: string;
  /** サーバーが取る夜のバックアップのコンテナ（サイトのコードは動かさない） */
  backupContainer: string;
  /** 夜のバックアップの設定（既定を埋めたもの） */
  backup: { at: string; keep: number; maxBytes: number };
}

export function vpsNames(cfg: DeployTargetConfig): VpsNames {
  const vps = cfg.vps;
  if (!vps)
    throw new Error("vps の設定がありません（provider: vps のときだけ呼ぶ）");
  const root = vps.root ?? VPS_DEFAULT_ROOT;
  const site = vps.site;
  const db = `${DB_PREFIX}${site}`;
  return {
    root,
    site,
    domain: new URL(cfg.app.url).hostname,
    appDir: `${root}/sites/${site}`,
    appContainer: `agentcms-${site}-app`,
    webContainer: `agentcms-${site}-web`,
    schedulerContainer: `agentcms-${site}-scheduler`,
    webNetwork: `agentcms-site-${site}-web`,
    dbName: db,
    dbUser: db,
    tls: vps.tls ?? "auto",
    controlDir: `${root}/control/${site}`,
    backupContainer: `agentcms-${site}-backup`,
    backup: {
      at: vps.backup?.at ?? BACKUP_DEFAULTS.at,
      keep: vps.backup?.keep ?? BACKUP_DEFAULTS.keep,
      maxBytes: (vps.backup?.maxMB ?? BACKUP_DEFAULTS.maxMB) * 1048576,
    },
  };
}

/**
 * サーバーに 1 つの Caddy と MySQL。MySQL はホストへポートを出さない。
 *
 * **Caddy にはサイトのディレクトリを見せない。** Caddy はサイトごとの web（nginx）へ
 * 転送するだけにする。Caddy に全サイトのディレクトリを見せると、あるサイトの中に
 * 置かれた symlink を辿って、別のサイトの `.env` や設定のキャッシュを配信できてしまう
 * （Caddy の root は sandbox ではない — 公式の file_server の説明）。
 *
 * 置き場所（root）はラベルに残す。edge は 1 台に 1 つなので、別の root の設定から
 * 触らせないため（doctor と setup が見る）。
 */
export function edgeCompose(root: string): string {
  return `# SiteGrowKit VPS edge（agentcms deploy setup が管理する。手で変えると、キットの版が上がったときの setup で置き換わる）
# Caddy（HTTPS の証明書を自動で取って更新し、サイトごとの web へ転送する）と MySQL（サイトごとに DB と利用者を分ける）。
name: agentcms-edge
services:
  caddy:
    image: ${CADDY_IMAGE}
    container_name: ${CADDY_CONTAINER}
    restart: unless-stopped
    labels:
      agentcms.root: "${root}"
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./sites:/etc/caddy/sites:ro
      - caddy-data:/data
      - caddy-config:/config
    networks: [${EDGE_NETWORK}]
  mysql:
    image: ${MYSQL_IMAGE}
    container_name: ${MYSQL_CONTAINER}
    restart: unless-stopped
    environment:
      # 値そのものではなくファイルの場所だけを渡す（docker inspect に値が出ない）
      MYSQL_ROOT_PASSWORD_FILE: /run/agentcms/mysql-root
    volumes:
      - ./secrets/mysql-root:/run/agentcms/mysql-root:ro
      - mysql-data:/var/lib/mysql
    networks: [${EDGE_NETWORK}]
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 --silent"]
      interval: 5s
      timeout: 3s
      retries: 60
networks:
  ${EDGE_NETWORK}:
    name: ${EDGE_NETWORK}
volumes:
  caddy-data:
  caddy-config:
  mysql-data:
`;
}

export function edgeCaddyfile(): string {
  return `# SiteGrowKit VPS edge — サイトごとの設定は sites/<サイト名>.caddy（agentcms deploy が書く）
import sites/*.caddy
`;
}

/**
 * サイト 1 つ分の Caddy の設定。**転送だけ**（証明書と HTTPS は Caddy、配信と PHP はサイトの web）。
 * Caddy は X-Forwarded-For に利用者の IP を足して渡す（サイトの web がそれを読む）。
 */
export function siteCaddy(n: VpsNames): string {
  return `# SiteGrowKit サイト "${n.site}"（agentcms deploy が書く。手で変えても次の deploy で戻る）
${DOMAIN_MARK} ${n.domain}
${n.domain} {
${n.tls === "internal" ? "\ttls internal\n" : ""}\tencode zstd gzip
\treverse_proxy ${n.webContainer}:${WEB_PORT}
}
`;
}

/**
 * サイトの web（nginx）の設定。サイトのコンテナの中だけで動くので、**このサイトの
 * ディレクトリしか見えない**（別のサイトを指す symlink を置かれても辿れない）。
 *
 * - `$realpath_root`：`current` の symlink を実際の release のパスへ解いてから PHP へ渡す。
 *   解かないと全 release が同じパスに見え、OPcache が古い release のコードを返し続ける
 *   （Xserver の entry stub が `realpath()` しているのと同じ理由・ADR-0008）
 * - PHP-FPM へは control/<site>/run の Unix ソケットでつなぐ（TCP では待たせない。
 *   同じ Docker ネットワークの別のサイトから FastCGI を直接送れないように）
 * - 前に立つのは Caddy の HTTPS だけなので、PHP には HTTPS・443 と伝える
 * - 利用者の IP は Caddy が付ける X-Forwarded-For から読む（ログインの回数制限が
 *   全員同じ IP にならないように）。信じるのは Docker の内側の宛先だけ
 */
export function siteNginx(n: VpsNames): string {
  return `# SiteGrowKit サイト "${n.site}" の web（agentcms deploy が書く。手で変えても次の deploy で戻る）
server {
    listen ${WEB_PORT};
    server_name _;
    root ${n.appDir}/current/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    client_max_body_size 25m;

    set_real_ip_from 10.0.0.0/8;
    set_real_ip_from 172.16.0.0/12;
    set_real_ip_from 192.168.0.0/16;
    real_ip_header X-Forwarded-For;
    real_ip_recursive on;

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \\.php$ {
        try_files $uri =404;
        fastcgi_pass unix:${FPM_SOCKET_IN_CONTAINER};
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_param SERVER_PORT 443;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
    }

    location ~ /\\.(?!well-known) {
        deny all;
        return 404;
    }
}
`;
}

/**
 * サイト 1 つ分の PHP-FPM・web・scheduler と、サーバーの夜のバックアップ（backup）。
 *
 * - サイトのディレクトリを**同じ絶対パス**で入れる（`current` などの symlink がそのまま解ける）
 * - **ssh の利用者と同じ uid:gid** で動かす（`shared/.env` は 600 で、その利用者のもの）
 * - `working_dir` を `current` にしない：初回は `current` がまだ無く、Docker が
 *   **本物のディレクトリとして作ってしまう**（そうなると `ln -sfn` が中に symlink を作る）
 * - scheduler は毎分 `current` へ入り直す。`schedule:work` のように 1 度入ったまま
 *   動き続けると、切り替えたあとも古い release で動く
 * - web は読むだけで入れる
 * - 秘密は環境変数で渡さない（`docker inspect` に出る）。アプリは `shared/.env` を読む
 * - **根は読むだけ**（read_only）。書けるのはサイトの置き場所と、大きさを決めた tmpfs（/tmp・/dev/shm・
 *   ソケットの置き場所）だけ。コンテナの書ける層（Docker の置き場所＝MySQL と同じディスク）や
 *   control（サーバーのディスク）に書かせない
 * - backup はサイトのコードを動かさない（MySQL の公式イメージの mysqldump・tar・gzip だけ）。
 *   サイトの置き場所は読むだけ、書くのは control/<site>/backup（サイトのコンテナには入っていない）
 */
export function siteCompose(
  n: VpsNames,
  ids: { uid: string; gid: string },
): string {
  const logging = `    logging:
      driver: json-file
      options:
        max-size: "${SITE_LIMITS.logMaxSize}"
        max-file: "${SITE_LIMITS.logMaxFile}"`;
  const base = `    user: "${ids.uid}:${ids.gid}"
    working_dir: ${n.appDir}
    restart: unless-stopped
    read_only: true
    tmpfs:
      - /tmp:size=${SITE_TMPFS.tmp},mode=1777,nosuid,nodev,noexec
    shm_size: "${SITE_TMPFS.shm}"
    mem_limit: ${SITE_LIMITS.memory}
    memswap_limit: ${SITE_LIMITS.memory}
    cpus: ${SITE_LIMITS.cpus}
    pids_limit: ${SITE_LIMITS.pids}
${logging}`;
  // app・scheduler は MySQL と外へ（メール・Web Push）。web はこのサイトの web 用のネットワークだけ
  const common = `${base}
    networks: [${EDGE_NETWORK}]`;
  const webCommon = `${base}
    networks: [web]`;
  return `# SiteGrowKit サイト "${n.site}"（agentcms deploy が書く。手で変えても次の deploy で戻る）
name: agentcms-site-${n.site}
services:
  app:
    container_name: ${n.appContainer}
    image: ${PHP_IMAGE}
${common}
    volumes:
      - ${n.appDir}:${n.appDir}
      # PHP-FPM はこのソケットだけで待つ。置き場所は大きさを決めた tmpfs（app と web だけが入れる）
      - run:/run/agentcms
  web:
    container_name: ${n.webContainer}
    image: ${NGINX_IMAGE}
${webCommon}
    depends_on: [app]
    volumes:
      - ${n.appDir}:${n.appDir}:ro
      - run:/run/agentcms
      - ${n.controlDir}/nginx.conf:/etc/nginx/conf.d/default.conf:ro
  scheduler:
    container_name: ${n.schedulerContainer}
    image: ${PHP_IMAGE}
${common}
    volumes:
      - ${n.appDir}:${n.appDir}
    command:
      - sh
      - -c
      - 'while :; do if [ -f current/artisan ]; then (cd current && php artisan schedule:run --no-interaction); fi; sleep $$((60 - $$(date +%s) % 60)); done'
  backup:
    container_name: ${n.backupContainer}
    image: ${MYSQL_IMAGE}
    user: "${ids.uid}:${ids.gid}"
    restart: unless-stopped
    init: true
    read_only: true
    tmpfs:
      - /tmp:size=16m,mode=1777,nosuid,nodev,noexec
    cap_drop: [ALL]
    security_opt: ["no-new-privileges:true"]
    mem_limit: ${BACKUP_LIMITS.memory}
    memswap_limit: ${BACKUP_LIMITS.memory}
    cpus: ${BACKUP_LIMITS.cpus}
    pids_limit: ${BACKUP_LIMITS.pids}
${logging}
    networks: [${EDGE_NETWORK}]
    entrypoint: ["bash", "/agentcms/backup.sh"]
    # 秘密ではない設定だけ（DB のパスワードはファイルで渡す）
    environment:
      AGENTCMS_BACKUP_AT: "${n.backup.at}"
      AGENTCMS_BACKUP_KEEP: "${n.backup.keep}"
      AGENTCMS_BACKUP_MAX_BYTES: "${n.backup.maxBytes}"
    volumes:
      - ${n.appDir}:/site:ro
      - ${n.controlDir}/backup.sh:/agentcms/backup.sh:ro
      - ${n.controlDir}/db-password:/agentcms/db-password:ro
      - ${n.controlDir}/backup:/agentcms/backup
networks:
  ${EDGE_NETWORK}:
    external: true
  web:
    name: ${n.webNetwork}
    internal: true
volumes:
  run:
    driver: local
    driver_opts:
      type: tmpfs
      device: tmpfs
      o: "size=${SITE_TMPFS.run},mode=0700,uid=${ids.uid},gid=${ids.gid},nosuid,nodev,noexec"
`;
}

/**
 * サーバーが取る夜のバックアップの台本（backup のコンテナの中で、サイトと同じ利用者で動く）。
 *
 * - 取るのは DB（このサイトの利用者で mysqldump）と shared/storage/app（tar・リンクは辿らない）。
 *   アプリが取る zip（storage/app/private/backups）と、その記録（backup-status）は入れない
 * - 書くのは /agentcms/backup（= control/<site>/backup・サイトのコンテナには入っていない）だけ。
 *   作りかけは `.work-<id>` に置き、確かめてから名前を付ける。落ちたら消す
 * - 1 つのファイルが上限（AGENTCMS_BACKUP_MAX_BYTES）を超えたら、取らずに失敗と書く。
 *   置き場所の空きが上限の 2 倍より少なければ始めない（サーバーのディスクを埋めない）
 * - 成否は status.json に**サーバーの決めた言葉だけ**で書く（mysqldump・tar の出力は書かない。
 *   サイトの表の名前やファイル名が入るので）。理由の詳しいことはこのコンテナのログに出す
 * - `bash backup.sh now` で 1 回だけ取る（手で・試験で）。引数なしは毎日 AGENTCMS_BACKUP_AT（UTC）に取り、
 *   記録がまだ無ければ立ち上がってすぐ 1 回取る
 */
export function backupScript(n: VpsNames): string {
  return `#!/bin/bash
# SiteGrowKit サイト "${n.site}" の夜のバックアップ（agentcms deploy が書く。手で変えても次の deploy で戻る）
set -uo pipefail
umask 077
# 置き場所はコンテナの中の決まった場所（変えられるのは単体の試験のため）
B="\${AGENTCMS_BACKUP_DIR:-/agentcms/backup}"
SITE="\${AGENTCMS_BACKUP_SITE:-/site}"
PWFILE="\${AGENTCMS_BACKUP_PWFILE:-/agentcms/db-password}"
LOCK="\${AGENTCMS_BACKUP_LOCK:-/tmp/backup.lock}"
DB=${shellWord(n.dbName)}
DBUSER=${shellWord(n.dbUser)}
DBHOST=${MYSQL_CONTAINER}
AT="\${AGENTCMS_BACKUP_AT:-${BACKUP_DEFAULTS.at}}"
KEEP="\${AGENTCMS_BACKUP_KEEP:-${BACKUP_DEFAULTS.keep}}"
MAX="\${AGENTCMS_BACKUP_MAX_BYTES:-${BACKUP_DEFAULTS.maxMB * 1048576}}"
[[ "$AT" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || AT=${BACKUP_DEFAULTS.at}
[[ "$KEEP" =~ ^[1-9][0-9]?$ ]] || KEEP=${BACKUP_DEFAULTS.keep}
[[ "$MAX" =~ ^[1-9][0-9]{0,12}$ ]] || MAX=${BACKUP_DEFAULTS.maxMB * 1048576}
size() { echo $(( $(wc -c < "$1") )); }
log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" >&2; }
num() { sed -n "s/.*\\"$1\\":\\([0-9][0-9]*\\).*/\\1/p" "$B/status.json" 2>/dev/null | head -1; }
str() { sed -n "s/.*\\"$1\\":\\"\\([0-9]\\{14\\}\\)\\".*/\\1/p" "$B/status.json" 2>/dev/null | head -1; }
# 前の回の「最後の成功」を引き継ぐ（この台本だけが書くファイル）
load_prev() { last_at=$(num lastSuccessAt); last_id=$(str lastSuccessId); last_db=$(num dbBytes); last_files=$(num filesBytes); }
jn() { if [ -n "$1" ]; then printf '%s' "$1"; else printf null; fi; }
js() { if [ -n "$1" ]; then printf '"%s"' "$1"; else printf null; fi; }
write_status() { # state error id started finished
  printf '{"version":1,"state":"%s","error":%s,"id":"%s","startedAt":%s,"finishedAt":%s,"lastSuccessAt":%s,"lastSuccessId":%s,"dbBytes":%s,"filesBytes":%s}\\n' \\
    "$1" "$(js "$2")" "$3" "$4" "$(jn "$5")" "$(jn "$last_at")" "$(js "$last_id")" "$(jn "$last_db")" "$(jn "$last_files")" > "$B/.status.tmp" \\
    && mv -f "$B/.status.tmp" "$B/status.json"
}
# 1 回分を .work-<id> に作る。うまくいけば何も出さず、だめなら理由の言葉を 1 つ出す
take() {
  local id="$1" w="$B/.work-$1" pw avail rc
  mkdir "$w" || { echo write; return; }
  avail=$(df -Pk "$B" 2>/dev/null | awk 'NR==2{print $4}')
  [[ "$avail" =~ ^[0-9]+$ ]] && [ $((avail * 1024)) -ge $((MAX * 2)) ] || { echo no-space; return; }
  pw=$(head -c 256 "$PWFILE" 2>/dev/null | tr -d '\\n')
  [ -n "$pw" ] || { echo no-db-password; return; }
  if ! MYSQL_PWD="$pw" timeout 60 mysql -h "$DBHOST" -u "$DBUSER" -N -e 'SELECT 1' "$DB" >/dev/null 2>"$w/err"; then
    log "DB に入れません: $(head -c 300 "$w/err")"
    if grep -q 'ERROR 1045' "$w/err"; then echo db-auth; else echo db-connect; fi
    return
  fi
  MYSQL_PWD="$pw" timeout 3600 mysqldump --single-transaction --no-tablespaces --skip-comments -h "$DBHOST" -u "$DBUSER" "$DB" 2>"$w/err" | gzip | head -c $((MAX + 1)) > "$w/db.sql.gz"
  rc=("\${PIPESTATUS[@]}")
  if [ "$(size "$w/db.sql.gz")" -gt "$MAX" ]; then echo too-large; return; fi
  if [ "\${rc[0]}" != 0 ] || [ "\${rc[1]}" != 0 ]; then log "mysqldump: $(head -c 300 "$w/err")"; echo db-dump; return; fi
  gzip -t "$w/db.sql.gz" 2>/dev/null && [ "$(size "$w/db.sql.gz")" -ge 100 ] || { echo db-dump; return; }
  # ファイル。置き場所の中のリンクは辿らない（リンクのまま写す）。途中がリンクなら取らない
  for p in "$SITE/shared" "$SITE/shared/storage" "$SITE/shared/storage/app"; do
    if [ -L "$p" ]; then echo files-link; return; fi
  done
  [ -d "$SITE/shared/storage/app" ] || { echo files-missing; return; }
  timeout 3600 tar -C "$SITE/shared" --exclude=storage/app/private/backups --exclude=storage/app/private/backup-status -cf - storage/app 2>"$w/err" | gzip | head -c $((MAX + 1)) > "$w/files.tar.gz"
  rc=("\${PIPESTATUS[@]}")
  if [ "$(size "$w/files.tar.gz")" -gt "$MAX" ]; then echo too-large; return; fi
  # tar の 1 は「読んでいる間に変わったファイルがある」（それでも取れている）
  if [ "\${rc[0]}" -gt 1 ] || [ "\${rc[1]}" != 0 ]; then log "tar: $(head -c 300 "$w/err")"; echo files; return; fi
  gzip -t "$w/files.tar.gz" 2>/dev/null || { echo files; return; }
  ( cd "$w" && sha256sum db.sql.gz files.tar.gz | sed "s/  /  $id-/" > sha256 ) || { echo write; return; }
}
run_once() {
  if ! mkdir "$LOCK" 2>/dev/null; then log "別の回が動いています"; return 3; fi
  local id started err db files
  id=$(date -u +%Y%m%d%H%M%S); started=$(date -u +%s)
  load_prev
  rm -rf "$B"/.work-*
  write_status running "" "$id" "$started" "" || { rmdir "$LOCK"; log "記録を書けません"; return 1; }
  err=$(take "$id")
  if [ -z "$err" ]; then
    db=$(size "$B/.work-$id/db.sql.gz"); files=$(size "$B/.work-$id/files.tar.gz")
    if mv "$B/.work-$id/db.sql.gz" "$B/$id-db.sql.gz" && mv "$B/.work-$id/files.tar.gz" "$B/$id-files.tar.gz" && mv "$B/.work-$id/sha256" "$B/$id.sha256"; then
      last_at=$(date -u +%s); last_id=$id; last_db=$db; last_files=$files
    else
      err=write
    fi
  fi
  rm -rf "$B/.work-$id"
  # 古い世代を片付ける（新しい順に KEEP 個を残す）
  ls -1 "$B" | grep -E '^[0-9]{14}-db\\.sql\\.gz$' | sort -r | tail -n +$((KEEP + 1)) | cut -c1-14 | while read -r old; do
    rm -f "$B/$old-db.sql.gz" "$B/$old-files.tar.gz" "$B/$old.sha256"
  done
  if [ -n "$err" ]; then
    write_status failed "$err" "$id" "$started" "$(date -u +%s)"
    log "失敗: $err（$id）"
    rmdir "$LOCK"
    return 1
  fi
  write_status ok "" "$id" "$started" "$(date -u +%s)"
  log "取りました: $id（DB $db bytes・ファイル $files bytes）"
  rmdir "$LOCK"
  return 0
}
if [ "\${1:-}" = now ]; then run_once; exit $?; fi
trap 'exit 0' TERM INT
[ -s "$B/status.json" ] || run_once || true
while :; do
  now=$(date -u +%s)
  next=$(date -u -d "$(date -u +%F) $AT" +%s)
  [ "$next" -gt "$now" ] || next=$((next + 86400))
  sleep $((next - now)) &
  wait $!
  run_once || true
done
`;
}

/** 台本に入れる名前（形を確かめた物だけ。引用符で包む） */
function shellWord(v: string): string {
  if (!/^[a-z0-9-]+$/.test(v)) throw new Error(`名前の形が違います: ${v}`);
  return `'${v}'`;
}

/**
 * 各サイトの PHP のイメージ（サーバーの上で build する）。
 * 拡張は手元の開発用（template/docker/php/Dockerfile）と同じものにそろえる。
 */
export function phpDockerfile(): string {
  return `FROM php:8.5-fpm-alpine
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd pdo_mysql intl zip bcmath opcache
RUN { \\
      echo 'memory_limit = 256M'; \\
      echo 'upload_max_filesize = 20M'; \\
      echo 'post_max_size = 25M'; \\
      echo 'opcache.enable = 1'; \\
      echo 'opcache.validate_timestamps = 1'; \\
      echo 'expose_php = Off'; \\
      echo 'display_errors = Off'; \\
      echo 'log_errors = On'; \\
    } > /usr/local/etc/php/conf.d/agentcms.ini
# TCP の 9000 ではなく Unix ソケットで待つ（公式イメージの zz-docker.conf の listen = 9000 を後から上書き）
RUN { \\
      echo '[www]'; \\
      echo 'listen = ${FPM_SOCKET_IN_CONTAINER}'; \\
      echo 'listen.mode = 0660'; \\
    } > /usr/local/etc/php-fpm.d/zzz-agentcms.conf
LABEL org.opencontainers.image.title="SiteGrowKit PHP-FPM"
`;
}

/** `shared/.env` の中で、サーバーの上で生成した値に差し替える印（印そのものは秘密ではない） */
export const APP_KEY_PLACEHOLDER = "__AGENTCMS_APP_KEY__";
export const DB_PASSWORD_PLACEHOLDER = "__AGENTCMS_DB_PASSWORD__";
