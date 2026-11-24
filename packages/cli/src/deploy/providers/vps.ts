import { createHash } from "node:crypto";
import { posix } from "node:path";

import type { ExecResult, RemoteExecutor } from "../executor.js";
import { shq } from "../executor.js";
import type { DeployContext } from "../release.js";
import type {
  Capabilities,
  CapabilityCheck,
  DeployTargetConfig,
  StepResult,
} from "../types.js";
import {
  APP_KEY_PLACEHOLDER,
  FPM_SOCKET_IN_CONTAINER,
  BACKUP_RUNNING_HOURS,
  BACKUP_STALE_HOURS,
  backupScript,
  CADDY_CONTAINER,
  DB_MAX_USER_CONNECTIONS,
  DB_PASSWORD_PLACEHOLDER,
  DOMAIN_MARK,
  edgeCaddyfile,
  edgeCompose,
  MYSQL_CONTAINER,
  PHP_IMAGE,
  phpDockerfile,
  siteCaddy,
  siteCompose,
  siteNginx,
  vpsNames,
  type VpsNames,
} from "./vps-assets.js";
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

const ok = (step: string, detail?: string): StepResult => ({
  step,
  ok: true,
  detail,
});
const ng = (step: string, detail: string): StepResult => ({
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
export async function runScript(
  exec: RemoteExecutor,
  script: string,
  timeoutMs = TIMEOUTS.default,
): Promise<ExecResult> {
  const cmd =
    `f=$(mktemp) && cat > "$f" << 'AGENTCMS_EOS'\n${script}\nAGENTCMS_EOS\n` +
    `bash "$f"; rc=$?; rm -f "$f"; exit $rc`;
  const r = await exec.exec(cmd, { timeoutMs });
  // SshExecutor は時間切れで SIGTERM → 終了コード 1 になり、理由が残らない
  return r;
}

function tail(r: ExecResult, n = 600): string {
  return `${r.stdout}\n${r.stderr}`.trim().slice(-n);
}

// ---------------------------------------------------------------- サイトの置き場所の中のファイル操作

/**
 * サイトの置き場所（`sites/<site>`）の中のファイル操作を、**その置き場所だけを入れた
 * 使い捨てのコンテナ**で、サイトと同じ利用者のまま流すコマンド行。
 *
 * サイトの PHP は置き場所を書き換えられる（ほかのサイトやサーバーのファイルを指す
 * symlink も置ける）。ssh の利用者（docker グループ）がホストの上でそこを通って
 * 読み書きすると、symlink の先（ほかのサイト・edge の秘密・~/.ssh）に届く。
 * コンテナの中では、symlink はコンテナに見える物の中でしか解けない。
 *
 * - ネットワークなし・権限（capability）なし・特権の昇格なし・メモリ／プロセス／CPU・時間の上限つき・ログを残さない
 * - 根は読むだけ。/tmp は大きさを決めた tmpfs（コンテナの書ける層＝Docker の置き場所に書かない）
 * - イメージはサイトの PHP と同じ（setup が build したもの。取りに行かない）
 * - 秘密を引数に載せない（`stdin` で渡す）
 */
/** サイトの置き場所の中のファイル操作に使う使い捨てのコンテナの上限 */
const SITE_FS_LIMITS = {
  memory: "512m",
  pids: 128,
  cpus: "1.0",
  timeoutSec: 900,
  tmp: "64m",
} as const;

export function siteFsCommand(
  n: VpsNames,
  script: string,
  opts: { stdin?: boolean; timeoutSec?: number } = {},
): string {
  // 時間の上限はコンテナの中の timeout で切る。手元の CLI や ssh が先に切れても、
  // サーバーの上でその時間が来れば中の処理ごと終わり、--rm で消える。
  // --init で PID 1 を Docker の init にする（BusyBox の timeout は自分を対象の処理に置き換えるので、
  // それが PID 1 だと同じ名前空間からの KILL が届かない）
  const secs = Math.max(1, Math.floor(opts.timeoutSec ?? SITE_FS_LIMITS.timeoutSec));
  return (
    `docker run --rm${opts.stdin ? " -i" : ""} --init --pull never --network none --cap-drop ALL ` +
    `--security-opt no-new-privileges --read-only --tmpfs /tmp:size=${SITE_FS_LIMITS.tmp},mode=1777,nosuid,nodev,noexec ` +
    `--memory ${SITE_FS_LIMITS.memory} --memory-swap ${SITE_FS_LIMITS.memory} ` +
    `--pids-limit ${SITE_FS_LIMITS.pids} --cpus ${SITE_FS_LIMITS.cpus} --log-driver none --user "$(id -u):$(id -g)" ` +
    `-v ${shq(`${n.appDir}:${n.appDir}`)} -w ${shq(n.appDir)} ` +
    `--entrypoint timeout ${shq(PHP_IMAGE)} -s KILL ${secs} sh -c ${shq(script)}`
  );
}

/** bash の台本の中で使う同じもの（in_site は標準入力なし、in_site_in は標準入力を渡す） */
function siteFsFns(n: VpsNames): string {
  return `in_site() { ${siteFsCommand(n, '"$1"').replace(`'"$1"'`, '"$1"')} < /dev/null; }
in_site_in() { ${siteFsCommand(n, '"$1"', { stdin: true, timeoutSec: 3600 }).replace(`'"$1"'`, '"$1"')}; }`;
}

/**
 * release.ts の工程（レイアウト・送信と展開・shared のリンク・current の切り替え）を、
 * サイトの置き場所の中だけで流す RemoteExecutor。xserver と同じ工程の中身をそのまま使う。
 *
 * - `exec` … 置き場所だけを入れたコンテナの中で流す
 * - `upload` … いったん control/<site>/upload（サイトから見えない）へ scp し、
 *   コンテナの中で置き場所へ写す（scp が置き場所の中の symlink を辿らない）
 */
export function siteFsExecutor(
  exec: RemoteExecutor,
  n: VpsNames,
): RemoteExecutor {
  return {
    label: () => exec.label(),
    exec: (command, opts) =>
      exec.exec(`${siteFsCommand(n, command)} < /dev/null`, opts),
    upload: async (localPath, remotePath) => {
      if (!remotePath.startsWith(`${n.appDir}/`) || remotePath.includes("/../"))
        return {
          code: 1,
          stdout: "",
          stderr: `サイトの置き場所の外へは送りません: ${remotePath}`,
        };
      const upDir = `${n.controlDir}/upload`;
      const staged = `${upDir}/${posix.basename(remotePath)}`;
      const mk = await exec.exec(
        `mkdir -p ${shq(upDir)} && chmod 700 ${shq(n.controlDir)} ${shq(upDir)}`,
      );
      if (mk.code !== 0) return mk;
      const up = await exec.upload(localPath, staged);
      if (up.code !== 0) return up;
      const dir = posix.dirname(remotePath);
      return exec.exec(
        `${siteFsCommand(
          n,
          `set -eu; mkdir -p ${shq(dir)}; t=$(mktemp ${shq(`${dir}/.upload.XXXXXX`)}); cat > "$t"; mv -f "$t" ${shq(remotePath)}`,
          { stdin: true },
        )} < ${shq(staged)}; rc=$?; rm -f ${shq(staged)}; exit $rc`,
        { timeoutMs: 600_000 },
      );
    },
  };
}

/** release.ts の工程に渡す DeployContext（ファイル操作だけをサイトの置き場所の中へ閉じ込める） */
export function siteFsContext(ctx: DeployContext): DeployContext {
  return { ...ctx, exec: siteFsExecutor(ctx.exec, vpsNames(ctx.cfg)) };
}

/**
 * Caddy を、あるサイトの web 用のネットワーク（そのサイトの web と Caddy だけ）へ入れる bash の関数。
 * 入っていれば何もしない。Caddy を作り直したあと（edge をそろえる・起こす）は全サイトの分を入れ直す
 */
const CADDY_JOIN_FN = `caddy_join() {
  if ! docker network inspect -f '{{range .Containers}}{{.Name}} {{end}}' "$1" 2>/dev/null | tr ' ' '\\n' | grep -qx ${CADDY_CONTAINER}; then
    docker network connect "$1" ${CADDY_CONTAINER}
  fi
}
caddy_join_all() {
  for net in $(docker network ls --format '{{.Name}}' | grep -E '^agentcms-site-[a-z0-9-]+-web$' || true); do caddy_join "$net"; done
}`;

/** DB 名・利用者名（`sgk-<サイト名>`）の形。SQL とシェルに入る前に台本の中でも確かめる */
const DB_NAME_SHELL_RE = "^sgk-[a-z][a-z0-9-]{0,26}[a-z0-9]$";

// ---------------------------------------------------------------- doctor

/** `agentcms deploy doctor`（vps）。読むだけで何も変えない */
export async function vpsDoctor(
  exec: RemoteExecutor,
  cfg: DeployTargetConfig,
): Promise<{ capabilities: Capabilities }> {
  const n = vpsNames(cfg);
  const checks: CapabilityCheck[] = [];
  const add = (c: CapabilityCheck) => checks.push(c);
  const done = (currentRelease: string | null = null) => ({
    capabilities: {
      checks,
      phpBin: "php",
      symlinkOk: true,
      currentRelease,
    } satisfies Capabilities,
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
  const facts = new Map<string, string>();
  for (const line of r.stdout.split("\n")) {
    const i = line.indexOf("=");
    if (i > 0) facts.set(line.slice(0, i), line.slice(i + 1));
  }
  const fact = (k: string) => facts.get(k) ?? "";

  add(
    fact("docker")
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
        },
  );
  add(
    fact("compose")
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
        },
  );
  add(
    fact("root_writable") === "yes"
      ? { key: "root", name: `置き場所 ${n.root}`, status: "PASS" }
      : {
          key: "root",
          name: `置き場所 ${n.root}`,
          status: "FAIL",
          what: `${n.root} が無いか、${cfg.ssh.user} が書き込めません`,
          why: fact("root_writable") || "未確認",
          fix: `一度だけ root で: sudo install -d -o ${cfg.ssh.user} -g ${cfg.ssh.user} ${n.root}`,
        },
  );

  if (fact("edge_root") === "other")
    add({
      key: "edge_root",
      name: `置き場所（vps.root）が edge と同じ`,
      status: "FAIL",
      what: "このサーバーの Caddy は別の置き場所のサイトを見ています",
      why: edgeRootMismatch(n),
      fix: "vps.root をすでにあるサイトと同じにしてください",
    });
  const edgeUp = fact("caddy") === "true" && fact("mysql") === "true";
  add(
    edgeUp
      ? { key: "edge", name: "Caddy と MySQL（edge）", status: "PASS" }
      : {
          key: "edge",
          name: "Caddy と MySQL（edge）",
          status: "FAIL",
          what: "Caddy か MySQL のコンテナが動いていません",
          why: `caddy=${fact("caddy") || "無し"} / mysql=${fact("mysql") || "無し"}`,
          fix: `\`agentcms deploy setup -t ${cfg.target}\` を流してください（作るだけ・何度流しても同じ）`,
        },
  );
  add(
    fact("php_image") === "yes"
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
        },
  );
  add(
    fact("env") === "yes"
      ? { key: "env", name: "shared/.env（APP_KEY あり）", status: "PASS" }
      : {
          key: "env",
          name: "shared/.env（APP_KEY あり）",
          status: "FAIL",
          what: `${n.appDir}/shared/.env が無いか APP_KEY がありません`,
          why: "このサイトの DB と .env をまだ作っていません",
          fix: `\`agentcms deploy setup -t ${cfg.target}\` を流してください（DB パスワードと APP_KEY はサーバーの上で作り、手元へは出しません）`,
        },
  );
  const taken = fact("domain_taken_by");
  add(
    taken
      ? {
          key: "domain",
          name: `ドメイン ${n.domain}`,
          status: "FAIL",
          what: `ドメイン ${n.domain} は別のサイト「${taken}」が使っています`,
          why: "1 つのドメインは 1 つのサイトにしか向けられません",
          fix: "app.url か、相手のサイトの設定を見直してください",
        }
      : { key: "domain", name: `ドメイン ${n.domain}`, status: "PASS" },
  );
  const owner = fact("site_owner");
  add(
    owner && owner !== n.domain
      ? {
          key: "site",
          name: `サイト名 ${n.site}`,
          status: "FAIL",
          what: `サイト名「${n.site}」は別のドメイン ${owner} のサイトが使っています`,
          why: "同じサイト名で出すと、そのサイトを上書きします",
          fix: "vps.site を別の名前にしてください",
        }
      : { key: "site", name: `サイト名 ${n.site}`, status: "PASS" },
  );
  if (fact("disk_shared") === "yes" || fact("disk_shared") === "unknown")
    add({
      key: "disk",
      name: "サイトの置き場所の容量",
      status: "WARN",
      what:
        fact("disk_shared") === "yes"
          ? `サイトの置き場所と Docker（MySQL のデータ）が同じファイルシステムにあり、サイトごとの容量の上限がありません`
          : `サイトの置き場所と Docker（MySQL のデータ）が同じファイルシステムかどうかを確かめられませんでした`,
      why: "1 つのサイトが置き場所を書き足し続けると、MySQL とほかのサイトも書けなくなります（サイトのコンテナは同じ uid で動くので、利用者ごとの quota では区切れません）",
      fix: `${n.root}/sites をサイトごとに容量を区切ったファイルシステム（project quota・サイトごとのディスクイメージなど）に置くか、Docker の置き場所と別のディスクに分けてください（docs/deployment/vps.md「1 台に置くサイトの上限」）`,
    });

  const bk = backupCheck(n, {
    status: fact("backup_status"),
    container: fact("backup_container"),
    now: Number(fact("server_now")),
    deployed: Boolean(fact("current")),
  });
  if (bk) add(bk);

  return done(fact("current") || null);
}

/** サーバーの夜のバックアップの記録（control/<site>/backup/status.json・backup のコンテナだけが書く） */
export interface BackupStatus {
  state: "running" | "ok" | "failed";
  error: string | null;
  id: string;
  startedAt: number;
  finishedAt: number | null;
  lastSuccessAt: number | null;
  lastSuccessId: string | null;
  dbBytes: number | null;
  filesBytes: number | null;
}

/** 失敗の言葉（台本が決めた物だけ）と、その意味 */
export const BACKUP_ERRORS: Record<string, string> = {
  "db-auth": "このサイトの DB の利用者で入れません（パスワードが変わった？ control/<サイト名>/db-password と shared/.env の DB_PASSWORD を確かめる）",
  "db-connect": "MySQL につながりません",
  "db-dump": "mysqldump が途中で落ちました（backup のコンテナのログ）",
  "no-db-password": "control/<サイト名>/db-password が空です",
  "files-link": "shared・storage・storage/app のどれかがリンクに差し替わっています（サイトの中から？）",
  "files-missing": "shared/storage/app がありません",
  files: "ファイルを tar で読めませんでした（読めないファイルがある・backup のコンテナのログ）",
  "too-large": "上限（vps.backup.maxMB）を超えたので取りませんでした",
  "no-space": "サーバーの空きが上限の 2 倍より少ないので取りませんでした",
  write: "control/<サイト名>/backup に書けませんでした",
};

/** status.json（base64）を読む。形が違えば null（数は数・言葉は決まった物だけ） */
export function parseBackupStatus(b64: string): BackupStatus | null {
  try {
    const raw = Buffer.from(b64, "base64").toString("utf8");
    if (raw.length > 4096) return null;
    const d = JSON.parse(raw) as Record<string, unknown>;
    const sec = (v: unknown) =>
      Number.isSafeInteger(v) && (v as number) >= 0 && (v as number) <= 4_102_444_800
        ? (v as number)
        : null;
    const bytes = (v: unknown) =>
      Number.isSafeInteger(v) && (v as number) >= 0 ? (v as number) : null;
    const id14 = (v: unknown) =>
      typeof v === "string" && /^\d{14}$/.test(v) ? v : null;
    if (d.version !== 1) return null;
    if (d.state !== "running" && d.state !== "ok" && d.state !== "failed")
      return null;
    const id = id14(d.id);
    const startedAt = sec(d.startedAt);
    if (id === null || startedAt === null) return null;
    const error =
      typeof d.error === "string" && d.error in BACKUP_ERRORS ? d.error : null;
    if (d.state === "failed" && error === null) return null;
    return {
      state: d.state,
      error,
      id,
      startedAt,
      finishedAt: sec(d.finishedAt),
      lastSuccessAt: sec(d.lastSuccessAt),
      lastSuccessId: id14(d.lastSuccessId),
      dbBytes: bytes(d.dbBytes),
      filesBytes: bytes(d.filesBytes),
    };
  } catch {
    return null;
  }
}

/**
 * doctor の「夜のバックアップ」の行。サイトの置き場所の記録（アプリの auto.json）は読まない
 * — サイトが書けるので、成功を装える。読むのはサーバーの backup のコンテナが control に書いた物だけ。
 * どれも WARN（deploy は止めない）。まだ一度も出していないサイトは出さない
 */
export function backupCheck(
  n: VpsNames,
  f: { status: string; container: string; now: number; deployed: boolean },
): CapabilityCheck | null {
  const name = `夜のバックアップ（サーバーが取る写し・${n.controlDir}/backup）`;
  if (!f.deployed && (f.status === "" || f.status === "none")) return null;
  const warn = (what: string, why: string, fix: string): CapabilityCheck => ({
    key: "backup",
    name,
    status: "WARN",
    what,
    why,
    fix,
  });
  const logs = `docker logs --tail 50 ${n.backupContainer}`;
  if (f.container !== "running")
    return warn(
      `夜のバックアップのコンテナが動いていません（${f.container || "無い"}）`,
      "このままでは毎日のバックアップが取れません",
      "`agentcms deploy` を流し直すと作り直します",
    );
  if (f.status === "" || f.status === "none")
    return warn(
      "夜のバックアップの記録がまだありません（コンテナは立ち上がるとすぐ 1 回取ります）",
      "立ち上げた直後なら、少し待ってからもう一度 doctor を流してください",
      `続くなら ${logs}`,
    );
  const st = f.status === "odd" ? null : parseBackupStatus(f.status);
  if (!st)
    return warn(
      "夜のバックアップの記録（status.json）の形が違います",
      "backup のコンテナだけが書くファイルです。手で変えたか、壊れています",
      `${logs} を見て、${n.controlDir}/backup/status.json を消すと次の起動で取り直します`,
    );
  const iso = (t: number | null) =>
    t === null ? "記録なし" : new Date(t * 1000).toISOString();
  const now = Number.isFinite(f.now) && f.now > 0 ? f.now : Date.now() / 1000;
  if (st.state === "failed")
    return warn(
      `最後の夜のバックアップが失敗しました（${st.error}・${iso(st.finishedAt)}）`,
      `${BACKUP_ERRORS[st.error!]}。最後に取れたのは ${iso(st.lastSuccessAt)}`,
      `${logs} で詳しい理由を見て直し、\`docker exec ${n.backupContainer} bash /agentcms/backup.sh now\` で取り直してください`,
    );
  if (st.state === "running" && now - st.startedAt > BACKUP_RUNNING_HOURS * 3600)
    return warn(
      `夜のバックアップが ${iso(st.startedAt)} に始まったまま終わっていません`,
      "途中でコンテナが止まった可能性があります",
      `${logs} を見て、\`docker exec ${n.backupContainer} bash /agentcms/backup.sh now\` で取り直してください`,
    );
  if (st.lastSuccessAt === null || now - st.lastSuccessAt > BACKUP_STALE_HOURS * 3600)
    return warn(
      `夜のバックアップが ${BACKUP_STALE_HOURS} 時間以上取れていません（最後の成功: ${iso(st.lastSuccessAt)}）`,
      "毎日取るはずです（vps.backup.at・UTC）",
      `${logs} を見てください`,
    );
  const kb = (b: number | null) => (b === null ? "?" : `${Math.ceil(b / 1024)}KB`);
  return {
    key: "backup",
    name,
    status: "PASS",
    detected: `最後の成功 ${iso(st.lastSuccessAt)}（${st.lastSuccessId}・DB ${kb(st.dbBytes)}・ファイル ${kb(st.filesBytes)}）`,
  };
}

function doctorScript(n: VpsNames): string {
  return `set -u
if v=$(docker version --format '{{.Server.Version}}' 2>&1); then echo "docker=$v"; else echo "docker="; echo "docker_err=$(printf %s "$v" | tail -1)"; fi
echo "compose=$(docker compose version --short 2>/dev/null)"
if [ -d ${shq(n.root)} ] && [ -w ${shq(n.root)} ]; then echo root_writable=yes; elif [ -d ${shq(n.root)} ]; then echo root_writable=書き込めない; else echo root_writable=無い; fi
echo "caddy=$(docker inspect -f '{{.State.Running}}' ${CADDY_CONTAINER} 2>/dev/null)"
echo "mysql=$(docker inspect -f '{{.State.Running}}' ${MYSQL_CONTAINER} 2>/dev/null)"
if docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1; then echo php_image=yes; else echo php_image=no; fi
${siteFsFns(n)}
if [ -d ${shq(n.appDir)} ] && docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1 && in_site "grep -q '^APP_KEY=base64:' shared/.env" 2>/dev/null; then echo env=yes; else echo env=no; fi
for g in ${shq(n.root + "/edge/sites")}/*.caddy; do
  [ -e "$g" ] || continue
  [ "$(basename "$g" .caddy)" = ${shq(n.site)} ] && continue
  if grep -qxF ${shq(`${DOMAIN_MARK} ${n.domain}`)} "$g"; then echo "domain_taken_by=$(basename "$g" .caddy)"; fi
done
[ -f ${shq(n.controlDir + "/site")} ] && echo "site_owner=$(head -1 ${shq(n.controlDir + "/site")})"
echo "current=$(readlink ${shq(n.appDir + "/current")} 2>/dev/null)"
${edgeRootProbe(n)}
# サーバーの夜のバックアップの記録（control の中・サイトのコンテナから見えない。backup のコンテナだけが書く）
bk=${shq(n.controlDir + "/backup/status.json")}
if [ -L "$bk" ] || { [ -e "$bk" ] && [ ! -f "$bk" ]; }; then echo backup_status=odd
elif [ -f "$bk" ]; then echo "backup_status=$(head -c 4097 "$bk" | base64 | tr -d '\\n')"
else echo backup_status=none; fi
echo "backup_container=$(docker inspect -f '{{.State.Status}}' ${shq(n.backupContainer)} 2>/dev/null)"
echo "server_now=$(date -u +%s)"
# サイトの置き場所と Docker（MySQL のデータ）が同じ場所（ファイルシステム）か。同じなら、1 サイトが
# 置き場所を書き足し続けると MySQL とほかのサイトも書けなくなる（サイトごとの容量の上限は無い）
# 比べるのは、サイトが実際に書く場所（このサイトの置き場所・無ければ sites/）と Docker の置き場所
if [ -d ${shq(n.root)} ]; then
  dr=$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || true)
  w=${shq(n.root + "/sites")}; [ -d ${shq(n.appDir)} ] && w=${shq(n.appDir)}
  a=""; b=""
  [ -d "$w" ] && a=$(df -P "$w" 2>/dev/null | awk 'NR==2{print $1}')
  [ -n "$dr" ] && b=$(df -P "$dr" 2>/dev/null | awk 'NR==2{print $1}')
  if [ -z "$a" ] || [ -z "$b" ]; then echo disk_shared=unknown; elif [ "$a" = "$b" ]; then echo disk_shared=yes; else echo disk_shared=no; fi
fi
exit 0`;
}

/**
 * edge（Caddy の行・MySQL の root パスワード・サイトの DB）を変える台本の鍵。
 * 同じサーバーへ 2 つの setup / deploy が同時に来ても、順番に流す。
 *
 * `mkdir` の成否で取る（flock は BusyBox と macOS で使い方が違う・無いことがある）。
 * 台本が終われば（失敗でも）trap で外す。取れなければ 1 秒ごとに待ち、
 * `AGENTCMS_LOCK_WAIT` 秒（既定 300）で諦める。
 */
function edgeLock(n: VpsNames): string {
  const lock = `${n.root}/edge/.agentcms.lock`;
  return `mkdir -p ${shq(n.root + "/edge")}
lock_waited=0
until mkdir ${shq(lock)} 2>/dev/null; do
  lock_waited=$((lock_waited + 1))
  if [ "$lock_waited" -ge "\${AGENTCMS_LOCK_WAIT:-300}" ]; then
    echo "lock-timeout: 別の setup / deploy が終わりません（${lock} — 止まったまま残っているなら、ほかに動いていないことを確かめてから消す）"
    exit 7
  fi
  sleep 1
done
trap 'rmdir ${shq(lock)} 2>/dev/null' EXIT`;
}

/**
 * 動いている Caddy が**この設定と同じ置き場所**のサイトを見ているか。
 * edge（Caddy・MySQL）はサーバーに 1 つなので、`vps.root` が違う設定から触ると、
 * 別の置き場所の同じ名前の DB を「無い」と読み違える。
 */
function edgeRootProbe(n: VpsNames): string {
  return `if docker inspect ${CADDY_CONTAINER} >/dev/null 2>&1; then
  r=$(docker inspect -f '{{index .Config.Labels "agentcms.root"}}' ${CADDY_CONTAINER} 2>/dev/null || true)
  if [ -z "$r" ] || [ "$r" = "<no value>" ]; then echo edge_root=unknown; elif [ "$r" = ${shq(n.root)} ]; then echo edge_root=same; else echo edge_root=other; fi
fi`;
}

function edgeRootMismatch(n: VpsNames): string {
  return `このサーバーの Caddy と MySQL は、別の置き場所（vps.root）のために動いています。1 台の置き場所は 1 つです。設定の vps.root を、すでにあるサイトと同じにしてください（この設定: ${n.root}）`;
}

// ---------------------------------------------------------------- setup

export interface VpsSetupAction {
  key: string;
  describe: string;
  satisfied: boolean;
  current: string;
  timeoutMs: number;
  script: string;
}

/**
 * `agentcms deploy setup`（vps）でやること。**作るだけ・何度流しても同じ**。
 * 既にあるものは変えない（edge の compose・Caddyfile・MySQL の root パスワード・
 * サイトの .env を上書きしない）。DB パスワード・APP_KEY は .env が**無いときだけ**作る。
 */
export async function vpsSetupActions(
  exec: RemoteExecutor,
  cfg: DeployTargetConfig,
): Promise<{ actions: VpsSetupAction[]; error?: string }> {
  const n = vpsNames(cfg);
  const probe = await runScript(
    exec,
    `set -u
[ -d ${shq(n.root)} ] && [ -w ${shq(n.root)} ] && echo root=yes
[ -f ${shq(n.root + "/edge/compose.yml")} ] && [ -f ${shq(n.root + "/edge/Caddyfile")} ] && [ -s ${shq(n.root + "/edge/secrets/mysql-root")} ] && echo edge_files=yes
[ -f ${shq(n.root + "/edge/compose.yml")} ] && echo "edge_compose_sha=$(sha256sum ${shq(n.root + "/edge/compose.yml")} | cut -d' ' -f1)"
${edgeRootProbe(n)}
[ "$(docker inspect -f '{{.State.Running}}' ${CADDY_CONTAINER} 2>/dev/null)" = true ] && [ "$(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>/dev/null)" = healthy ] && echo edge_up=yes
docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1 && echo php_image=yes
${siteFsFns(n)}
[ -d ${shq(n.appDir)} ] && docker image inspect ${shq(PHP_IMAGE)} >/dev/null 2>&1 && in_site 'test -f shared/.env' 2>/dev/null && echo env=yes
exit 0`,
  );
  if (probe.code !== 0)
    return { actions: [], error: `サーバーを読めませんでした: ${tail(probe)}` };
  const has = (k: string) => probe.stdout.includes(`${k}=yes`);
  if (!has("root"))
    return {
      actions: [],
      error: `${n.root} が無いか書き込めません。一度だけ root で: sudo install -d -o ${cfg.ssh.user} -g ${cfg.ssh.user} ${n.root}`,
    };
  if (probe.stdout.includes("edge_root=other"))
    return { actions: [], error: edgeRootMismatch(n) };
  const remoteSha = /edge_compose_sha=([0-9a-f]{64})/.exec(probe.stdout)?.[1];
  const edgeComposeIsCurrent =
    remoteSha ===
    createHash("sha256").update(edgeCompose(n.root)).digest("hex");

  const actions: VpsSetupAction[] = [
    {
      key: "edge_files",
      describe: `edge の設定を置く（${n.root}/edge の compose.yml・Caddyfile・MySQL の root パスワードをサーバーで生成）`,
      satisfied: has("edge_files"),
      current: has("edge_files") ? "置いてある（上書きしない）" : "無い",
      timeoutMs: TIMEOUTS.default,
      script: edgeFilesScript(n),
    },
    {
      key: "edge_upgrade",
      describe: `edge をこの版のキットの形にそろえる（${n.root}/edge/compose.yml を置き換え・前のものは compose.yml.prev。Caddy と MySQL を作り直すので、全サイトが数秒止まる）`,
      satisfied: !has("edge_files") || edgeComposeIsCurrent,
      current: edgeComposeIsCurrent
        ? "この版の形"
        : "前の版の形（または手で変えてある）",
      timeoutMs: TIMEOUTS.composeUp + TIMEOUTS.mysqlReady,
      script: edgeUpgradeScript(n),
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
export function envTemplate(cfg: DeployTargetConfig): string {
  const n = vpsNames(cfg);
  return minimalEnv(cfg, APP_KEY_PLACEHOLDER, {
    dbName: n.dbName,
    dbUser: n.dbUser,
    dbPassword: DB_PASSWORD_PLACEHOLDER,
  });
}

export function edgeFilesScript(n: VpsNames): string {
  const edge = `${n.root}/edge`;
  return `set -euo pipefail
umask 077
${edgeLock(n)}
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
  # MySQL のデータが既にあるのに作り直すと、誰も root で入れなくなる
  if docker volume inspect agentcms-edge_mysql-data >/dev/null 2>&1; then
    echo "MySQL のデータ（agentcms-edge_mysql-data）はあるのに root パスワードのファイルがありません。新しく作ると入れなくなるので止めます。${edge}/secrets/mysql-root を元に戻してください"
    exit 1
  fi
  # 値はパイプでだけ流す（引数に載せない）。表示しない
  head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40 > ${shq(edge + "/secrets/mysql-root")}
fi
chmod 600 ${shq(edge + "/secrets/mysql-root")}
echo edge-files-ok`;
}

/** edge の compose をこの版の形にそろえ、作り直す（前のものは .prev に残す） */
export function edgeUpgradeScript(n: VpsNames): string {
  const edge = `${n.root}/edge`;
  return `set -euo pipefail
${edgeLock(n)}
cd ${shq(edge)}
# 前の形（Caddy がファイルを直接配信する）のサイトの行が残っていたら、edge を変える前に止める。
# 先に変えると、その行のサイトは配信の道を失う。各サイトを今のキットで deploy し直すと行が今の形になる
old=$(grep -lE 'php_fastcgi|file_server' sites/*.caddy 2>/dev/null || true)
if [ -n "$old" ]; then
  echo "edge-old-sites: 前の形のサイトの行があります（$(printf '%s' "$old" | xargs -n1 basename | tr '\\n' ' ')）。先にそれぞれのサイトを agentcms deploy で出し直してから、setup をもう一度流してください。edge は変えていません"
  exit 1
fi
cp -p compose.yml compose.yml.prev
cat > compose.yml.new << 'AGENTCMS_EDGE'
${edgeCompose(n.root)}AGENTCMS_EDGE
chmod 644 compose.yml.new
mv compose.yml.new compose.yml
docker compose up -d --remove-orphans --quiet-pull 2>&1 | tail -20
# 作り直した Caddy を、各サイトの web 用のネットワークへ入れ直す（入れないと全サイトが 502）
${CADDY_JOIN_FN}
caddy_join_all
for i in $(seq 1 ${TIMEOUTS.mysqlReady / 2000}); do
  if [ "$(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>/dev/null)" = healthy ] && [ "$(docker inspect -f '{{.State.Running}}' ${CADDY_CONTAINER} 2>/dev/null)" = true ]; then echo edge-upgrade-ok; exit 0; fi
  sleep 2
done
echo "edge を起こせませんでした。前の compose は ${edge}/compose.yml.prev にあります"
exit 1`;
}

function edgeUpScript(n: VpsNames): string {
  return `set -euo pipefail
cd ${shq(n.root + "/edge")}
docker compose up -d --quiet-pull 2>&1 | tail -20
${CADDY_JOIN_FN}
caddy_join_all
for i in $(seq 1 ${TIMEOUTS.mysqlReady / 2000}); do
  if [ "$(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>/dev/null)" = healthy ]; then echo edge-up-ok; exit 0; fi
  sleep 2
done
echo "mysql-not-healthy: $(docker inspect -f '{{.State.Health.Status}}' ${MYSQL_CONTAINER} 2>&1)"
docker logs --tail 20 ${MYSQL_CONTAINER} 2>&1 | grep -v -i password || true
exit 1`;
}

function phpImageScript(): string {
  return `set -euo pipefail
docker build -q -t ${shq(PHP_IMAGE)} - << 'AGENTCMS_DOCKERFILE'
${phpDockerfile()}AGENTCMS_DOCKERFILE
docker image inspect ${shq(PHP_IMAGE)} >/dev/null && echo php-image-ok`;
}

/**
 * サイトの DB・利用者・shared/.env。**.env が既にあれば何もしない**（パスワードを回さない）。
 *
 * - MySQL の root で流す SQL には、**サイトの置き場所から読んだ値を一切入れない**。
 *   DB 名・利用者名は設定のサイト名から作った `sgk-<サイト名>`（形を台本の中でも確かめる）、
 *   パスワードはこの台本がサーバーの上で作った英数字 32 文字だけ
 * - 途中で止まったときの値は control/<site>/env.pending（サイトのコンテナから見えない・600）に置く。
 *   サイトの置き場所の shared/.env.pending は読まない（サイトが書き換えられる）
 * - MySQL の root パスワードはコンテナの中のファイルから読む（ホストの引数に出ない）
 * - 新しいパスワードは SQL ごと標準入力で渡す
 * - .env は印の行だけを builtin の printf で差し替え、置き場所だけを入れたコンテナの中で置く
 * - 同じ名前の DB か利用者が既にあれば止める（パスワードを変えない・他人の DB に権限を足さない）
 * - DB を作り終えてから .env を置く
 */
export function siteEnvScript(n: VpsNames, template: string): string {
  const pendingFile = `${n.controlDir}/env.pending`;
  return `set -euo pipefail
umask 077
${edgeLock(n)}
[[ ${shq(n.dbName)} =~ ${DB_NAME_SHELL_RE} ]] && [[ ${shq(n.dbUser)} =~ ${DB_NAME_SHELL_RE} ]] || { echo "name-odd: DB 名・利用者名の形が違います。何も変えずに止めました"; exit 1; }
mkdir -p ${shq(n.appDir)} ${shq(n.controlDir)}
chmod 700 ${shq(n.appDir)} ${shq(n.controlDir)}
${siteFsFns(n)}
has_env=$(in_site 'if [ -f shared/.env ]; then echo yes; else echo no; fi') || { echo "site-fs-failed: サイトの置き場所を読めませんでした（PHP のイメージが無い？）"; exit 1; }
if [ "$has_env" = yes ]; then echo site-env-exists; exit 0; fi
[ "$has_env" = no ] || { echo "site-fs-failed: $has_env"; exit 1; }
tmpl=$(mktemp)
trap 'rm -f "$tmpl"; rmdir ${shq(`${n.root}/edge/.agentcms.lock`)} 2>/dev/null' EXIT
cat > "$tmpl" << 'AGENTCMS_ENV'
${template}
AGENTCMS_ENV
gen() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32; }
pending=${shq(pendingFile)}
root_sql() { docker exec -i ${MYSQL_CONTAINER} sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysql -uroot -N'; }
db_pass=""; app_key=""
if [ -s "$pending" ] && [ ! -L "$pending" ]; then
  # 前の setup が「同じ名前の DB・利用者が無い」と確かめてから置いた値（サイトから見えない場所）。
  # 形が違えば信じない（手で書き換えられた・壊れた）
  while IFS= read -r line; do
    case "$line" in
      DB_PASSWORD=*) db_pass="\${line#DB_PASSWORD=}" ;;
      APP_KEY=*) app_key="\${line#APP_KEY=}" ;;
      *) echo "pending-odd: ${pendingFile} に知らない行があります。何も変えずに止めました"; exit 1 ;;
    esac
  done < "$pending"
  [[ "$db_pass" =~ ^[A-Za-z0-9]{32}$ ]] || { echo "pending-odd: ${pendingFile} の DB_PASSWORD の形が違います。何も変えずに止めました"; exit 1; }
  [[ "$app_key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || { echo "pending-odd: ${pendingFile} の APP_KEY の形が違います。何も変えずに止めました"; exit 1; }
else
  # 同じ名前の DB か利用者が既にあれば作らない（別の置き場所のサイトや手で作ったもの・
  # .env を消されたサイト）。確かめられなければ（MySQL に入れないなど）ここで止まる — まだ何も書いていない
  exists=$(printf "SELECT (SELECT COUNT(*) FROM mysql.user WHERE user='%s') + (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='%s');\\n" ${shq(n.dbUser)} ${shq(n.dbName)} | root_sql)
  if [ "$exists" != "0" ]; then
    echo "db-exists: DB か利用者「${n.dbName}」が既にあるのに、このサイトの shared/.env がありません。別の置き場所の同じサイト名か、手で作ったものか、.env を消されたサイトです。何も変えずに止めました"
    exit 1
  fi
  db_pass=$(gen)
  app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\\n')"
  [[ "$db_pass" =~ ^[A-Za-z0-9]{32}$ ]] && [[ "$app_key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || { echo "gen-failed: 値を作れませんでした"; exit 1; }
  # DB に触る前に値を残す（600・サイトから見えない場所）。止まっても次の setup が同じ値で続けられる
  printf 'DB_PASSWORD=%s\\nAPP_KEY=%s\\n' "$db_pass" "$app_key" > "$pending.tmp"
  chmod 600 "$pending.tmp"
  mv -f "$pending.tmp" "$pending"
fi
# 作る（続きのときは、あるものは飛ばす。既にある利用者のパスワードは変えない）。
# ここに入る値は、上で形を確かめた sgk-<サイト名> と英数字 32 文字だけ
printf "CREATE DATABASE IF NOT EXISTS \\\`%s\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\\nCREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s' WITH MAX_USER_CONNECTIONS ${DB_MAX_USER_CONNECTIONS};\\n" \\
  ${shq(n.dbName)} ${shq(n.dbUser)} "$db_pass" | root_sql
# その利用者に、残した値で本当に入れるか（入れなければ自分の作ったものではない）
if ! printf '%s\\n' "$db_pass" | docker exec -i ${MYSQL_CONTAINER} sh -c 'IFS= read -r p; MYSQL_PWD="$p" exec mysql -h 127.0.0.1 -u "$1" -N -e "SELECT 1"' sh ${shq(n.dbUser)} >/dev/null 2>&1; then
  echo "db-owner-mismatch: 利用者「${n.dbUser}」に、このサイトの値で入れません。別の人の利用者です。権限は足さずに止めました（${pendingFile} を消すと、最初からやり直します）"
  exit 1
fi
# 接続の数の上限（パスワードは変えない）と権限。共有の MySQL の接続枠を 1 サイトで使い切らない
printf "ALTER USER '%s'@'%%' WITH MAX_USER_CONNECTIONS ${DB_MAX_USER_CONNECTIONS};\\nGRANT ALL PRIVILEGES ON \\\`%s\\\`.* TO '%s'@'%%';\\n" ${shq(n.dbUser)} ${shq(n.dbName)} ${shq(n.dbUser)} | root_sql
# .env はサイトの置き場所だけを入れたコンテナの中で置く（置き場所の中の symlink を外で辿らない）
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in
    "APP_KEY=${APP_KEY_PLACEHOLDER}") printf 'APP_KEY=%s\\n' "$app_key" ;;
    "DB_PASSWORD=${DB_PASSWORD_PLACEHOLDER}") printf 'DB_PASSWORD=%s\\n' "$db_pass" ;;
    *) printf '%s\\n' "$line" ;;
  esac
done < "$tmpl" | in_site_in 'set -eu; umask 077; mkdir -p shared; t=$(mktemp shared/.env.XXXXXX); cat > "$t"; chmod 600 "$t"; mv -f "$t" shared/.env; rm -f shared/.env.pending'
in_site 'test -s shared/.env'
# 夜のバックアップ（サーバーの backup のコンテナ）が使う、このサイトの利用者のパスワード（サイトから見えない・600）
printf '%s' "$db_pass" > ${shq(n.controlDir + "/db-password.tmp")}
chmod 600 ${shq(n.controlDir + "/db-password.tmp")}
mv -f ${shq(n.controlDir + "/db-password.tmp")} ${shq(n.controlDir + "/db-password")}
rm -f "$pending"
echo site-env-ok`;
}

/**
 * サイトの DB 利用者に同時接続の上限を付ける（deploy のたび・何度流しても同じ）。
 * setup より前の版で作った利用者にも付く。パスワードは変えない。
 * SQL に入るのは設定のサイト名から作った名前（形を確かめる）と決まった数だけ
 */
export function dbLimitsScript(n: VpsNames): string {
  return `set -euo pipefail
[[ ${shq(n.dbUser)} =~ ${DB_NAME_SHELL_RE} ]] || { echo "name-odd"; exit 1; }
root_sql() { docker exec -i ${MYSQL_CONTAINER} sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysql -uroot -N'; }
have=$(printf "SELECT COUNT(*) FROM mysql.user WHERE user='%s' AND host='%%';\\n" ${shq(n.dbUser)} | root_sql)
[ "$have" = 1 ] || { echo "db-user-missing: DB の利用者「${n.dbUser}」がありません。agentcms deploy setup を流してください"; exit 1; }
printf "ALTER USER '%s'@'%%' WITH MAX_USER_CONNECTIONS ${DB_MAX_USER_CONNECTIONS};\\n" ${shq(n.dbUser)} | root_sql
echo db-limits-ok`;
}

export async function dbUserLimits(ctx: DeployContext): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const r = await runScript(ctx.exec, dbLimitsScript(n));
  return r.code === 0 && r.stdout.includes("db-limits-ok")
    ? ok("db-limits", `${n.dbUser} の同時接続は ${DB_MAX_USER_CONNECTIONS} まで`)
    : ng("db-limits", `DB の利用者に接続の上限を付けられませんでした: ${tail(r)}`);
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
export async function siteRoute(ctx: DeployContext): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const r = await runScript(ctx.exec, siteRouteScript(n), TIMEOUTS.default);
  if (
    r.code === 0 &&
    (r.stdout.includes("route-ok") || r.stdout.includes("route-unchanged"))
  )
    return ok(
      "caddy-route",
      r.stdout.includes("route-ok")
        ? `Caddy に ${n.domain} を入れました`
        : "Caddy の行は変わっていません",
    );
  const taken = /domain-taken:(\S+)/.exec(r.stdout)?.[1];
  if (taken)
    return ng(
      "caddy-route",
      `ドメイン ${n.domain} は別のサイト「${taken}」が使っています。何も変えずに止めました`,
    );
  const owner = /site-taken:(\S+)/.exec(r.stdout)?.[1];
  if (owner)
    return ng(
      "caddy-route",
      `サイト名「${n.site}」は別のドメイン ${owner} のサイトが使っています。vps.site を別の名前にしてください。何も変えずに止めました`,
    );
  if (r.stdout.includes("route-restore-failed"))
    return ng(
      "caddy-route",
      `🔴 Caddy の行を元に戻せませんでした。動いている Caddy は前の設定のままですが、${n.root}/edge/sites/${n.site}.caddy を手で直さないと、次の reload・再起動で止まります: ${tail(r)}`,
    );
  return ng(
    "caddy-route",
    `Caddy の設定が通りませんでした（動いている設定はそのまま）: ${tail(r)}`,
  );
}

export function siteRouteScript(n: VpsNames): string {
  const sites = `${n.root}/edge/sites`;
  const f = `${sites}/${n.site}.caddy`;
  // このサイト名を使っているドメインの印。サイトのコンテナから見えない control に置く
  const marker = `${n.controlDir}/site`;
  return `set -uo pipefail
${edgeLock(n)}
for g in ${shq(sites)}/*.caddy; do
  [ -e "$g" ] || continue
  [ "$g" = ${shq(f)} ] && continue
  if grep -qxF ${shq(`${DOMAIN_MARK} ${n.domain}`)} "$g"; then echo "domain-taken:$(basename "$g" .caddy)"; exit 3; fi
done
if [ -f ${shq(marker)} ] && [ "$(head -1 ${shq(marker)})" != ${shq(n.domain)} ]; then echo "site-taken:$(head -1 ${shq(marker)})"; exit 4; fi
mkdir -p ${shq(n.appDir)} ${shq(n.controlDir)} && chmod 700 ${shq(n.appDir)} ${shq(n.controlDir)}
printf '%s\\n' ${shq(n.domain)} > ${shq(marker + ".tmp")} && mv -f ${shq(marker + ".tmp")} ${shq(marker)}
new=${shq(f + ".new")}
cat > "$new" << 'AGENTCMS_CADDY'
${siteCaddy(n)}AGENTCMS_CADDY
chmod 644 "$new"
if [ -f ${shq(f)} ] && cmp -s ${shq(f)} "$new"; then rm -f "$new"; echo route-unchanged; exit 0; fi
# 動いている Caddy の外で、新しい行を入れた設定を確かめる
if ! out=$(docker exec ${CADDY_CONTAINER} sh -c 'rm -rf /tmp/agentcms-validate && cp -r /etc/caddy /tmp/agentcms-validate && cp "/etc/caddy/sites/$1.caddy.new" "/tmp/agentcms-validate/sites/$1.caddy" && rm -f /tmp/agentcms-validate/sites/*.new && caddy validate --adapter caddyfile --config /tmp/agentcms-validate/Caddyfile' sh ${shq(n.site)} 2>&1); then
  rm -f "$new"; printf '%s\\n' "$out" | tail -5; echo route-invalid; exit 5
fi
rm -f ${shq(f + ".prev")}
if [ -f ${shq(f)} ]; then
  if ! cp -p ${shq(f)} ${shq(f + ".prev")} || ! cmp -s ${shq(f)} ${shq(f + ".prev")}; then
    rm -f "$new" ${shq(f + ".prev")}; echo route-backup-failed; exit 8
  fi
fi
if ! mv "$new" ${shq(f)}; then
  rm -f "$new" ${shq(f + ".prev")}; echo route-replace-failed; exit 9
fi
if out=$(docker exec ${CADDY_CONTAINER} caddy reload --adapter caddyfile --config /etc/caddy/Caddyfile 2>&1); then
  rm -f ${shq(f + ".prev")}; echo route-ok; exit 0
fi
printf '%s\\n' "$out" | tail -5
if [ -f ${shq(f + ".prev")} ]; then
  mv ${shq(f + ".prev")} ${shq(f)} || { echo route-restore-failed; exit 10; }
else
  rm -f ${shq(f)} || { echo route-restore-failed; exit 10; }
fi
echo route-reload-failed; exit 6`;
}

/** サイトの PHP-FPM と scheduler のコンテナを（作り直しが要れば作り直して）起動する */
export async function siteContainers(ctx: DeployContext): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const ids = await ctx.exec.exec('printf \'%s:%s\' "$(id -u)" "$(id -g)"');
  const m = /^(\d+):(\d+)$/.exec(ids.stdout.trim());
  if (!m)
    return ng(
      "containers",
      `ssh の利用者の uid/gid を読めません: ${tail(ids)}`,
    );
  const compose = siteCompose(n, { uid: m[1]!, gid: m[2]! });
  const nginx = siteNginx(n);
  const r = await runScript(ctx.exec, siteContainersScript(n, compose, nginx), TIMEOUTS.composeUp);
  if (r.code !== 0 || !r.stdout.includes("containers-ok"))
    return ng(
      "containers",
      `サイトのコンテナが起動しません: ${tail(r)}。\`agentcms deploy doctor\` で PHP のイメージと edge を確かめてください`,
    );
  const warnPw = /db-password-missing: (.*)/.exec(r.stdout)?.[1];
  return ok(
    "containers",
    `${n.appContainer} / ${n.webContainer} / ${n.schedulerContainer} / ${n.backupContainer}${warnPw ? `（⚠ ${warnPw}）` : ""}`,
  );
}

/**
 * サイトのコンテナを置いて起こす台本（siteContainers）。
 *
 * - compose・nginx の設定・夜のバックアップの台本と DB のパスワードは control/<site>（サイトのコンテナから
 *   見えない）に置く。サイトの置き場所に置くと、サイトが書き換えた compose を docker compose が読む・
 *   symlink を辿って別の場所へ書く・mount の元を差し替えられる
 * - 夜のバックアップの DB のパスワードは、setup が作ったとき control に置く。前の版で作ったサイトは
 *   ここで 1 回だけ shared/.env から写す（置き場所だけを入れたコンテナの中で読み、形を確かめる。
 *   入るのは**このサイトの利用者**のパスワードだけ — 違う値なら、夜のバックアップが「db-auth」で落ちて知らせる）
 * - mount の元（台本・パスワード・backup/）は、compose を起こす前に作る（無いと Docker が root の持ち物の
 *   ディレクトリを作る）
 */
export function siteContainersScript(
  n: VpsNames,
  compose: string,
  nginx: string,
): string {
  const c = n.controlDir;
  return `set -euo pipefail
${siteFsFns(n)}
mkdir -p ${shq(c + "/backup")}
chmod 700 ${shq(c)} ${shq(c + "/backup")}
cat > ${shq(c + "/nginx.conf.tmp")} << 'AGENTCMS_NGINX'
${nginx}AGENTCMS_NGINX
chmod 644 ${shq(c + "/nginx.conf.tmp")}
mv -f ${shq(c + "/nginx.conf.tmp")} ${shq(c + "/nginx.conf")}
cat > ${shq(c + "/backup.sh.tmp")} << 'AGENTCMS_BACKUP'
${backupScript(n)}AGENTCMS_BACKUP
chmod 600 ${shq(c + "/backup.sh.tmp")}
mv -f ${shq(c + "/backup.sh.tmp")} ${shq(c + "/backup.sh")}
pwf=${shq(c + "/db-password")}
if [ -L "$pwf" ] || { [ -e "$pwf" ] && [ ! -f "$pwf" ]; }; then rm -rf "$pwf"; fi
if [ ! -s "$pwf" ]; then
  umask 077
  p=$(in_site 'grep -E "^DB_PASSWORD=" shared/.env 2>/dev/null | tail -1 | cut -d= -f2- | head -c 200' || true)
  if [[ "$p" =~ ^[A-Za-z0-9._~@%+=-]{8,128}$ ]]; then printf '%s' "$p" > "$pwf.tmp"; else : > "$pwf.tmp"; echo "db-password-missing: shared/.env の DB_PASSWORD を読めないか形が違うので、夜のバックアップは「no-db-password」で止まります"; fi
  chmod 600 "$pwf.tmp"; mv -f "$pwf.tmp" "$pwf"
fi
cat > ${shq(c + "/compose.yml.tmp")} << 'AGENTCMS_COMPOSE'
${compose}AGENTCMS_COMPOSE
mv -f ${shq(c + "/compose.yml.tmp")} ${shq(c + "/compose.yml")}
docker compose -f ${shq(c + "/compose.yml")} up -d --remove-orphans --quiet-pull 2>&1 | tail -20
# Caddy をこのサイトの web 用のネットワークへ（web にはほかのサイトのコンテナから届かない）
${CADDY_JOIN_FN}
caddy_join ${shq(n.webNetwork)}
# nginx の設定を確かめてから読み直させる（中身が変わってもコンテナは作り直されないことがある）。
# 通らなければ、Caddy の行を差し替える前にここで止まる
for i in $(seq 1 30); do
  if [ "$(docker inspect -f '{{.State.Running}}' ${shq(n.webContainer)} 2>/dev/null)" = true ]; then break; fi
  sleep 1
done
docker exec ${shq(n.webContainer)} nginx -t 2>&1 | tail -3
docker exec ${shq(n.webContainer)} nginx -s reload >/dev/null 2>&1 || true
for i in $(seq 1 30); do
  if [ "$(docker inspect -f '{{.State.Running}}' ${shq(n.appContainer)} 2>/dev/null)" = true ] && [ "$(docker inspect -f '{{.State.Running}}' ${shq(n.webContainer)} 2>/dev/null)" = true ] && docker exec ${shq(n.webContainer)} test -S ${FPM_SOCKET_IN_CONTAINER} 2>/dev/null; then
    # 前の版が置き場所に置いていた物を片付ける（コンテナの中で消す。symlink でも外は辿らない）
    in_site 'rm -rf run compose.yml nginx.conf .agentcms-site releases.history .agentcms-deploy.lock shared/.env.pending' || true
    # 前の版のソケットの置き場所（control の中・サイトのコンテナが書けた）。いまは tmpfs なので使わない
    rm -rf ${shq(c + "/run")}
    echo containers-ok; exit 0
  fi
  sleep 1
done
docker logs --tail 20 ${shq(n.webContainer)} 2>&1
docker logs --tail 20 ${shq(n.appContainer)} 2>&1
exit 1`;
}

/**
 * DB バックアップ（vps）。mysqldump は MySQL のコンテナの中で流す。
 *
 * - DB 名は設定のサイト名から作った `sgk-<サイト名>` だけ（サイトの .env の値は使わない。
 *   MySQL のコンテナの中で root として動く mysqldump の引数に、サイトが決めた値を渡さない）
 * - root のパスワードはコンテナの中のファイルから読む（引数・環境変数の受け渡しに載せない）
 * - 書くのは、サイトの置き場所だけを入れたコンテナの中（backups が symlink でも外へ書かない）
 * - 成功は終了コード＋gzip の検査＋大きさの下限で見る（ファイルがあるだけで成功にしない）
 */
export function vpsBackupScript(n: VpsNames, releaseId: string): string {
  if (!/^\d{14}$/.test(releaseId))
    throw new Error(`release の番号の形が違います: ${releaseId}`);
  return `set -euo pipefail
[[ ${shq(n.dbName)} =~ ${DB_NAME_SHELL_RE} ]] || { echo "name-odd"; exit 1; }
${siteFsFns(n)}
docker exec ${MYSQL_CONTAINER} sh -c 'MYSQL_PWD="$(cat /run/agentcms/mysql-root)" exec mysqldump --single-transaction --no-tablespaces -uroot "$1"' sh ${shq(n.dbName)} | gzip | in_site_in 'set -eu
mkdir -p backups
t=$(mktemp backups/.db.XXXXXX)
cat > "$t"
gzip -t "$t"
size=$(wc -c < "$t")
if [ "$size" -lt 200 ]; then rm -f "$t"; echo "backup-too-small:$size"; exit 1; fi
mv -f "$t" backups/${releaseId}-db.sql.gz
# このときに出ていた release を横に残す（DB を戻すとき、コードも同じ版へ合わせるため）
if [ -L current ]; then basename "$(readlink current)" > backups/${releaseId}-db.release; fi
echo "backup-ok:$size"'`;
}

export async function backupDatabaseVps(
  ctx: DeployContext,
): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const r = await runScript(
    ctx.exec,
    vpsBackupScript(n, ctx.releaseId),
    300_000,
  );
  if (r.code !== 0 || !r.stdout.includes("backup-ok"))
    return ng(
      "db-backup",
      `DB バックアップ失敗（安全のため deploy を中止）: ${tail(r, 300)}`,
    );
  return ok(
    "db-backup",
    `${n.appDir}/backups/${ctx.releaseId}-db.sql.gz (${r.stdout.trim().split("\n").pop()})`,
  );
}

// ---------------------------------------------------------------- release の記録・掃除・鍵

/** health まで通った release に付ける印 */
export const HEALTHY_MARK = ".agentcms-healthy";
/**
 * 公開の記録（health まで通った deploy を、公開した順に 1 行ずつ）。
 * rollback と掃除は、release の番号（手元の時計で付けた時刻）ではなく、この順で選ぶ。
 * 手元で build に時間がかかって後から公開された版や、時計のずれた手元から出した版でも、
 * 「実際に 1 つ前に出ていた版」へ戻る。
 */
export const HISTORY_FILE = "releases.history";

export async function markHealthy(ctx: DeployContext): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  // 印はサイトの release の中（置き場所の中で付ける）、記録は control（サイトから見えない）
  const r = await ctx.exec.exec(
    `${siteFsCommand(n, `touch ${shq(`releases/${ctx.releaseId}/${HEALTHY_MARK}`)}`)} < /dev/null && ` +
      `mkdir -p ${shq(n.controlDir)} && printf '%s\\n' ${shq(ctx.releaseId)} >> ${shq(`${n.controlDir}/${HISTORY_FILE}`)} && echo mark-ok`,
  );
  return r.code === 0 && r.stdout.includes("mark-ok")
    ? ok("mark-healthy")
    : ng("mark-healthy", tail(r));
}

/**
 * health まで通って公開された release の id（**公開した順の新しい順**・重なりなし・
 * ディレクトリが残っているものだけ）。途中で落ちた release は記録に無いので入らない —
 * **一度も公開できなかった版へ rollback しない**。
 */
export async function healthyReleases(
  exec: RemoteExecutor,
  cfg: DeployTargetConfig,
): Promise<string[] | null> {
  const n = vpsNames(cfg);
  // 記録は control（サイトから見えない）。印があるかは、置き場所だけを入れたコンテナの中で見る。
  // コンテナへ渡すのは番号の形（14 桁）の行だけ
  const r = await exec.exec(
    `cd ${shq(n.controlDir)} 2>/dev/null || { echo history-none; exit 0; }; [ -f ${HISTORY_FILE} ] || { echo history-none; exit 0; }; ` +
      // 読めなければここで止まる（パイプの途中の失敗は sh では拾えないので、先に読み切る）
      `out=$(awk '{ l[NR] = $0 } END { for (i = NR; i > 0; i--) print l[i] }' ${HISTORY_FILE}) || exit 4; ` +
      `printf '%s\\n' "$out" | grep -E '^[0-9]{14}$' | ${siteFsCommand(n, `while IFS= read -r d; do if [ -f "releases/$d/${HEALTHY_MARK}" ]; then echo "$d"; fi; done; echo history-ok`, { stdin: true })}`,
  );
  // 読めなかったのか、まだ記録が無いのかを分ける（読めなかったのに空と読むと、掃除が戻り先を消す）
  if (r.code !== 0 || !/history-(ok|none)/.test(r.stdout)) return null;
  const seen = new Set<string>();
  return r.stdout
    .split("\n")
    .map((l) => l.trim())
    .filter((l) => /^\d{14}$/.test(l) && !seen.has(l) && seen.add(l));
}

/**
 * rollback の先：公開の記録で current の 1 つ前。current が記録に無ければ選ばない
 * （どこへ戻るのが正しいか分からないので、推測で動かさない）。
 */
export function rollbackTargetByHistory(
  history: string[],
  current: string | null,
): string | null {
  if (current === null) return null;
  const at = history.indexOf(current);
  return at === -1 ? null : (history[at + 1] ?? null);
}

/**
 * 古い release の掃除。current と、公開の記録の新しい順 keep 個を残し、ほかは消す
 * （途中で落ちた release も消す。残しておくと rollback 先と取り違える元になる）。
 */
export async function cleanupReleasesVps(
  ctx: DeployContext,
): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const keep = ctx.cfg.keepReleases;
  if (!Number.isInteger(keep) || keep < 1)
    return ng(
      "cleanup",
      `keepReleases が 1 以上の整数ではありません（${String(keep)}）。何も消していません`,
    );
  const history = await healthyReleases(ctx.exec, ctx.cfg);
  if (history === null)
    return ng("cleanup", "公開の記録を読めませんでした。何も消していません");
  const cur = await ctx.exec.exec(
    `basename "$(readlink ${shq(n.appDir + "/current")})"`,
  );
  const current = cur.stdout.trim();
  if (!/^\d{14}$/.test(current))
    return ng("cleanup", "current を読めませんでした。何も消していません");
  const keepSet = new Set([
    current,
    ...history.filter((id) => id !== current).slice(0, keep),
  ]);
  // 消すのは、置き場所だけを入れたコンテナの中（releases が symlink でも外のものは消さない）
  const keepList = [...keepSet].filter((id) => /^\d{14}$/.test(id)).join(" ");
  const r = await runScript(
    ctx.exec,
    `set -uo pipefail
${siteFsFns(n)}
in_site 'cd releases || exit 1
for d in $(ls -1 | grep -E "^[0-9]{14}$"); do
  case " ${keepList} " in *" $d "*) continue ;; esac
  rm -rf "$d"
done
rm -f tmp/*.tar.gz
echo cleanup-ok'
rm -f ${shq(n.controlDir + "/upload")}/*.tar.gz`,
  );
  return r.code === 0 && r.stdout.includes("cleanup-ok")
    ? ok("cleanup")
    : ng("cleanup", tail(r));
}

/** サイトごとの deploy の鍵（control/<site> の中・サイトのコンテナから見えない） */
const LOCK = "deploy.lock";

/**
 * サイトごとの鍵。deploy と rollback は、current を読む前から health・掃除が
 * 終わるまでこれを持つ（同じサイトへの 2 つの deploy が切り替えと切り戻しを
 * 取り合わない）。`mkdir` の成否で取るので、どの Linux でも動く。
 */
export async function acquireSiteLock(
  ctx: DeployContext,
  what: string,
): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const lock = `${n.controlDir}/${LOCK}`;
  const r = await ctx.exec.exec(
    `mkdir -p ${shq(n.controlDir)} && chmod 700 ${shq(n.controlDir)}; if mkdir ${shq(lock)} 2>/dev/null; then printf '%s %s\\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" ${shq(what)} > ${shq(lock + "/info")}; echo lock-ok; else echo "lock-busy: $(cat ${shq(lock + "/info")} 2>/dev/null)"; fi`,
  );
  if (r.code === 0 && r.stdout.includes("lock-ok")) return ok("lock");
  return ng(
    "lock",
    `このサイトでは別の deploy / rollback が動いています（${r.stdout.replace("lock-busy:", "").trim() || "始めた時刻は不明"}）。終わるのを待ってください。止まったまま残っているなら、ほかに動いていないことを確かめてから ${lock} を消してください`,
  );
}

export async function releaseSiteLock(ctx: DeployContext): Promise<void> {
  const n = vpsNames(ctx.cfg);
  await ctx.exec.exec(`rm -rf ${shq(`${n.controlDir}/${LOCK}`)}`);
}

/**
 * これから出す release の番号が、まだ使われていないか。番号は手元の時計の秒なので、
 * 2 つの手元から同じ秒に始めると重なりうる。重なったまま展開すると、展開の前に
 * 同じ名前のディレクトリを消す（current が指しているものでも）。**鍵を取ったあとに見る**。
 */
export async function releaseIdIsFree(ctx: DeployContext): Promise<StepResult> {
  const n = vpsNames(ctx.cfg);
  const r = await ctx.exec.exec(
    `if [ -e ${shq(`${n.appDir}/releases/${ctx.releaseId}`)} ]; then echo release-taken; else echo release-free; fi`,
  );
  if (r.code === 0 && r.stdout.includes("release-free"))
    return ok("release-id");
  return ng(
    "release-id",
    r.stdout.includes("release-taken")
      ? `release ${ctx.releaseId} は既にあります（別の手元から同じ秒に出した可能性）。何も変えずに止めました。もう一度流せば新しい番号で出します`
      : `release の置き場所を読めませんでした: ${tail(r)}`,
  );
}

/**
 * いまの current を**厳しく**読む。「まだ無い（初回）」と「読めなかった」を分ける。
 * 読めなかったのに初回と読むと、health が落ちたときに戻す先が無いまま進んでしまう。
 */
export async function readCurrent(
  ctx: DeployContext,
): Promise<
  { ok: true; target: string | null } | { ok: false; detail: string }
> {
  const n = vpsNames(ctx.cfg);
  const r = await ctx.exec.exec(
    `cd ${shq(n.appDir)} || exit 3; if [ -L current ]; then printf 'current=%s\\n' "$(readlink current)"; elif [ -e current ]; then echo current-not-link; else echo current-none; fi`,
  );
  if (r.code === 0 && r.stdout.includes("current-none"))
    return { ok: true, target: null };
  const m = /current=(.*)/.exec(r.stdout);
  if (r.code === 0 && m) {
    // current はサイトのコンテナが書き換えられる。この置き場所の release を指していなければ信じない
    // （戻す先・画面に出す直し方のコマンドに、サイトの決めた値を入れない）
    const target = m[1]!;
    const prefix = `${n.appDir}/releases/`;
    if (target.startsWith(prefix) && /^\d{14}$/.test(target.slice(prefix.length)))
      return { ok: true, target };
    return {
      ok: false,
      detail: `${n.appDir}/current がこのサイトの release（releases/<14 桁>）を指していません（${JSON.stringify(target.slice(0, 80))}）。何も変えずに止めました。ssh で current を正しい release へ向け直してください`,
    };
  }
  return {
    ok: false,
    detail: r.stdout.includes("current-not-link")
      ? `${n.appDir}/current が symlink ではありません（手で作られたディレクトリなど）。何も変えずに止めました`
      : `いまの release（current）を読めませんでした: ${tail(r)}。何も変えずに止めました`,
  };
}
