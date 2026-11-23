import { createInterface } from "node:readline/promises";

import { loadDeployConfig } from "../deploy/config.js";
import { buildArtifact, cleanupBuilds, newReleaseId } from "../deploy/build.js";
import { SshExecutor, type RemoteExecutor } from "../deploy/executor.js";
import { postHealthAction, runHealthChecks } from "../deploy/health.js";
import { buildPlan, foreignTemplateFiles } from "../deploy/plan.js";
import {
  backupDatabase,
  cleanupReleases,
  currentTarget,
  ensureLayout,
  linkShared,
  migrate,
  optimize,
  pickRollbackTarget,
  publishDocroot,
  switchCurrent,
  taxonomySync,
  uploadAndExtract,
  type DeployContext,
} from "../deploy/release.js";
import {
  backupDocrootConflicts,
  detectPhpBin,
  xserverDoctor,
  xserverDocrootConflicts,
} from "../deploy/providers/xserver.js";
import {
  resolveApiKey,
  XserverApiHttp,
  type XserverApi,
} from "../deploy/providers/xserver-api.js";
import { detectSetupActions } from "../deploy/providers/xserver-setup.js";
import type {
  Capabilities,
  CapabilityCheck,
  DeployTargetConfig,
  StepResult,
} from "../deploy/types.js";
import { worstStatus } from "../deploy/types.js";
import { approveTarget, DEFAULT_TARGET } from "../deploy/target.js";
import { kitTemplateRefusal } from "../kit-template.js";
import { requireProject } from "./dev.js";
import {
  vpsDeploy,
  vpsDeployDoctor,
  vpsDeploySetup,
  vpsRollback,
} from "./deploy-vps.js";
import { vpsDoctor } from "../deploy/providers/vps.js";
import { fail, heading, info, ok, warn } from "../ui.js";

/** doctor / health の検査結果を PASS/WARN/FAIL で表示する */
function printChecks(checks: CapabilityCheck[]): void {
  for (const c of checks) {
    if (c.status === "PASS") ok(c.name);
    else if (c.status === "WARN") {
      warn(`${c.name}${c.what ? ` — ${c.what}` : ""}`);
      if (c.fix) console.log(`    対応: ${c.fix}`);
    } else {
      fail({
        what: c.what ?? c.name,
        why: c.why ?? "-",
        fix: c.fix ?? "-",
      });
    }
  }
}

interface DeploySetup {
  root: string;
  cfg: DeployTargetConfig;
  exec: RemoteExecutor;
}

function setup(target: string): DeploySetup | null {
  const root = requireProject();

  // **kit のひな形は案件ではない**（B-41）。
  // `template/` は artisan も docker-compose.yml も持つのでプロジェクトに見えるが、
  // ここから押すと配布物のひな形そのものを実サーバーへ載せることになる
  const refusal = kitTemplateRefusal(root);
  if (refusal) {
    fail(refusal);
    return null;
  }

  const { config, error } = loadDeployConfig(root, target);
  if (!config) {
    fail(error!);
    return null;
  }
  return { root, cfg: config, exec: new SshExecutor(config) };
}

/**
 * Control Plane（XServer API, ADR-0009）。キーは環境変数 XSERVER_API_KEY のみ。
 * 未設定なら null を返し、呼び出し側は手動手順へ fallback する。
 */
function resolveApi(cfg: DeployTargetConfig): XserverApi | null {
  const key = resolveApiKey();
  if (!key) return null;
  // API の {servername} は初期ドメイン形式（<サーバーID>.xsrv.jp）。実測で確認（ADR-0009）
  const servername = cfg.api?.servername ?? `${cfg.ssh.user}.xsrv.jp`;
  return new XserverApiHttp(servername, key);
}

/** `agentcms deploy doctor` — 実サーバー環境の診断（破壊的変更なし） */
export async function deployDoctor(target: string): Promise<number> {
  const s = setup(target);
  if (!s) return 1;
  if (s.cfg.provider === "vps")
    return vpsDeployDoctor(s.cfg, s.exec, { printChecks, confirm });
  heading(
    `agentcms deploy doctor — ${s.cfg.provider} / ${target} (${s.exec.label()})`,
  );
  const { capabilities } = await xserverDoctor(
    s.exec,
    s.cfg,
    resolveApi(s.cfg),
  );
  printChecks(capabilities.checks);
  console.log("");
  const status = worstStatus(capabilities.checks);
  if (status === "FAIL") {
    fail({
      what: "デプロイ可能な状態ではありません",
      why: "上記の FAIL 項目を参照してください",
      fix: "修正後にもう一度 `agentcms deploy doctor` を実行してください",
    });
    return 1;
  }
  ok(
    status === "WARN"
      ? "WARN はありますがデプロイ可能です（上記の対応を確認してください）"
      : "すべての診断に合格しました",
  );
  return 0;
}

/** `agentcms deploy plan` — 変更内容の事前提示（秘密情報なし） */
export async function deployPlan(
  target: string,
  opts: { offline?: boolean } = {},
): Promise<number> {
  const s = setup(target);
  if (!s) return 1;
  heading(`agentcms deploy plan — ${target}`);

  let caps: Capabilities | null = null;
  let exec: RemoteExecutor | null = null;
  if (!opts.offline) {
    exec = s.exec;
    info(`リモート状態を取得中… (${s.exec.label()})`);
    caps =
      s.cfg.provider === "vps"
        ? (await vpsDoctor(s.exec, s.cfg)).capabilities
        : (await xserverDoctor(s.exec, s.cfg, resolveApi(s.cfg))).capabilities;
  } else {
    info(
      "オフライン plan（リモート未接続。接続時は --offline を外してください）",
    );
  }

  const plan = await buildPlan(s.root, s.cfg, exec, caps);
  const mb = (plan.artifact.sizeBytes / 1024 / 1024).toFixed(1);

  console.log(`
target:        ${plan.target}（provider: ${plan.provider}）
app URL:       ${plan.appUrl}
release ID:    ${plan.releaseId}

[artifact]
  file:        ${plan.artifact.file}
  内容:        ${plan.artifact.fileCount} files / ${mb} MB（vendor・public/build 同梱）
  除外:        ${plan.artifact.excluded.join(" / ")}

[environment]
  APP_ENV:     ${plan.environment.appEnv}
  APP_DEBUG:   ${plan.environment.appDebug}
  shared/.env: ${
    plan.environment.sharedEnvKeys.length > 0
      ? `${plan.environment.sharedEnvKeys.length} キー（${plan.environment.sharedEnvKeys.join(", ")}）`
      : "未接続または未配置（値は plan に含めません）"
  }

[database]
  migrations:  ${plan.database.pendingMigrations.length} 件（${plan.database.source}）
${plan.database.pendingMigrations.map((m) => `    - ${m}`).join("\n") || "    （なし）"}
  destructive: ${plan.database.destructive ? "あり（内容を確認してください）" : "なし"}

[strategy]
  storage:     ${plan.storageStrategy}
  public:      ${
    plan.publicStrategy === "caddy_root"
      ? "caddy_root（Caddy が current/public を直接配信・PHP はサイトのコンテナ）"
      : `${plan.publicStrategy}（docroot は実ディレクトリ + index.php スタブ）`
  }
  docroot${plan.publicStrategy === "caddy_root" ? " / Caddy" : " 競合（DirectoryIndex: index.html > htm > shtml > cgi > php）"}:
${plan.docrootActions.map((a) => `    - ${a}`).join("\n")}
  symlink:
${plan.symlinkChanges.map((c) => `    - ${c}`).join("\n")}
  cron:
${plan.cronChanges.map((c) => `    - ${c}`).join("\n")}

[backup]
  DB:          ${plan.backup.database}
  rollback:    ${plan.backup.rollbackPoint}

[health check]
${plan.healthChecks.map((h) => `  - ${h}`).join("\n")}
`);

  /*
   * **別のテンプレートから来た生成物が残っていないか**（B-41 ⑥ / B-40 の機械検査）。
   *
   * 見た目を見て回っても出てこない — B-40 のときは**ナビから辿れず、
   * sitemap にだけ載っていた**。**hash を突き合わせるしかない。**
   *
   * ▷ **止めない。** 消すか差し替えるかは案件の判断で、**plan は何も変えない**コマンドである。
   *   ただし**黙らない** — 本番へ出た瞬間に indexed される。
   */
  const foreign = foreignTemplateFiles(s.root);

  if (foreign.length > 0) {
    warn(
      `別のテンプレートから来た生成物が ${foreign.length} 件あります（この案件の取り込み記録と fingerprint が違う）:`,
    );
    for (const f of foreign) {
      console.log(`    - ${f.path}（fingerprint ${f.fingerprint}）`);
    }
    console.log(
      "    本番へ出ると、この内容がそのまま公開・index されます。消すか、この案件の内容へ差し替えてください。",
    );
  }

  return 0;
}

async function confirm(question: string): Promise<boolean> {
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  const answer = await rl.question(`${question} [y/N]: `);
  rl.close();
  return answer.trim().toLowerCase() === "y";
}

/**
 * `agentcms deploy <target>` — 実デプロイ。--dry-run は artifact 生成までで止まる。
 *
 * **既定は staging のまま**で、staging 以外は `--confirm-target` で名指ししない限り
 * 進まない（`approveTarget`）。行き先の承認は**サーバーへ触る前**に済ませる。
 */
export async function deployStaging(
  target: string,
  opts: {
    dryRun?: boolean;
    yes?: boolean;
    confirmTarget?: string;
  },
): Promise<number> {
  const approval = approveTarget(target, opts);
  if (approval.kind === "refuse") {
    fail(approval);
    return 1;
  }
  const s = setup(target);
  if (!s) return 1;
  const { root, cfg, exec } = s;
  if (cfg.provider === "vps")
    return vpsDeploy(root, cfg, exec, { printChecks, confirm }, approval, opts);
  heading(`agentcms deploy ${target}${opts.dryRun ? "（dry-run）" : ""}`);

  // 1. artifact をローカルで構築（dry-run はここまで。外部書き込みなし）
  const releaseId = newReleaseId();
  info(`artifact を構築中…（release ${releaseId}）`);
  const built = await buildArtifact(root, releaseId);
  if (!built.ok) {
    fail(built.error!);
    return 1;
  }
  const mb = ((built.stats?.sizeBytes ?? 0) / 1024 / 1024).toFixed(1);
  ok(
    `artifact: ${built.artifactPath}（${built.stats?.fileCount} files / ${mb} MB）`,
  );
  if (opts.dryRun) {
    ok(
      "dry-run のためここで終了します（サーバーへの変更なし）。実行前に `agentcms deploy plan` を確認してください",
    );
    return 0;
  }

  // 2. 環境診断（FAIL があれば中止）
  info("環境を診断中…");
  const { capabilities } = await xserverDoctor(exec, cfg, resolveApi(cfg));
  if (worstStatus(capabilities.checks) === "FAIL" || !capabilities.phpBin) {
    printChecks(capabilities.checks);
    fail({
      what: "環境診断に失敗したため deploy を中止しました",
      why: "FAIL 項目があります",
      fix: "`agentcms deploy doctor` の指示に従って修正してください",
    });
    return 1;
  }

  // 3. docroot 競合検査（XServer の DirectoryIndex は index.html 等が index.php より優先）
  const conflicts = await xserverDocrootConflicts(exec, cfg);
  const blocked = conflicts.filter((c) => c.action === "block");
  if (blocked.length > 0) {
    fail({
      what: `docroot にそのままでは進めないファイルがあります: ${blocked.map((c) => c.file).join(", ")}`,
      why: blocked.map((c) => `${c.file}: ${c.reason}`).join(" / "),
      fix: `内容を確認し、不要なら SSH で ${cfg.remote.docroot} から退避・削除してから再実行してください（XServer 初期生成ページと判定できたものだけ自動退避します。既存の index.php は上書きしません）`,
    });
    return 1;
  }
  for (const c of conflicts) {
    info(`docroot 競合: ${c.file} — ${c.reason} → backups/ へ退避します`);
  }

  // 4. 確認（staging は --yes でスキップ可。staging 以外は --confirm-target も要る）
  if (approval.kind === "ask") {
    const yes = await confirm(
      `${exec.label()} の ${cfg.remote.appDir} へ release ${releaseId} をデプロイします。続行しますか？`,
    );
    if (!yes) {
      warn("中止しました");
      return 1;
    }
  }

  const ctx: DeployContext = {
    cfg,
    exec,
    phpBin: capabilities.phpBin,
    releaseId,
    log: info,
  };

  // 4. デプロイ工程（各ステップの失敗で安全に停止する）
  const previous = await currentTarget(ctx);
  const steps: Array<[string, () => Promise<StepResult>]> = [
    ["レイアウト作成 + shared/.env 検査", () => ensureLayout(ctx)],
    [
      "artifact アップロード + 展開",
      () => uploadAndExtract(ctx, built.artifactPath!),
    ],
    ["shared リンク（storage / .env）", () => linkShared(ctx)],
    ["DB バックアップ", () => backupDatabase(ctx)],
    ["DB migration", () => migrate(ctx)],
    ["分類定義の同期（taxonomy-sync）", () => taxonomySync(ctx)],
    ["config / view キャッシュ", () => optimize(ctx)],
    [
      "current 切り替え",
      () => switchCurrent(ctx, `${cfg.remote.appDir}/releases/${releaseId}`),
    ],
    [
      "docroot 競合ファイル退避（XServer 初期生成ページ）",
      async () => {
        const r = await backupDocrootConflicts(exec, cfg, conflicts, releaseId);
        return { step: "docroot-conflicts", ok: r.ok, detail: r.detail };
      },
    ],
    ["docroot 反映（entry_stub）", () => publishDocroot(ctx)],
  ];

  let switched = false;
  for (const [label, run] of steps) {
    info(`▶ ${label}`);
    const r = await run();
    if (!r.ok) {
      fail({
        what: `deploy 失敗: ${label}`,
        why: r.detail ?? "-",
        fix:
          switched && previous
            ? "current は新 release に切り替わっています。`agentcms deploy rollback` で旧 release へ戻せます"
            : "current は切り替えていないため旧状態のままです。原因を修正して再実行してください（同じ操作の再実行は安全です）",
      });
      return 1;
    }
    if (r.detail) info(`  ${r.detail}`);
    if (label === "current 切り替え") switched = true;
  }

  // 5. health check（失敗したら自動で旧 release へ戻す）
  info("▶ health check");
  const checks = await runHealthChecks(
    cfg.app.url,
    cfg.app.healthPath,
    cfg.target,
  );
  printChecks(checks);
  // **次の一手は `postHealthAction` が決める**（判断だけを試験で固定してある — B-12）
  const action = postHealthAction(checks, previous ?? null);
  if (action.kind !== "continue") {
    if (action.kind === "rollback") {
      const previous = action.to;
      warn("health check に失敗したため旧 release へ切り戻します…");
      const rb = await switchCurrent(ctx, previous);
      const pub = rb.ok ? await publishDocroot(ctx) : rb;
      if (rb.ok && pub.ok) {
        warn(
          `旧 release へ切り戻しました（${previous}）。DB migration は自動では戻しません — 必要なら backups/${releaseId}-db.sql.gz から手動復元してください`,
        );
      } else {
        fail({
          what: "自動切り戻しにも失敗しました",
          why: (rb.ok ? pub.detail : rb.detail) ?? "-",
          fix: `SSH でサーバーに入り、current を ${previous} へ手動で向けてください`,
        });
      }
    } else {
      fail({
        what: "health check の結果、切り替えを完了できません（戻す先がありません）",
        why:
          checks.length === 0
            ? "health を 1 つも実行できませんでした（測れていない状態を「通った」とは読みません）"
            : "上記の FAIL 項目を参照してください（初回デプロイのため旧 release がありません）",
        fix: "サーバーの storage/logs/laravel.log を確認し、修正後に再デプロイしてください",
      });
    }
    return 1;
  }

  // 6. 掃除（古い release / ローカル build）
  await cleanupReleases(ctx);
  cleanupBuilds(root);

  console.log("");
  ok(`deploy 完了: release ${releaseId} が ${cfg.app.url} で稼働中です`);
  info(
    "Cron 未登録の場合は `agentcms deploy plan` の Cron コマンドをサーバーパネルへ登録してください",
  );
  return 0;
}

/** `agentcms deploy rollback` — current を直前の release へ戻す（DB は対象外） */
export async function deployRollback(
  target: string,
  opts: { yes?: boolean; confirmTarget?: string },
): Promise<number> {
  const approval = approveTarget(target, opts);
  if (approval.kind === "refuse") {
    fail(approval);
    return 1;
  }
  const s = setup(target);
  if (!s) return 1;
  const { cfg, exec } = s;
  if (cfg.provider === "vps")
    return vpsRollback(cfg, exec, { printChecks, confirm }, approval);
  heading(`agentcms deploy rollback — ${target}`);

  const { capabilities } = await xserverDoctor(exec, cfg);
  if (!capabilities.phpBin) {
    fail({
      what: "PHP CLI を検出できません",
      why: "rollback 後のキャッシュ再生成に必要です",
      fix: "`agentcms deploy doctor` を確認してください",
    });
    return 1;
  }
  const ctx: DeployContext = {
    cfg,
    exec,
    phpBin: capabilities.phpBin,
    releaseId: "rollback",
    log: info,
  };

  const cur = await currentTarget(ctx);
  if (!cur) {
    fail({
      what: "rollback できません",
      why: "current が存在しません（未デプロイ）",
      fix: "`agentcms deploy staging` で初回デプロイしてください",
    });
    return 1;
  }
  /*
   * **`head -5` で切らない**（B-41 ⑤）。
   *
   * current が 5 番目に在ると、**1 つ古いものが一覧の外へ落ちる** —
   * 実際には残っているのに「戻り先がありません」と言うことになる。
   * `keepReleases` は 3〜5 なので全部並べても軽い（上限は暴走よけ）。
   */
  const list = await exec.exec(
    `ls -1 ${cfg.remote.appDir}/releases | sort -r | head -50`,
  );
  const releases = list.stdout.split("\n").filter(Boolean);
  const curId = cur.split("/").pop() ?? null;
  const prev = pickRollbackTarget(releases, curId);
  if (!prev) {
    fail({
      what: "rollback 先がありません",
      why: `releases に ${curId} より古い release がありません`,
      fix: "旧 release が cleanup 済みです。再デプロイで復旧してください",
    });
    return 1;
  }

  warn(
    `current: ${curId} → ${prev} へ切り戻します。DB migration は自動では戻しません（migration の内容によっては手動対応が必要です）`,
  );
  if (approval.kind === "ask" && !(await confirm("続行しますか？"))) {
    warn("中止しました");
    return 1;
  }

  // docroot 競合検査（rollback 中に index.html 等が置かれていたら安全側で停止）
  const rbConflicts = await xserverDocrootConflicts(exec, cfg);
  const rbBlocked = rbConflicts.filter((c) => c.action === "block");
  if (rbBlocked.length > 0) {
    fail({
      what: `docroot にそのままでは進めないファイルがあります: ${rbBlocked.map((c) => c.file).join(", ")}`,
      why: rbBlocked.map((c) => `${c.file}: ${c.reason}`).join(" / "),
      fix: `内容を確認し、SSH で ${cfg.remote.docroot} から退避・削除してください`,
    });
    return 1;
  }
  const rbBackup = await backupDocrootConflicts(
    exec,
    cfg,
    rbConflicts,
    `rollback-${prev}`,
  );
  if (!rbBackup.ok) {
    fail({
      what: "docroot 競合ファイルの退避に失敗",
      why: rbBackup.detail,
      fix: "SSH で手動確認してください",
    });
    return 1;
  }

  const rb = await switchCurrent(ctx, `${cfg.remote.appDir}/releases/${prev}`);
  if (!rb.ok) {
    fail({
      what: "切り戻し失敗",
      why: rb.detail ?? "-",
      fix: "SSH で手動確認してください",
    });
    return 1;
  }
  const pub = await publishDocroot(ctx);
  if (!pub.ok) {
    fail({
      what: "docroot 反映に失敗",
      why: pub.detail ?? "-",
      fix: "SSH で docroot の index.php を確認してください",
    });
    return 1;
  }
  const checks = await runHealthChecks(
    cfg.app.url,
    cfg.app.healthPath,
    cfg.target,
  );
  printChecks(checks);
  if (checks.some((c) => c.status === "FAIL")) return 1;
  ok(`rollback 完了: ${prev} が稼働中です`);
  return 0;
}

/** `agentcms verify staging` — デプロイ済み staging の外形検証（実メール・実 Push は送らない） */
export async function verifyStaging(): Promise<number> {
  const s = setup(DEFAULT_TARGET);
  if (!s) return 1;
  heading(`agentcms verify staging — ${s.cfg.app.url}`);
  const checks = await runHealthChecks(
    s.cfg.app.url,
    s.cfg.app.healthPath,
    s.cfg.target,
  );
  printChecks(checks);
  console.log("");
  const fails = checks.filter((c) => c.status === "FAIL").length;
  if (fails > 0) {
    fail({
      what: `${fails} 件の検証が失敗しました`,
      why: "上記を参照してください",
      fix: "修正後にもう一度 `agentcms verify staging` を実行してください",
    });
    return 1;
  }
  ok("staging 検証に合格しました");
  info(
    "Lighthouse 実測: `pnpm lighthouse:staging`（テスト条件を変えてスコアを整えないこと）。実メール・実 Push・実機 PWA は手動 Acceptance（docs/deployment/xserver.md）で確認してください",
  );
  return 0;
}

/**
 * `agentcms deploy setup` — Control Plane（XServer API）でサーバー前提条件を整える。
 * subdomain / SSL / MySQL DB・ユーザー・grant / shared/.env / Cron / PHP。
 * 作成のみ（冪等・既存はスキップ）。削除・リストアは自動化しない（ADR-0009）。
 */
export async function deploySetup(
  target: string,
  opts: { yes?: boolean },
): Promise<number> {
  const s = setup(target);
  if (!s) return 1;
  if (s.cfg.provider === "vps")
    return vpsDeploySetup(s.cfg, s.exec, { printChecks, confirm }, opts);
  const api = resolveApi(s.cfg);
  heading(`agentcms deploy setup — ${target}`);

  if (!api) {
    fail({
      what: "XServer API を利用できません（XSERVER_API_KEY 未設定）",
      why: "Control Plane 操作（SSL / DB / Cron 等の作成）には API キーが必要です",
      fix: "XServer アカウント管理画面 > APIキー管理 で発行し、環境変数 XSERVER_API_KEY へ設定してください（設定ファイル・Git に書かない）。手動で設定する場合は docs/deployment/xserver.md の手順に従ってください",
    });
    return 1;
  }

  info(`対象サーバー: ${api.servername} / 検出中…（API + SSH 読み取りのみ）`);
  const detected = await detectPhpBin(s.exec, s.cfg);
  const { actions, errors } = await detectSetupActions({
    cfg: s.cfg,
    api,
    exec: s.exec,
    phpBin: detected.phpBin ?? null,
    log: info,
  });
  for (const e of errors) warn(`検出時の警告: ${e}`);

  console.log("");
  for (const a of actions) {
    if (a.satisfied) ok(`${a.describe} — ${a.current}`);
    else warn(`${a.describe} — 現状: ${a.current}`);
  }
  const pending = actions.filter((a) => !a.satisfied);
  if (pending.length === 0) {
    console.log("");
    ok(
      "すべての前提条件が満たされています。`agentcms deploy doctor` → `agentcms deploy staging` へ進めます",
    );
    return 0;
  }

  console.log("");
  warn(`${pending.length} 件の操作を XServer API / SSH で実行します:`);
  for (const a of pending) console.log(`  - ${a.describe}`);
  if (!opts.yes) {
    const rl = createInterface({
      input: process.stdin,
      output: process.stdout,
    });
    const answer = await rl.question("実行しますか？ [y/N]: ");
    rl.close();
    if (answer.trim().toLowerCase() !== "y") {
      warn("中止しました（サーバーへの変更なし）");
      return 1;
    }
  }

  for (const a of pending) {
    info(`▶ ${a.describe}`);
    const r = await a.run();
    if (!r.ok) {
      fail({
        what: `setup 失敗: ${a.key}`,
        why: r.detail,
        fix: "原因を解消して `agentcms deploy setup` を再実行してください（完了済みの項目はスキップされます）。API で解決できない場合はサーバーパネルで手動設定してください",
      });
      return 1;
    }
    ok(`  ${r.detail}`);
  }

  console.log("");
  ok(
    "setup 完了。`agentcms deploy doctor` で全体を確認してから `agentcms deploy staging` を実行してください",
  );
  return 0;
}
