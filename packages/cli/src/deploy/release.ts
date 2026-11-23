import type { RemoteExecutor } from "./executor.js";
import { shq } from "./executor.js";
import type { DeployTargetConfig, StepResult } from "./types.js";

/**
 * release directory 方式のデプロイ工程（ADR-0008）。
 *
 * appDir/
 *   releases/<id>/   … 展開済み artifact（自己完結: vendor / public/build 同梱）
 *   shared/.env      … 人間がサーバー上で管理する唯一の秘密情報置き場
 *   shared/storage/  … 永続 storage（release 間で共有）
 *   backups/         … DB バックアップ（rollback point）
 *   current -> releases/<id>
 *
 * docroot（public_html）は entry_stub 方式:
 * 実ディレクトリのまま index.php スタブ + 公開アセット同期。
 * docroot 自体の symlink 化は XServer 公式見解がないため既定では行わない。
 */

/**
 * `dir` に入ってコマンドを流すリモートのコマンド行。
 *
 * - `remote.container` が無い（xserver）：ssh の先で `cd dir && cmd`
 * - ある（vps）：ホストに PHP は無い。そのコンテナの中で同じパスに入って流す
 *   （コンテナにはサイトのディレクトリが**同じ絶対パス**で入っている・ADR-0021）
 */
export function inDir(
  cfg: DeployTargetConfig,
  dir: string,
  cmd: string,
): string {
  const container = cfg.remote.container;
  if (container)
    return `docker exec -w ${shq(dir)} ${shq(container)} sh -c ${shq(cmd)}`;
  return `cd ${shq(dir)} && ${cmd}`;
}

export interface DeployContext {
  cfg: DeployTargetConfig;
  exec: RemoteExecutor;
  phpBin: string;
  releaseId: string;
  log: (msg: string) => void;
}

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

/**
 * docroot の `index.php` が**自分が置いたスタブか**を見分ける目印（B-41）。
 *
 * `publishDocroot()` は `cp` で `index.php` を置き換える。**ファイルの backup は無い**ので、
 * 他人のサイトの `index.php` を上書きすると戻せない。だが**前回の release が置いた
 * スタブは当然上書きしてよい**。その区別をこの 1 文字列で行う。
 *
 * **既に配ってあるスタブに入っている文字列であること**が条件なので、
 * ここを書き換えると「自分が置いたものを他人だと読んで」2 回目のデプロイが止まる。
 * ASCII に限るのも意図的である（`grep` をリモートへ送るため）。
 */
export const ENTRY_STUB_MARKER = "Agent CMS Kit";

/** docroot に置く index.php スタブ（テンプレートの index.php と同じ boot 手順） */
export function entryStubPhp(appDir: string): string {
  return `<?php

// ${ENTRY_STUB_MARKER} — XServer entry stub（agentcms deploy が生成。手動編集しない）
// docroot は実ディレクトリのまま、アプリ本体は current release を参照する（ADR-0008）
//
// realpath() で current symlink を実 release パスへ解決してから boot する。
// symlink パスのまま起動すると全 release が同一絶対パスを共有し、
// OPcache / コンパイル済みビューのキーが衝突して旧 release のバイトコードが
// 配信され続ける（共用サーバーでは FPM を再起動できない — 実機で確認した罠）。

use Illuminate\\Foundation\\Application;
use Illuminate\\Http\\Request;

define('LARAVEL_START', microtime(true));

// 常駐PHPのrealpathキャッシュを破棄し、切り替え直後から現在のreleaseを読む。
clearstatcache(true);
$current = realpath('${appDir}/current') ?: '${appDir}/current';

if (file_exists($maintenance = $current.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $current.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $current.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
`;
}

/**
 * DB バックアップスクリプト（サーバー上で bash 実行）。
 * 認証情報は shared/.env から読み、引数・ログへ出さない（MYSQL_PWD 環境変数渡し）。
 * 成功判定は exit code + gzip 整合性 + サイズ下限（存在だけで成功にしない）。
 */
export function backupScript(appDir: string, releaseId: string): string {
  return `set -euo pipefail
cd ${shq(appDir)}
envval() { grep -E "^$1=" shared/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
DB_HOST=$(envval DB_HOST)
DB_DATABASE=$(envval DB_DATABASE)
DB_USERNAME=$(envval DB_USERNAME)
DB_PASSWORD=$(envval DB_PASSWORD)
mkdir -p backups
out="backups/${releaseId}-db.sql.gz"
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --no-tablespaces -h "$DB_HOST" -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$out"
gzip -t "$out"
size=$(wc -c < "$out")
if [ "$size" -lt 200 ]; then echo "backup-too-small:$size"; exit 1; fi
echo "backup-ok:$size"
`;
}

/** レイアウト作成 + shared/.env の安全性検査（値は読まない。キーの存在と危険設定のみ） */
export async function ensureLayout(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec } = ctx;
  const a = cfg.remote.appDir;
  const mk = await exec.exec(
    `mkdir -p ${shq(a + "/releases")} ${shq(a + "/backups")} ${shq(a + "/tmp")} ` +
      `${shq(a + "/shared/storage/app/public")} ${shq(a + "/shared/storage/framework/cache/data")} ` +
      `${shq(a + "/shared/storage/framework/sessions")} ${shq(a + "/shared/storage/framework/views")} ` +
      `${shq(a + "/shared/storage/logs")} && chmod 700 ${shq(a)}`,
  );
  if (mk.code !== 0) return ng("layout", `ディレクトリ作成失敗: ${mk.stderr}`);

  const env = await exec.exec(
    `test -f ${shq(a + "/shared/.env")} && chmod 600 ${shq(a + "/shared/.env")} && ` +
      `grep -c '^APP_KEY=base64:' ${shq(a + "/shared/.env")} || echo missing`,
  );
  if (env.stdout.includes("missing") || env.code !== 0)
    return ng(
      "layout",
      cfg.provider === "vps"
        ? `shared/.env が未配置か APP_KEY が未設定です（${a}/shared/.env）。先に \`agentcms deploy setup -t ${cfg.target}\` を流してください（DB と .env をサーバーの上で作ります）`
        : `shared/.env が未配置か APP_KEY が未設定です（${a}/shared/.env）。docs/deployment/xserver.md の「環境設定」手順を先に実施してください`,
    );

  for (const [key, expected] of [
    ["APP_ENV", cfg.target],
    ["APP_DEBUG", "false"],
  ] as const) {
    const r = await exec.exec(
      `grep -E '^${key}=' ${shq(a + "/shared/.env")} | tail -1`,
    );
    if (!r.stdout.includes(`${key}=${expected}`))
      return ng(
        "layout",
        `shared/.env の ${key} が「${expected}」ではありません（現在: ${r.stdout || "未設定"}）。staging 安全性のため中止します`,
      );
  }
  return ok("layout");
}

export async function uploadAndExtract(
  ctx: DeployContext,
  artifactPath: string,
): Promise<StepResult> {
  const { cfg, exec, releaseId } = ctx;
  const a = cfg.remote.appDir;
  const remoteTar = `${a}/tmp/${releaseId}.tar.gz`;
  const up = await exec.upload(artifactPath, remoteTar);
  if (up.code !== 0) return ng("upload", `scp 失敗: ${up.stderr}`);

  const rel = `${a}/releases/${releaseId}`;
  // 再実行安全: 展開先を作り直してから展開する（半端な release を残さない）
  const ex = await exec.exec(
    `rm -rf ${shq(rel)} && mkdir -p ${shq(rel)} && tar -xzf ${shq(remoteTar)} -C ${shq(rel)} && test -f ${shq(rel + "/artisan")} && echo extract-ok`,
  );
  if (!ex.stdout.includes("extract-ok"))
    return ng("extract", `展開失敗: ${ex.stderr || ex.stdout}`);
  return ok("upload+extract", remoteTar);
}

export async function linkShared(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec, releaseId } = ctx;
  const a = cfg.remote.appDir;
  const rel = `${a}/releases/${releaseId}`;
  const r = await exec.exec(
    `rm -rf ${shq(rel + "/storage")} && ` +
      `ln -sfn ${shq(a + "/shared/storage")} ${shq(rel + "/storage")} && ` +
      `ln -sfn ${shq(a + "/shared/.env")} ${shq(rel + "/.env")} && ` +
      `mkdir -p ${shq(rel + "/public")} && ` +
      `ln -sfn ${shq(a + "/shared/storage/app/public")} ${shq(rel + "/public/storage")} && echo link-ok`,
  );
  if (!r.stdout.includes("link-ok"))
    return ng("shared-links", r.stderr || r.stdout);
  return ok("shared-links");
}

export async function backupDatabase(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec, releaseId } = ctx;
  const a = cfg.remote.appDir;
  const script = backupScript(a, releaseId);
  const w = await exec.exec(
    `cat > ${shq(a + `/tmp/backup-${releaseId}.sh`)} << 'AGENTCMS_EOS'\n${script}\nAGENTCMS_EOS`,
  );
  if (w.code !== 0) return ng("db-backup", `スクリプト配置失敗: ${w.stderr}`);
  const r = await exec.exec(`bash ${shq(a + `/tmp/backup-${releaseId}.sh`)}`, {
    timeoutMs: 300_000,
  });
  if (!r.stdout.includes("backup-ok"))
    return ng(
      "db-backup",
      `DB バックアップ失敗（安全のため deploy を中止）: ${r.stderr || r.stdout}。DB 未作成の場合はサーバーパネル > MySQL設定 で作成し、shared/.env を設定してください`,
    );
  return ok(
    "db-backup",
    `${a}/backups/${releaseId}-db.sql.gz (${r.stdout.trim()})`,
  );
}

export async function pendingMigrations(ctx: DeployContext): Promise<string[]> {
  const { cfg, exec, phpBin, releaseId } = ctx;
  const rel = `${cfg.remote.appDir}/releases/${releaseId}`;
  const r = await exec.exec(
    inDir(
      cfg,
      rel,
      `${shq(phpBin)} artisan migrate:status --pending 2>/dev/null`,
    ),
  );
  if (r.code !== 0) return [];
  return r.stdout
    .split("\n")
    .map((l) => l.trim())
    .filter((l) => /^\d{4}_\d{2}_\d{2}/.test(l))
    .map((l) => (l.split(/\s+/)[0] ?? "").replaceAll(".", ""))
    .filter(Boolean);
}

export async function migrate(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec, phpBin, releaseId } = ctx;
  const rel = `${cfg.remote.appDir}/releases/${releaseId}`;
  const r = await exec.exec(
    inDir(
      cfg,
      rel,
      `${shq(phpBin)} artisan migrate --force --no-interaction 2>&1`,
    ),
    { timeoutMs: 300_000 },
  );
  if (r.code !== 0)
    return ng(
      "migrate",
      `migration 失敗: ${(r.stdout + r.stderr).slice(-500)}。current は切り替えていないため旧 release が稼働中です。DB 復元が必要な場合は backups/${releaseId}-db.sql.gz を使用してください（自動復元はしません）`,
    );
  return ok("migrate");
}

/**
 * 分類定義の同期（Phase 9.3B / STAGE 1・SPEC §J.4 SY-2）。
 *
 * **migration 成功後の明示ステップ**として実行する。runtime からは実行しない。
 * 失敗は成功扱いにしない（current は切り替わらないため旧 release が稼働し続ける）。
 */
export async function taxonomySync(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec, phpBin, releaseId } = ctx;
  const rel = `${cfg.remote.appDir}/releases/${releaseId}`;
  const r = await exec.exec(
    inDir(cfg, rel, `${shq(phpBin)} artisan agentcms:taxonomy-sync 2>&1`),
    { timeoutMs: 120_000 },
  );
  if (r.code !== 0)
    return ng(
      "taxonomy-sync",
      `分類の同期に失敗しました: ${(r.stdout + r.stderr).slice(-500)}。分類は変更していません。current は切り替えていないため旧 release が稼働中です`,
    );
  return ok("taxonomy-sync");
}

export async function optimize(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec, phpBin, releaseId } = ctx;
  const rel = `${cfg.remote.appDir}/releases/${releaseId}`;
  // route:cache は closure ルートで失敗するため対象外（ADR-0008）
  const r = await exec.exec(
    inDir(
      cfg,
      rel,
      `${shq(phpBin)} artisan config:cache && ${shq(phpBin)} artisan view:cache 2>&1`,
    ),
  );
  if (r.code !== 0) return ng("optimize", (r.stdout + r.stderr).slice(-300));
  return ok("optimize");
}

/** 現在の current の指す先（rollback point）。未デプロイなら null */
export async function currentTarget(
  ctx: DeployContext,
): Promise<string | null> {
  const r = await ctx.exec.exec(
    `readlink ${shq(ctx.cfg.remote.appDir + "/current")} 2>/dev/null || echo ''`,
  );
  return r.stdout ? r.stdout.trim() : null;
}

export async function switchCurrent(
  ctx: DeployContext,
  releaseDir: string,
): Promise<StepResult> {
  const { cfg, exec } = ctx;
  const r = await exec.exec(
    `ln -sfn ${shq(releaseDir)} ${shq(cfg.remote.appDir + "/current")} && readlink ${shq(cfg.remote.appDir + "/current")}`,
  );
  if (!r.stdout.includes(releaseDir)) return ng("switch", r.stderr || r.stdout);
  return ok("switch", `current -> ${releaseDir}`);
}

/** docroot へ公開ファイルを反映（entry_stub 方式）。index.php を除いて同期→スタブ配置 */
export async function publishDocroot(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec } = ctx;
  const a = cfg.remote.appDir;
  const doc = cfg.remote.docroot;
  const stub = entryStubPhp(a);
  const w = await exec.exec(
    `cat > ${shq(a + "/tmp/index.php")} << 'AGENTCMS_EOS'\n${stub}\nAGENTCMS_EOS`,
  );
  if (w.code !== 0) return ng("publish", `スタブ配置失敗: ${w.stderr}`);
  const r = await exec.exec(
    `cd ${shq(a + "/current/public")} && ` +
      // index.php を除いた公開ファイルを docroot へ同期（旧アセットは残す＝旧 release の rollback 用）
      `tar -cf - --exclude='./index.php' --exclude='./storage' . | tar -xf - -C ${shq(doc)} && ` +
      `ln -sfn ${shq(a + "/shared/storage/app/public")} ${shq(doc + "/storage")} && ` +
      `cp ${shq(a + "/tmp/index.php")} ${shq(doc + "/index.php")} && echo publish-ok`,
  );
  if (!r.stdout.includes("publish-ok"))
    return ng("publish", r.stderr || r.stdout);
  return ok("publish", `docroot=${doc}`);
}

/** 旧 release の掃除（current が指す release と直近 keep 世代は残す） */
export async function cleanupReleases(ctx: DeployContext): Promise<StepResult> {
  const { cfg, exec } = ctx;
  const a = cfg.remote.appDir;
  const keep = cfg.keepReleases;
  const r = await exec.exec(
    `cur=$(readlink ${shq(a + "/current")} | xargs -n1 basename 2>/dev/null || echo ''); ` +
      `cd ${shq(a + "/releases")} && ls -1 | sort -r | awk -v cur="$cur" -v keep=${keep} ` +
      `'{ if ($0 == cur) next; n++; if (n > keep) print }' | xargs -r rm -rf; ` +
      `rm -f ${shq(a + "/tmp")}/*.tar.gz ${shq(a + "/tmp")}/backup-*.sh ${shq(a + "/tmp")}/index.php; echo cleanup-ok`,
  );
  if (!r.stdout.includes("cleanup-ok"))
    return ng("cleanup", r.stderr || r.stdout);
  return ok("cleanup");
}

/**
 * 切り戻す先を選ぶ（B-41 ⑤）。
 *
 * ## 「current 以外で一番新しいもの」ではない
 *
 * 直す前は `releases.find((r) => r !== curId)` だった。`releases` は**新しい順**なので、
 * これは「**current 以外で一番新しいもの**」を選ぶ。**1 回目は当たるが、2 回目に外す。**
 *
 * ```
 * releases: [B(壊れている), A(正常)]   current = B
 *   1 回目の rollback → B 以外で一番新しい = A   ✅
 *   current = A になる
 *   2 回目の rollback → A 以外で一番新しい = B   🔴 さっき捨てた壊れたほうへ戻る
 * ```
 *
 * **事故の上に事故を重ねる。** しかも **rollback は事故のときにしか使わない**ので、
 * **動かないことに気づけるのは事故の最中だけ**である。
 *
 * ## current より 1 つ古いもの
 *
 * **新しい方向へは絶対に行かない。** ただし **戻れなくもしない** —
 * current が一覧に無い（壊れた symlink・手で書き換えられた等）ときは、
 * **一番新しいものへ戻す**（何も選ばないより、動くものへ戻すほうがよい）。
 *
 * @param releases **新しい順**の release id
 * @param curId いま current が指している release id
 */
export function pickRollbackTarget(
  releases: string[],
  curId: string | null,
): string | null {
  const list = releases.filter(Boolean);

  if (list.length === 0) return null;

  if (curId === null) return list[0] ?? null;

  const at = list.indexOf(curId);

  // current が一覧に無い＝辿れない。**戻れなくしない**ので、一番新しいものへ
  if (at === -1) return list[0] ?? null;

  // **1 つ古いものだけ。** 新しい方向へは行かない
  return list[at + 1] ?? null;
}
