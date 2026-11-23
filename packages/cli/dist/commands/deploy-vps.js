import { buildArtifact, cleanupBuilds, newReleaseId } from "../deploy/build.js";
import { postHealthAction, runHealthChecks } from "../deploy/health.js";
import { cleanupReleases, currentTarget, ensureLayout, linkShared, migrate, optimize, pickRollbackTarget, switchCurrent, taxonomySync, uploadAndExtract, } from "../deploy/release.js";
import { backupDatabaseVps, runScript, siteContainers, siteRoute, vpsDoctor, vpsSetupActions, } from "../deploy/providers/vps.js";
import { vpsNames } from "../deploy/providers/vps-assets.js";
import { worstStatus } from "../deploy/types.js";
import { fail, heading, info, ok, warn } from "../ui.js";
export async function vpsDeployDoctor(cfg, exec, io) {
    heading(`agentcms deploy doctor — vps / ${cfg.target} (${exec.label()})`);
    const { capabilities } = await vpsDoctor(exec, cfg);
    io.printChecks(capabilities.checks);
    console.log("");
    if (worstStatus(capabilities.checks) === "FAIL") {
        fail({
            what: "デプロイ可能な状態ではありません",
            why: "上記の FAIL 項目を参照してください",
            fix: "直してからもう一度 `agentcms deploy doctor` を流してください",
        });
        return 1;
    }
    ok("すべての診断に合格しました");
    return 0;
}
export async function vpsDeploySetup(cfg, exec, io, opts) {
    const n = vpsNames(cfg);
    heading(`agentcms deploy setup — vps / ${cfg.target}（サイト ${n.site}・${n.domain}）`);
    const { actions, error } = await vpsSetupActions(exec, cfg);
    if (error) {
        fail({
            what: "サーバーの準備を始められません",
            why: error,
            fix: "docs/deployment/vps.md §2 を確かめてください",
        });
        return 1;
    }
    for (const a of actions) {
        if (a.satisfied)
            ok(`${a.describe} — ${a.current}`);
        else
            warn(`${a.describe} — 現状: ${a.current}`);
    }
    const pending = actions.filter((a) => !a.satisfied);
    if (pending.length === 0) {
        ok(`準備は済んでいます。\`agentcms deploy doctor -t ${cfg.target}\` → \`agentcms deploy staging -t ${cfg.target}\` へ進めます`);
        return 0;
    }
    console.log("");
    warn(`${pending.length} 件をサーバーで実行します（作るだけ・既にあるものは変えない）:`);
    for (const a of pending)
        console.log(`  - ${a.describe}`);
    if (!opts.yes && !(await io.confirm("実行しますか？"))) {
        warn("中止しました（サーバーへの変更なし）");
        return 1;
    }
    for (const a of pending) {
        info(`▶ ${a.describe}`);
        const r = await runScript(exec, a.script, a.timeoutMs);
        const okMark = `${a.key.replace("_", "-")}-`;
        if (r.code !== 0 || !r.stdout.includes(okMark)) {
            fail({
                what: `setup 失敗: ${a.key}`,
                why: `${`${r.stdout}\n${r.stderr}`.trim().slice(-600) || `exit ${r.code}`}${r.code !== 0 && !r.stderr ? `（${Math.round(a.timeoutMs / 60_000)} 分の上限に届いた可能性）` : ""}`,
                fix: "原因を直してから `agentcms deploy setup` をもう一度流してください（済んだものは飛ばします）",
            });
            return 1;
        }
        ok(`  ${a.key}`);
    }
    console.log("");
    ok(`setup 完了。DNS で ${n.domain} をこのサーバーへ向けてから、\`agentcms deploy doctor -t ${cfg.target}\` → \`agentcms deploy staging -t ${cfg.target}\``);
    info(`メール・Turnstile・Web Push の値は ${n.appDir}/shared/.env に ssh で書き足してください（手元へは持ってこない）`);
    return 0;
}
/** ステップを順に流し、落ちたらそこで止める。current を切り替えたかを返す */
async function runSteps(steps, previous) {
    let switched = false;
    for (const [label, run] of steps) {
        info(`▶ ${label}`);
        const r = await run();
        if (!r.ok) {
            fail({
                what: `deploy 失敗: ${label}`,
                why: r.detail ?? "-",
                fix: switched && previous
                    ? "current は新 release に切り替わっています。`agentcms deploy rollback` で旧 release へ戻せます"
                    : "current は切り替えていないため旧状態のままです。原因を直して再実行してください（同じ操作の再実行は安全です）",
            });
            return { ok: false, switched };
        }
        if (r.detail)
            info(`  ${r.detail}`);
        if (label === "current 切り替え")
            switched = true;
    }
    return { ok: true, switched };
}
export async function vpsDeploy(root, cfg, exec, io, approval, opts) {
    const n = vpsNames(cfg);
    heading(`agentcms deploy ${cfg.target} — vps（サイト ${n.site}）${opts.dryRun ? "（dry-run）" : ""}`);
    const releaseId = newReleaseId();
    info(`artifact を構築中…（release ${releaseId}）`);
    const built = await buildArtifact(root, releaseId);
    if (!built.ok) {
        fail(built.error);
        return 1;
    }
    const mb = ((built.stats?.sizeBytes ?? 0) / 1024 / 1024).toFixed(1);
    ok(`artifact: ${built.artifactPath}（${built.stats?.fileCount} files / ${mb} MB）`);
    if (opts.dryRun) {
        ok("dry-run のためここで終了します（サーバーへの変更なし）");
        return 0;
    }
    info("環境を診断中…");
    const { capabilities } = await vpsDoctor(exec, cfg);
    if (worstStatus(capabilities.checks) === "FAIL") {
        io.printChecks(capabilities.checks);
        fail({
            what: "環境診断に失敗したため deploy を中止しました",
            why: "FAIL 項目があります",
            fix: "`agentcms deploy doctor` の指示に従って直してください",
        });
        return 1;
    }
    if (approval.kind === "ask" &&
        !(await io.confirm(`${exec.label()} のサイト ${n.site}（${n.domain}）へ release ${releaseId} を出します。続行しますか？`))) {
        warn("中止しました");
        return 1;
    }
    const ctx = { cfg, exec, phpBin: "php", releaseId, log: info };
    const previous = await currentTarget(ctx);
    const rel = `${n.appDir}/releases/${releaseId}`;
    const steps = [
        ["レイアウト作成 + shared/.env 検査", () => ensureLayout(ctx)],
        ["Caddy のサイトの行（確かめてから差し替え）", () => siteRoute(ctx)],
        [
            "artifact アップロード + 展開",
            () => uploadAndExtract(ctx, built.artifactPath),
        ],
        ["shared リンク（storage / .env）", () => linkShared(ctx)],
        ["サイトのコンテナ（PHP-FPM / scheduler）", () => siteContainers(ctx)],
        ["DB バックアップ", () => backupDatabaseVps(ctx)],
        ["DB migration", () => migrate(ctx)],
        ["分類定義の同期（taxonomy-sync）", () => taxonomySync(ctx)],
        ["config / view キャッシュ", () => optimize(ctx)],
        ["current 切り替え", () => switchCurrent(ctx, rel)],
    ];
    const run = await runSteps(steps, previous);
    if (!run.ok)
        return 1;
    info("▶ health check");
    const checks = await runHealthChecks(cfg.app.url, cfg.app.healthPath, cfg.target);
    io.printChecks(checks);
    const action = postHealthAction(checks, previous ?? null);
    if (action.kind !== "continue") {
        if (action.kind === "rollback") {
            warn("health check に失敗したため旧 release へ切り戻します…");
            const rb = await switchCurrent(ctx, action.to);
            if (rb.ok)
                warn(`旧 release へ切り戻しました（${action.to}）。DB migration は自動では戻しません — 必要なら ${n.appDir}/backups/${releaseId}-db.sql.gz から手動で戻してください`);
            else
                fail({
                    what: "自動切り戻しにも失敗しました",
                    why: rb.detail ?? "-",
                    fix: `ssh でサーバーに入り、ln -sfn ${action.to} ${n.appDir}/current を流してください`,
                });
        }
        else {
            fail({
                what: "health check の結果、切り替えを完了できません（戻す先がありません）",
                why: checks.length === 0
                    ? "health を 1 つも実行できませんでした"
                    : "上記の FAIL 項目を参照してください（初回デプロイのため旧 release がありません）",
                fix: `DNS が ${n.domain} をこのサーバーへ向けているか、${n.appDir}/shared/storage/logs/ を確かめてください`,
            });
        }
        return 1;
    }
    await cleanupReleases(ctx);
    cleanupBuilds(root);
    console.log("");
    ok(`deploy 完了: release ${releaseId} が ${cfg.app.url} で稼働中です`);
    return 0;
}
export async function vpsRollback(cfg, exec, io, approval) {
    const n = vpsNames(cfg);
    heading(`agentcms deploy rollback — vps / ${cfg.target}（サイト ${n.site}）`);
    const ctx = {
        cfg,
        exec,
        phpBin: "php",
        releaseId: "rollback",
        log: info,
    };
    const cur = await currentTarget(ctx);
    if (!cur) {
        fail({
            what: "rollback できません",
            why: "current が存在しません（未デプロイ）",
            fix: `\`agentcms deploy staging -t ${cfg.target}\` で初回デプロイしてください`,
        });
        return 1;
    }
    const list = await exec.exec(`ls -1 ${n.appDir}/releases | sort -r | head -50`);
    const curId = cur.split("/").pop() ?? null;
    const prev = pickRollbackTarget(list.stdout.split("\n").filter(Boolean), curId);
    if (!prev) {
        fail({
            what: "rollback 先がありません",
            why: `releases に ${curId} より古い release がありません`,
            fix: "旧 release が cleanup 済みです。再デプロイで復旧してください",
        });
        return 1;
    }
    warn(`current: ${curId} → ${prev} へ切り戻します。DB migration は自動では戻しません`);
    if (approval.kind === "ask" && !(await io.confirm("続行しますか？"))) {
        warn("中止しました");
        return 1;
    }
    const rb = await switchCurrent(ctx, `${n.appDir}/releases/${prev}`);
    if (!rb.ok) {
        fail({
            what: "切り戻し失敗",
            why: rb.detail ?? "-",
            fix: "ssh で current を確かめてください",
        });
        return 1;
    }
    const checks = await runHealthChecks(cfg.app.url, cfg.app.healthPath, cfg.target);
    io.printChecks(checks);
    if (checks.some((c) => c.status === "FAIL"))
        return 1;
    ok(`rollback 完了: ${prev} が稼働中です`);
    return 0;
}
