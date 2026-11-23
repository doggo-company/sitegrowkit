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
 *   edge/                      … サーバーに 1 つ。Caddy と MySQL
 *     compose.yml  Caddyfile
 *     sites/<site>.caddy       … サイトごとの行（agentcms deploy が書く）
 *     secrets/mysql-root       … MySQL の root パスワード（サーバーで生成・600）
 *   sites/<site>/              … サイトごと（= remote.appDir）
 *     compose.yml              … このサイトの PHP-FPM と scheduler
 *     releases/ shared/ backups/ current -> releases/<id>
 * ```
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
export const CADDY_IMAGE = "caddy:2.10-alpine";
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
  schedulerContainer: string;
  /**
   * MySQL の DB 名と利用者名（`sgk-<サイト名>`）。
   * 頭の `sgk-` で `mysql`・`sys` などの DB と重ならない。`_`・`%` を含まないので
   * GRANT のワイルドカードにならない（バッククォートで囲んで使う）
   */
  dbName: string;
  dbUser: string;
  tls: "auto" | "internal";
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
    schedulerContainer: `agentcms-${site}-scheduler`,
    dbName: db,
    dbUser: db,
    tls: vps.tls ?? "auto",
  };
}

/** サーバーに 1 つの Caddy と MySQL。MySQL はホストへポートを出さない */
export function edgeCompose(root: string): string {
  return `# SiteGrowKit VPS edge（agentcms deploy setup が作成。無いときだけ書く）
# Caddy（HTTPS の証明書を自動で取って更新する）と MySQL（サイトごとに DB と利用者を分ける）。
name: agentcms-edge
services:
  caddy:
    image: ${CADDY_IMAGE}
    container_name: ${CADDY_CONTAINER}
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./sites:/etc/caddy/sites:ro
      - ${root}/sites:${root}/sites:ro
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
 * サイト 1 つ分の Caddy の設定。
 *
 * - `resolve_root_symlink`：`current` の symlink を実際の release のパスへ解いてから PHP へ渡す。
 *   解かないと全 release が同じパスに見え、OPcache が古い release のコードを返し続ける
 *   （Xserver の entry stub が `realpath()` しているのと同じ理由・ADR-0008）
 * - `.well-known` 以外のドットファイルは 404
 * - PHP-FPM へは**サイトの置き場所の中の Unix ソケット**でつなぐ。TCP（9000）で待たせると、
 *   同じ Docker ネットワークにいる別のサイトのコンテナから FastCGI を直接送れてしまう
 *   （PHP の公式の注意。接続元を絞らない FastCGI は任意のコードを動かされる）
 */
export function siteCaddy(n: VpsNames): string {
  return `# SiteGrowKit サイト "${n.site}"（agentcms deploy が書く。手で変えても次の deploy で戻る）
${DOMAIN_MARK} ${n.domain}
${n.domain} {
${n.tls === "internal" ? "\ttls internal\n" : ""}\troot * ${n.appDir}/current/public
\tencode zstd gzip
\t@hidden {
\t\tpath */.*
\t\tnot path /.well-known/*
\t}
\trespond @hidden 404
\tphp_fastcgi unix/${n.appDir}/run/php-fpm.sock {
\t\tresolve_root_symlink
\t}
\tfile_server
}
`;
}

/**
 * サイト 1 つ分の PHP-FPM と scheduler。
 *
 * - サイトのディレクトリを**同じ絶対パス**で入れる（`current` などの symlink がそのまま解ける）
 * - **ssh の利用者と同じ uid:gid** で動かす（`shared/.env` は 600 で、その利用者のもの）
 * - `working_dir` を `current` にしない：初回は `current` がまだ無く、Docker が
 *   **本物のディレクトリとして作ってしまう**（そうなると `ln -sfn` が中に symlink を作る）
 * - scheduler は毎分 `current` へ入り直す。`schedule:work` のように 1 度入ったまま
 *   動き続けると、切り替えたあとも古い release で動く
 * - 秘密は環境変数で渡さない（`docker inspect` に出る）。アプリは `shared/.env` を読む
 */
export function siteCompose(
  n: VpsNames,
  ids: { uid: string; gid: string },
): string {
  const common = `    image: ${PHP_IMAGE}
    user: "${ids.uid}:${ids.gid}"
    working_dir: ${n.appDir}
    restart: unless-stopped
    networks: [${EDGE_NETWORK}]`;
  return `# SiteGrowKit サイト "${n.site}"（agentcms deploy が書く。手で変えても次の deploy で戻る）
name: agentcms-site-${n.site}
services:
  app:
    container_name: ${n.appContainer}
${common}
    volumes:
      - ${n.appDir}:${n.appDir}
      # PHP-FPM はこのソケットだけで待つ（Caddy がサイトの置き場所越しにつなぐ）
      - ${n.appDir}/run:/run/agentcms
  scheduler:
    container_name: ${n.schedulerContainer}
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
`;
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
