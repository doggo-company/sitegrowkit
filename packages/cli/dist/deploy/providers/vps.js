import { shq } from "../executor.js";
import { APP_KEY_PLACEHOLDER, CADDY_CONTAINER, DB_PASSWORD_PLACEHOLDER, DOMAIN_MARK, edgeCaddyfile, edgeCompose, MYSQL_CONTAINER, PHP_IMAGE, phpDockerfile, siteCaddy, siteCompose, vpsNames, } from "./vps-assets.js";
import { minimalEnv } from "./xserver-setup.js";
/**
 * provider "vps"（Docker の入った Linux VPS 1 台・ADR-0021）。
 *
 * サーバーへは**ssh 越しのシェルだけ**で触る（RemoteExecutor）。秘密の扱い:
 *
 * - DB パスワード・APP_KEY・MySQL の root パスワードは**サーバーの上で生成**し、
 *   サーバーの外（手元・ログ・コマンド行・環境変数）へ出さない
 * - 値はパイプ（標準入力）でだけ渡す。コマンドの引数に載せると `ps` で見える
 */
const ok = (step, detail) => ({
    step,
    ok: true,
    detail,
});
const ng = (step, detail) => ({
    step,
    ok: false,
    detail,
});
/** 長いと落ちる処理の上限（ミリ秒）。届いたら「時間切れ」と読める形で返す */
export const TIMEOUTS = {
    imageBuild: 30 * 60_000,
    composeUp: 10 * 60_000,
    mysqlReady: 4 * 60_000,
    default: 120_000,
};
/**
 * bash の台本をサーバーの一時ファイルに置いて流し、消す。
 * 台本は heredoc（引用符つき）で送るので、中の `$` はサーバー側で展開される。
 * **台本に秘密を書かない**（コマンド行に載る）。
 */
export async function runScript(exec, script, timeoutMs = TIMEOUTS.default) {
    const cmd = `f=$(mktemp) && cat > "$f" << 'AGENTCMS_EOS'\n${script}\nAGENTCMS_EOS\n` +
        `bash "$f"; rc=$?; rm -f "$f"; exit $rc`;
    const r = await exec.exec(cmd, { timeoutMs });
    // SshExecutor は時間切れで SIGTERM → 終了コード 1 になり、理由が残らない
    return r;
}
function tail(r, n = 600) {
    return `${r.stdout}\n${r.stderr}`.trim().slice(-n);
}
// ---------------------------------------------------------------- doctor
/** `agentcms deploy doctor`（vps）。読むだけで何も変えない */
export async function vpsDoctor(exec, cfg) {
    const n = vpsNames(cfg);
    const checks = [];
    const add = (c) => checks.push(c);
    const done = (currentRelease = null) => ({
        capabilities: {
            checks,
            phpBin: "php",
            symlinkOk: true,
            currentRelease,
        },
    });
    const ping = await exec.exec("echo agentcms-ssh-ok");
    if (!ping.stdout.includes("agentcms-ssh-ok")) {
        add({
            key: "ssh",
            name: "SSH 接続",
            status: "FAIL",
            what: `SSH で接続できません（${exec.label()}）`,
            why: ping.stderr || `exit ${ping.code}`,
            fix: "ssh.host / ssh.port / ssh.user / ssh.identityFile を確かめ、一度 `ssh -p <port> <user>@<host>` で入ってホスト鍵を受け入れてください",
        });
        return done();
    }
    add({ key: "ssh", name: "SSH 接続", status: "PASS" });
    const r = await runScript(exec, doctorScript(n));
    const facts = new Map();
    for (const line of r.stdout.split("\n")) {
        const i = line.indexOf("=");
        if (i > 0)
            facts.set(line.slice(0, i), line.slice(i + 1));
    }
    const fact = (k) => facts.get(k) ?? "";
    add(fact("docker")
        ? {
            key: "docker",
            name: "Docker",
            status: "PASS",
            detected: fact("docker"),
        }
        : {
            key: "docker",
            name: "Docker",
            status: "FAIL",
            what: "Docker を使えません",
            why: fact("docker_err") || "docker version が失敗しました",
            fix: `サーバーに Docker Engine を入れ、${cfg.ssh.user} を docker グループへ足してください（docs/deployment/vps.md §2）`,
        });
    add(fact("compose")
        ? {
            key: "compose",
            name: "Docker Compose",
            status: "PASS",
            detected: fact("compose"),
        }
        : {
            key: "compose",
            name: "Docker Compose",
            status: "FAIL",
            what: "docker compose を使えません",
            why: "docker compose version が失敗しました",
            fix: "Docker の compose プラグイン（docker-compose-plugin）を入れてください",
        });
    add(fact("root_writable") === "yes"
        ? { key: "root", name: `置き場所 ${n.root}`, status: "PASS" }
        : {
            key: "root",
            name: `置き場所 ${n.root}`,
            status: "FAIL",
            what: `${n.root} が無いか、${cfg.ssh.user} が書き込めません`,
            why: fact("root_writable") || "未確認",
            fix: `一度だけ root で: sudo install -d -o ${cfg.ssh.user} -g ${cfg.ssh.user} ${n.root}`,
        });
    const edgeUp = fact("caddy") === "true" && fact("mysql") === "true";
    add(edgeUp
        ? { key: "edge", name: "Caddy と MySQL（edge）", status: "PASS" }
        : {
            key: "edge",
            name: "Caddy と MySQL（edge）",
            status: "FAIL",
            what: "Caddy か MySQL のコンテナが動いていません",
            why: `caddy=${fact("caddy") || "無し"} / mysql=${fact("mysql") || "無し"}`,
            fix: `\`agentcms deploy setup -t ${cfg.target}\` を流してください（作るだけ・何度流しても同じ）`,
        });
    add(fact("php_image") === "yes"
        ? {
            key: "php_image",
            name: `PHP のイメージ（${PHP_IMAGE}）`,
            status: "PASS",
        }
        : {
            key: "php_image",
            name: `PHP のイメージ（${PHP_IMAGE}）`,
            status: "FAIL",
            what: "サイトの PHP のイメージがありません",
            why: "まだ build していません",
            fix: `\`agentcms deploy setup -t ${cfg.target}\` を流してください`,
        });
    add(fact("env") === "yes"
        ? { key: "env", name: "shared/.env（APP_KEY あり）", status: "PASS" }
        : {
            key: "env",
            name: "shared/.env（APP_KEY あり）",
            status: "FAIL",
            what: `${n.appDir}/shared/.env が無いか APP_KEY がありません`,
            why: "このサイトの DB と .env をまだ作っていません",
            fix: `\`agentcms deploy setup -t ${cfg.target}\` を流してください（DB パスワードと APP_KEY はサーバーの上で作り、手元へは出しません）`,
        });
    const taken = fact("domain_taken_by");
    add(taken
        ? {
            key: "domain",
            name: `ドメイン ${n.domain}`,
            status: "FAIL",
            what: `ドメイン ${n.domain} は別のサイト「${taken}」が使っています`,
            why: "1 つのドメインは 1 つのサイトにしか向けられません",
            fix: "app.url か、相手のサイトの設定を見直してください",
        }
        : { key: "domain", name: `ドメイン ${n.domain}`, status: "PASS" });
    const owner = fact("site_owner");
    add(owner && owner !== n.domain
        ? {
            key: "site",
            name: `サイト名 ${n.site}`,
            status: "FAIL",
            what: `サイト名「${n.site}」は別のドメイン ${owner} のサイトが使っています`,
            why: "同じサイト名で出すと、そのサイトを上書きします",
            fix: "vps.site を別の名前にしてください",
        }
        : { key: "site", name: `サイト名 ${n.site}`, status: "PASS" });
    return done(fact("current") || null);
}
function doctorScript(n) {
    return `set -u
if v=$(docker version --format '{{.Server.Version}}' 2>&1); then echo "docker=$v"; else echo "docker="; echo "docker_err=$(printf %s "$v" | tail -1)"; fi
echo "compose=$(docker compose version --short 2>/dev/null)"
if [ -d ${shq(n.root)} ] && [ -w ${shq(n.root)} ]; then echo root_writable=yes; elif [ -d ${shq(n.root)} ]; then echo root_writable=書き込めない; else echo root_writable=無い; fi
echo "caddy=$(docker inspect -f '{{.State.Running}}' ${CADDY_CONTAINER} 2>/dev/null)"
echo "mysql=$(docker inspect -f '{{.State.Running}}' ${MYSQL_CONTAINER} 2>/dev/null)"
if docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1; then echo php_image=yes; else echo php_image=no; fi
if grep -q '^APP_KEY=base64:' ${shq(n.appDir + "/shared/.env")} 2>/dev/null; then echo env=yes; else echo env=no; fi
for g in ${shq(n.root + "/edge/sites")}/*.caddy; do
  [ -e "$g" ] || continue
  [ "$(basename "$g" .caddy)" = ${shq(n.site)} ] && continue
  if grep -qxF ${shq(`${DOMAIN_MARK} ${n.domain}`)} "$g"; then echo "domain_taken_by=$(basename "$g" .caddy)"; fi
done
[ -f ${shq(n.appDir + "/.agentcms-site")} ] && echo "site_owner=$(head -1 ${shq(n.appDir + "/.agentcms-site")})"
echo "current=$(readlink ${shq(n.appDir + "/current")} 2>/dev/null)"
exit 0`;
}
/**
 * 同じサーバーへ 2 つの deploy / setup が同時に来たとき、Caddy の行と DB の作成を
 * 順番に流すための鍵（flock があるときだけ。Debian / Ubuntu / Alpine にはある）。
 */
function edgeLock(n) {
    return `if command -v flock >/dev/null 2>&1 && [ -d ${shq(n.root + "/edge")} ]; then
  exec 9>${shq(n.root + "/edge/.agentcms.lock")}
  flock -w 300 9 || { echo "lock-timeout: 別の deploy / setup が 5 分以上終わりません"; exit 7; }
fi`;
}
/**
 * `agentcms deploy setup`（vps）でやること。**作るだけ・何度流しても同じ**。
 * 既にあるものは変えない（edge の compose・Caddyfile・MySQL の root パスワード・
 * サイトの .env を上書きしない）。DB パスワード・APP_KEY は .env が**無いときだけ**作る。
 */
export async function vpsSetupActions(exec, cfg) {
    const n = vpsNames(cfg);
    const probe = await runScript(exec, `set -u
[ -d ${shq(n.root)} ] && [ -w ${shq(n.root)} ] && echo root=yes
[ -f ${shq(n.root + "/edge/compose.yml")} ] && echo edge_files=yes
[ "$(docker inspect -f '{{.State.Running}}' ${CADDY_CONTAINER} 2>/dev/null)" = true ] && [ "$(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>/dev/null)" = healthy ] && echo edge_up=yes
docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1 && echo php_image=yes
[ -f ${shq(n.appDir + "/shared/.env")} ] && echo env=yes
exit 0`);
    if (probe.code !== 0)
        return { actions: [], error: `サーバーを読めませんでした: ${tail(probe)}` };
    const has = (k) => probe.stdout.includes(`${k}=yes`);
    if (!has("root"))
        return {
            actions: [],
            error: `${n.root} が無いか書き込めません。一度だけ root で: sudo install -d -o ${cfg.ssh.user} -g ${cfg.ssh.user} ${n.root}`,
        };
    const actions = [
        {
            key: "edge_files",
            describe: `edge の設定を置く（${n.root}/edge の compose.yml・Caddyfile・MySQL の root パスワードをサーバーで生成）`,
            satisfied: has("edge_files"),
            current: has("edge_files") ? "置いてある（上書きしない）" : "無い",
            timeoutMs: TIMEOUTS.default,
            script: edgeFilesScript(n),
        },
        {
            key: "edge_up",
            describe: "Caddy と MySQL を起動する（docker compose up -d）",
            satisfied: has("edge_up"),
            current: has("edge_up") ? "動いている" : "止まっているか無い",
            timeoutMs: TIMEOUTS.composeUp + TIMEOUTS.mysqlReady,
            script: edgeUpScript(n),
        },
        {
            key: "php_image",
            describe: `サイトの PHP のイメージを build する（${PHP_IMAGE}・初回は数分かかる）`,
            satisfied: has("php_image"),
            current: has("php_image") ? "ある" : "無い",
            timeoutMs: TIMEOUTS.imageBuild,
            script: phpImageScript(),
        },
        {
            key: "site_env",
            describe: `サイト「${n.site}」の DB・利用者と shared/.env を作る（パスワードと APP_KEY はサーバーで生成・表示しない）`,
            satisfied: has("env"),
            current: has("env") ? "ある（上書きしない）" : "無い",
            timeoutMs: TIMEOUTS.default,
            script: siteEnvScript(n, envTemplate(cfg)),
        },
    ];
    return { actions };
}
/** 秘密の代わりに印を入れた .env のひな形（印は秘密ではない） */
export function envTemplate(cfg) {
    const n = vpsNames(cfg);
    return minimalEnv(cfg, APP_KEY_PLACEHOLDER, {
        dbName: n.dbName,
        dbUser: n.dbUser,
        dbPassword: DB_PASSWORD_PLACEHOLDER,
    });
}
function edgeFilesScript(n) {
    const edge = `${n.root}/edge`;
    return `set -euo pipefail
umask 077
mkdir -p ${shq(edge + "/sites")} ${shq(edge + "/secrets")} ${shq(n.root + "/sites")}
chmod 755 ${shq(edge)} ${shq(edge + "/sites")} ${shq(n.root + "/sites")}
chmod 700 ${shq(edge + "/secrets")}
if [ ! -f ${shq(edge + "/compose.yml")} ]; then cat > ${shq(edge + "/compose.yml")} << 'AGENTCMS_EDGE'
${edgeCompose(n.root)}AGENTCMS_EDGE
chmod 644 ${shq(edge + "/compose.yml")}
fi
if [ ! -f ${shq(edge + "/Caddyfile")} ]; then cat > ${shq(edge + "/Caddyfile")} << 'AGENTCMS_EDGE'
${edgeCaddyfile()}AGENTCMS_EDGE
chmod 644 ${shq(edge + "/Caddyfile")}
fi
if [ ! -s ${shq(edge + "/secrets/mysql-root")} ]; then
  # 値はパイプでだけ流す（引数に載せない）。表示しない
  head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40 > ${shq(edge + "/secrets/mysql-root")}
fi
chmod 600 ${shq(edge + "/secrets/mysql-root")}
echo edge-files-ok`;
}
function edgeUpScript(n) {
    return `set -euo pipefail
cd ${shq(n.root + "/edge")}
docker compose up -d --quiet-pull 2>&1 | tail -20
for i in $(seq 1 ${TIMEOUTS.mysqlReady / 2000}); do
  if [ "$(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>/dev/null)" = healthy ]; then echo edge-up-ok; exit 0; fi
  sleep 2
done
echo "mysql-not-healthy: $(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>&1)"
docker logs --tail 20 ${MYSQL_CONTAINER} 2>&1 | grep -v -i password || true
exit 1`;
}
function phpImageScript() {
    return `set -euo pipefail
docker build -q -t ${shq(PHP_IMAGE)} - << 'AGENTCMS_DOCKERFILE'
${phpDockerfile()}AGENTCMS_DOCKERFILE
docker image inspect ${shq(PHP_IMAGE)} >/dev/null && echo php-image-ok`;
}
/**
 * サイトの DB・利用者・shared/.env。**.env が既にあれば何もしない**（パスワードを回さない）。
 *
 * - MySQL の root パスワードはコンテナの中のファイルから読む（ホストの引数に出ない）
 * - 新しいパスワードは SQL ごと標準入力で渡す
 * - .env は印の行だけを builtin の printf で差し替える（sed の引数に値が載らない）
 * - DB を作り終えてから .env を置く。途中で落ちても、次の setup が作り直す
 */
export function siteEnvScript(n, template) {
    const env = `${n.appDir}/shared/.env`;
    return `set -euo pipefail
umask 077
${edgeLock(n)}
if [ -f ${shq(env)} ]; then echo site-env-exists; exit 0; fi
mkdir -p ${shq(n.appDir + "/shared")}
chmod 700 ${shq(n.appDir)}
tmpl=$(mktemp)
cat > "$tmpl" << 'AGENTCMS_ENV'
${template}
AGENTCMS_ENV
gen() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32; }
db_pass=$(gen)
app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\\n')"
printf "CREATE DATABASE IF NOT EXISTS \\\`%s\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\\nCREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\\nALTER USER '%s'@'%%' IDENTIFIED BY '%s';\\nGRANT ALL PRIVILEGES ON \\\`%s\\\`.* TO '%s'@'%%';\\n" \\
  ${shq(n.dbName)} ${shq(n.dbUser)} "$db_pass" ${shq(n.dbUser)} "$db_pass" ${shq(n.dbName)} ${shq(n.dbUser)} \\
  | docker exec -i ${MYSQL_CONTAINER} sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysql -uroot'
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in
    "APP_KEY=${APP_KEY_PLACEHOLDER}") printf 'APP_KEY=%s\\n' "$app_key" ;;
    "DB_PASSWORD=${DB_PASSWORD_PLACEHOLDER}") printf 'DB_PASSWORD=%s\\n' "$db_pass" ;;
    *) printf '%s\\n' "$line" ;;
  esac
done < "$tmpl" > ${shq(env + ".tmp")}
rm -f "$tmpl"
chmod 600 ${shq(env + ".tmp")}
mv ${shq(env + ".tmp")} ${shq(env)}
echo site-env-ok`;
}
// ---------------------------------------------------------------- deploy steps
/**
 * Caddy にこのサイトの行を置く。**先に確かめてから差し替える**:
 *
 * 1. 同じドメインを別のサイトが使っていないか／同じサイト名を別のドメインが使っていないか
 * 2. 新しい行を入れた設定を、動いている Caddy とは別の場所で `caddy validate`
 * 3. 通ったら置き換えて `caddy reload`。reload が落ちたら元の行へ戻す
 *
 * reload は失敗すると前の設定のまま動き続ける（Caddy の仕様）ので、
 * **ほかのサイトは止まらない**。
 */
export async function siteRoute(ctx) {
    const n = vpsNames(ctx.cfg);
    const r = await runScript(ctx.exec, siteRouteScript(n), TIMEOUTS.default);
    if (r.stdout.includes("route-ok") || r.stdout.includes("route-unchanged"))
        return ok("caddy-route", r.stdout.includes("route-ok")
            ? `Caddy に ${n.domain} を入れました`
            : "Caddy の行は変わっていません");
    const taken = /domain-taken:(\S+)/.exec(r.stdout)?.[1];
    if (taken)
        return ng("caddy-route", `ドメイン ${n.domain} は別のサイト「${taken}」が使っています。何も変えずに止めました`);
    const owner = /site-taken:(\S+)/.exec(r.stdout)?.[1];
    if (owner)
        return ng("caddy-route", `サイト名「${n.site}」は別のドメイン ${owner} のサイトが使っています。vps.site を別の名前にしてください。何も変えずに止めました`);
    return ng("caddy-route", `Caddy の設定が通りませんでした（動いている設定はそのまま）: ${tail(r)}`);
}
export function siteRouteScript(n) {
    const sites = `${n.root}/edge/sites`;
    const f = `${sites}/${n.site}.caddy`;
    const marker = `${n.appDir}/.agentcms-site`;
    return `set -uo pipefail
${edgeLock(n)}
for g in ${shq(sites)}/*.caddy; do
  [ -e "$g" ] || continue
  [ "$g" = ${shq(f)} ] && continue
  if grep -qxF ${shq(`${DOMAIN_MARK} ${n.domain}`)} "$g"; then echo "domain-taken:$(basename "$g" .caddy)"; exit 3; fi
done
if [ -f ${shq(marker)} ] && [ "$(head -1 ${shq(marker)})" != ${shq(n.domain)} ]; then echo "site-taken:$(head -1 ${shq(marker)})"; exit 4; fi
mkdir -p ${shq(n.appDir)} && chmod 700 ${shq(n.appDir)}
printf '%s\\n' ${shq(n.domain)} > ${shq(marker)}
new=${shq(f + ".new")}
cat > "$new" << 'AGENTCMS_CADDY'
${siteCaddy(n)}AGENTCMS_CADDY
chmod 644 "$new"
if [ -f ${shq(f)} ] && cmp -s ${shq(f)} "$new"; then rm -f "$new"; echo route-unchanged; exit 0; fi
# 動いている Caddy の外で、新しい行を入れた設定を確かめる
if ! out=$(docker exec ${CADDY_CONTAINER} sh -c 'rm -rf /tmp/agentcms-validate && cp -r /etc/caddy /tmp/agentcms-validate && cp "/etc/caddy/sites/$1.caddy.new" "/tmp/agentcms-validate/sites/$1.caddy" && rm -f /tmp/agentcms-validate/sites/*.new && caddy validate --adapter caddyfile --config /tmp/agentcms-validate/Caddyfile' sh ${shq(n.site)} 2>&1); then
  rm -f "$new"; printf '%s\\n' "$out" | tail -5; echo route-invalid; exit 5
fi
[ -f ${shq(f)} ] && cp -p ${shq(f)} ${shq(f + ".prev")}
mv "$new" ${shq(f)}
if out=$(docker exec ${CADDY_CONTAINER} caddy reload --adapter caddyfile --config /etc/caddy/Caddyfile 2>&1); then
  rm -f ${shq(f + ".prev")}; echo route-ok; exit 0
fi
if [ -f ${shq(f + ".prev")} ]; then mv ${shq(f + ".prev")} ${shq(f)}; else rm -f ${shq(f)}; fi
printf '%s\\n' "$out" | tail -5; echo route-reload-failed; exit 6`;
}
/** サイトの PHP-FPM と scheduler のコンテナを（作り直しが要れば作り直して）起動する */
export async function siteContainers(ctx) {
    const n = vpsNames(ctx.cfg);
    const ids = await ctx.exec.exec('printf \'%s:%s\' "$(id -u)" "$(id -g)"');
    const m = /^(\d+):(\d+)$/.exec(ids.stdout.trim());
    if (!m)
        return ng("containers", `ssh の利用者の uid/gid を読めません: ${tail(ids)}`);
    const compose = siteCompose(n, { uid: m[1], gid: m[2] });
    const r = await runScript(ctx.exec, `set -euo pipefail
cat > ${shq(n.appDir + "/compose.yml")} << 'AGENTCMS_COMPOSE'
${compose}AGENTCMS_COMPOSE
docker compose -f ${shq(n.appDir + "/compose.yml")} up -d --remove-orphans --quiet-pull 2>&1 | tail -20
for i in $(seq 1 30); do
  if [ "$(docker inspect -f '{{.State.Running}}' ${shq(n.appContainer)} 2>/dev/null)" = true ]; then echo containers-ok; exit 0; fi
  sleep 1
done
docker logs --tail 20 ${shq(n.appContainer)} 2>&1
exit 1`, TIMEOUTS.composeUp);
    if (!r.stdout.includes("containers-ok"))
        return ng("containers", `サイトのコンテナが起動しません: ${tail(r)}。\`agentcms deploy doctor\` で PHP のイメージと edge を確かめてください`);
    return ok("containers", `${n.appContainer} / ${n.schedulerContainer}`);
}
/**
 * DB バックアップ（vps）。mysqldump は MySQL のコンテナの中で流す。
 * パスワードは shared/.env から読み、**標準入力**で渡す（引数・環境変数の受け渡しに載せない）。
 * 成功は終了コード＋gzip の検査＋大きさの下限で見る（ファイルがあるだけで成功にしない）。
 */
export function vpsBackupScript(n, releaseId) {
    return `set -euo pipefail
cd ${shq(n.appDir)}
envval() { grep -E "^$1=" shared/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
DB_DATABASE=$(envval DB_DATABASE)
DB_USERNAME=$(envval DB_USERNAME)
mkdir -p backups
out="backups/${releaseId}-db.sql.gz"
envval DB_PASSWORD | docker exec -i ${MYSQL_CONTAINER} sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec mysqldump --single-transaction --no-tablespaces -h 127.0.0.1 -u "$1" "$2"' sh "$DB_USERNAME" "$DB_DATABASE" | gzip > "$out"
gzip -t "$out"
size=$(wc -c < "$out")
if [ "$size" -lt 200 ]; then echo "backup-too-small:$size"; exit 1; fi
echo "backup-ok:$size"`;
}
export async function backupDatabaseVps(ctx) {
    const n = vpsNames(ctx.cfg);
    const r = await runScript(ctx.exec, vpsBackupScript(n, ctx.releaseId), 300_000);
    if (!r.stdout.includes("backup-ok"))
        return ng("db-backup", `DB バックアップ失敗（安全のため deploy を中止）: ${tail(r, 300)}`);
    return ok("db-backup", `${n.appDir}/backups/${ctx.releaseId}-db.sql.gz (${r.stdout.trim().split("\n").pop()})`);
}
