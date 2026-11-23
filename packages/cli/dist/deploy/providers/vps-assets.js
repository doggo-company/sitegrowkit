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
 *   control/<site>/            … サイトごとの、サーバーだけが触る物（コンテナには見せない・700）
 *     compose.yml nginx.conf   … このサイトの PHP-FPM・web（nginx）・scheduler
 *     run/php-fpm.sock         … PHP-FPM はここでだけ待つ（TCP では待たない）
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
};
/** サイトの DB 利用者 1 人が同時に張れる接続の数（共有の MySQL の接続枠を 1 サイトで使い切らない） */
export const DB_MAX_USER_CONNECTIONS = 30;
export const MYSQL_IMAGE = "mysql:8.4";
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
export const HOSTNAME_RE = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/;
/** サイトの DB 名・利用者名の頭 */
export const DB_PREFIX = "sgk-";
/** 1 行の印。ほかのサイトが同じドメインを使っていないかを grep で見るのに使う */
export const DOMAIN_MARK = "# agentcms-domain:";
export function vpsNames(cfg) {
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
export function edgeCompose(root) {
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
export function edgeCaddyfile() {
    return `# SiteGrowKit VPS edge — サイトごとの設定は sites/<サイト名>.caddy（agentcms deploy が書く）
import sites/*.caddy
`;
}
/**
 * サイト 1 つ分の Caddy の設定。**転送だけ**（証明書と HTTPS は Caddy、配信と PHP はサイトの web）。
 * Caddy は X-Forwarded-For に利用者の IP を足して渡す（サイトの web がそれを読む）。
 */
export function siteCaddy(n) {
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
export function siteNginx(n) {
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
 * サイト 1 つ分の PHP-FPM・web・scheduler。
 *
 * - サイトのディレクトリを**同じ絶対パス**で入れる（`current` などの symlink がそのまま解ける）
 * - **ssh の利用者と同じ uid:gid** で動かす（`shared/.env` は 600 で、その利用者のもの）
 * - `working_dir` を `current` にしない：初回は `current` がまだ無く、Docker が
 *   **本物のディレクトリとして作ってしまう**（そうなると `ln -sfn` が中に symlink を作る）
 * - scheduler は毎分 `current` へ入り直す。`schedule:work` のように 1 度入ったまま
 *   動き続けると、切り替えたあとも古い release で動く
 * - web は読むだけで入れる
 * - 秘密は環境変数で渡さない（`docker inspect` に出る）。アプリは `shared/.env` を読む
 */
export function siteCompose(n, ids) {
    const base = `    user: "${ids.uid}:${ids.gid}"
    working_dir: ${n.appDir}
    restart: unless-stopped
    mem_limit: ${SITE_LIMITS.memory}
    memswap_limit: ${SITE_LIMITS.memory}
    cpus: ${SITE_LIMITS.cpus}
    pids_limit: ${SITE_LIMITS.pids}
    logging:
      driver: json-file
      options:
        max-size: "${SITE_LIMITS.logMaxSize}"
        max-file: "${SITE_LIMITS.logMaxFile}"`;
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
      # PHP-FPM はこのソケットだけで待つ。置き場所はサイトから差し替えられない control の中
      - ${n.controlDir}/run:/run/agentcms
  web:
    container_name: ${n.webContainer}
    image: ${NGINX_IMAGE}
${webCommon}
    depends_on: [app]
    volumes:
      - ${n.appDir}:${n.appDir}:ro
      - ${n.controlDir}/run:/run/agentcms
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
networks:
  ${EDGE_NETWORK}:
    external: true
  web:
    name: ${n.webNetwork}
    internal: true
`;
}
/**
 * 各サイトの PHP のイメージ（サーバーの上で build する）。
 * 拡張は手元の開発用（template/docker/php/Dockerfile）と同じものにそろえる。
 */
export function phpDockerfile() {
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
