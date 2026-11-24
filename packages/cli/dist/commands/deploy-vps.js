import { buildArtifact, cleanupBuilds, newReleaseId } from "../deploy/build.js";
import { postHealthAction, runHealthChecks } from "../deploy/health.js";
import { ensureLayout, linkShared, migrate, optimize, switchCurrent, taxonomySync, } from "../deploy/release.js";
import { acquireSiteLock, backupDatabaseVps, cleanupReleasesVps, dbUserLimits, healthyReleases, markHealthy, readCurrent, rebuildReleaseFromArtifact, releaseIdIsFree, rollbackTargetByHistory, releaseSiteLock, restartSiteCode, runScript, siteContainers, siteFsContext, siteRoute, uploadAndExtractVps, vpsDoctor, vpsReleaseContext, vpsSetupActions, } from "../deploy/providers/vps.js";
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
    if (worstStatus(capabilities.checks) === "WARN")
        warn("WARN の項目があります（deploy はできます）。上の直し方を確かめてください");
    else
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
/**
 * deploy の工程（この順で流し、落ちたらそこで止まる）。
 *
 * **Caddy の行は、サイトの web が起きて設定が通ってから差し替える。** 先に差し替えると、
 * そのあとの送信や展開で落ちたとき、current は前の版のままなのに入口だけが
 * まだ無い web を向き、サイトが止まる（前の形から移るときに起きる）。
 * DB に触る前に差し替えるので、ここで落ちても DB はそのまま。
 */
export function vpsDeploySteps(ctx, artifactPath) {
    const n = vpsNames(ctx.cfg);
    const rel = `${n.appDir}/releases/${ctx.releaseId}`;
    // サイトの置き場所の中のファイル操作は、置き場所だけを入れたコンテナの中で流す（vps.ts の siteFsCommand）
    const fs = siteFsContext(ctx);
    return [
        ["release の番号が空いているか", () => releaseIdIsFree(ctx)],
        ["レイアウト作成 + shared/.env 検査", () => ensureLayout(fs)],
        // 送った artifact は control に残し、そこから標準入力で展開する（サイトの tmp/ を経由しない）
        ["artifact アップロード + 展開", () => uploadAndExtractVps(ctx, artifactPath)],
        ["shared リンク（storage / .env）", () => linkShared(fs)],
        [
            "サイトのコンテナ（PHP-FPM / web / scheduler）",
            () => siteContainers(ctx),
        ],
        ["Caddy のサイトの行（確かめてから差し替え）", () => siteRoute(ctx)],
        ["DB の利用者の同時接続の上限", () => dbUserLimits(ctx)],
        ["DB バックアップ", () => backupDatabaseVps(ctx)],
        ["DB migration", () => migrate(ctx)],
        ["分類定義の同期（taxonomy-sync）", () => taxonomySync(ctx)],
        ["config / view キャッシュ", () => optimize(ctx)],
        ["current 切り替え", () => switchCurrent(fs, rel)],
        // 乗っ取られたサイトがコンテナの中に残したプロセスを、出し直しで消す
        ["サイトのコンテナを起こし直す（app / scheduler）", () => restartSiteCode(ctx)],
    ];
}
export async function runSteps(steps, previous) {
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
    if (worstStatus(capabilities.checks) === "WARN")
        io.printChecks(capabilities.checks.filter((c) => c.status === "WARN"));
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
    const ctx = vpsReleaseContext({
        cfg,
        exec,
        phpBin: "php",
        releaseId,
        log: info,
    });
    const lock = await acquireSiteLock(ctx, `deploy ${releaseId}`);
    if (!lock.ok) {
        fail({
            what: "deploy を始められません",
            why: lock.detail ?? "-",
            fix: "しばらく待ってからもう一度流してください",
        });
        return 1;
    }
    try {
        return await deployLocked(ctx, root, built.artifactPath, io);
    }
    finally {
        await releaseSiteLock(ctx);
    }
}
async function deployLocked(ctx, root, artifactPath, io) {
    const { cfg, releaseId } = ctx;
    const n = vpsNames(cfg);
    const live = await readCurrent(ctx);
    if (!live.ok) {
        fail({
            what: "deploy を始められません",
            why: live.detail,
            fix: "ssh でつながるか・その場所を確かめてから、もう一度流してください",
        });
        return 1;
    }
    const previous = live.target;
    const run = await runSteps(vpsDeploySteps(ctx, artifactPath), previous);
    if (!run.ok)
        return 1;
    info("▶ health check");
    const checks = await runHealthChecks(cfg.app.url, cfg.app.healthPath, cfg.target);
    io.printChecks(checks);
    const action = postHealthAction(checks, previous ?? null);
    if (action.kind !== "continue") {
        if (action.kind === "rollback") {
            warn("health check に失敗したため旧 release へ切り戻します…");
            const rb = await switchCurrent(siteFsContext(ctx), action.to);
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
    // health まで通った印。rollback はこの印のある release にだけ戻る
    const mark = await markHealthy(ctx);
    if (!mark.ok)
        warn(`release に印を付けられませんでした（rollback の候補に入りません）: ${mark.detail}`);
    // 印を付けられなかったときは掃除しない（記録の無い今の版を、残す数に数えられない）
    if (mark.ok) {
        const clean = await cleanupReleasesVps(ctx);
        if (!clean.ok)
            warn(`古い release を片付けられませんでした: ${clean.detail}`);
    }
    cleanupBuilds(root);
    console.log("");
    ok(`deploy 完了: release ${releaseId} が ${cfg.app.url} で稼働中です`);
    return 0;
}
export async function vpsRollback(cfg, exec, io, approval) {
    const n = vpsNames(cfg);
    heading(`agentcms deploy rollback — vps / ${cfg.target}（サイト ${n.site}）`);
    const ctx = vpsReleaseContext({
        cfg,
        exec,
        phpBin: "php",
        releaseId: "rollback",
        log: info,
    });
    const lock = await acquireSiteLock(ctx, "rollback");
    if (!lock.ok) {
        fail({
            what: "rollback を始められません",
            why: lock.detail ?? "-",
            fix: "しばらく待ってからもう一度流してください",
        });
        return 1;
    }
    try {
        return await rollbackLocked(ctx, io, approval);
    }
    finally {
        await releaseSiteLock(ctx);
    }
}
/** 作り直しのあと止めておいたサイトのコンテナを起こす。起こせなければ知らせる（health が続けて見る） */
async function startAfterRebuild(ctx) {
    const r = await restartSiteCode(ctx);
    if (r.ok)
        info(`  ${r.detail}`);
    else
        warn(r.detail ?? "サイトのコンテナを起こせませんでした");
}
async function rollbackLocked(ctx, io, approval) {
    const { cfg, exec } = ctx;
    const n = vpsNames(cfg);
    const live = await readCurrent(ctx);
    if (!live.ok) {
        fail({
            what: "rollback できません",
            why: live.detail,
            fix: "ssh でつながるか・その場所を確かめてから、もう一度流してください",
        });
        return 1;
    }
    const cur = live.target;
    if (!cur) {
        fail({
            what: "rollback できません",
            why: "current が存在しません（未デプロイ）",
            fix: `\`agentcms deploy staging -t ${cfg.target}\` で初回デプロイしてください`,
        });
        return 1;
    }
    // 公開の記録で 1 つ前（health まで通った版だけ・公開した順）。途中で落ちた版へは戻らない
    const curId = cur.split("/").pop() ?? null;
    const history = await healthyReleases(exec, cfg);
    if (history === null) {
        fail({
            what: "rollback できません",
            why: `公開の記録（${n.controlDir}/releases.history）を読めませんでした`,
            fix: "ssh でつながるか・その場所が読めるかを確かめてから、もう一度流してください（何も変えていません）",
        });
        return 1;
    }
    const prev = rollbackTargetByHistory(history, curId);
    if (!prev) {
        fail({
            what: "rollback 先がありません",
            why: `公開の記録（${n.controlDir}/releases.history）に、${curId} の前に出ていて今も残っている release がありません`,
            fix: "再デプロイで復旧してください",
        });
        return 1;
    }
    warn(`current: ${curId} → ${prev} へ切り戻します。DB migration は自動では戻しません`);
    if (approval.kind === "ask" && !(await io.confirm("続行しますか？"))) {
        warn("中止しました");
        return 1;
    }
    // 戻す先は、置き場所の中の物（サイトが書き換えられる）ではなく、送った artifact から作り直す
    const rebuilt = await rebuildReleaseFromArtifact(ctx, prev);
    if (!rebuilt.ok) {
        fail({
            what: "戻す先を送った物から作り直せませんでした（current は変えていません）",
            why: rebuilt.detail ?? "-",
            fix: `${n.controlDir}/artifacts を確かめてください。分かったうえで置き場所の中の物のまま戻すなら ssh で ln -sfn ${n.appDir}/releases/${prev} ${n.appDir}/current`,
        });
        return 1;
    }
    if (rebuilt.rebuilt) {
        // 作り直しのあと app・scheduler は止まっている。キャッシュは使い捨てのコンテナで作り、
        // current を切り替えてから起こす（切り替える前に、いまのコードを起こし直さない）
        const rctx = { ...ctx, releaseId: prev };
        for (const [label, step] of [
            ["shared リンク", () => linkShared(siteFsContext(rctx))],
            ["config / view キャッシュ", () => optimize(rctx)],
        ]) {
            const r = await step();
            if (!r.ok) {
                await startAfterRebuild(ctx);
                fail({
                    what: `作り直した ${prev} の${label}に失敗しました（current は変えていません）`,
                    why: r.detail ?? "-",
                    fix: "原因を直してから、もう一度 rollback を流してください",
                });
                return 1;
            }
        }
        info(`  戻す先 ${prev} を、送った artifact から作り直しました（置き場所の中の古いコードは使いません）`);
    }
    else {
        // 置き場所の中の古い release はサイトが書き換えられる。確かめられない物へは黙って戻さない
        fail({
            what: `${prev} は送った artifact が残っていないので（前の版のキットで出した release）、作り直せません（current は変えていません）`,
            why: "置き場所の中の古い release は、サイトの中から書き換えられた可能性があり、確かめる物がありません",
            fix: `手元の正しいコードから \`agentcms deploy\` で出し直してください。分かったうえで置き場所の中の物のまま戻すなら ssh で ln -sfn ${n.appDir}/releases/${prev} ${n.appDir}/current`,
        });
        return 1;
    }
    const rb = await switchCurrent(siteFsContext(ctx), `${n.appDir}/releases/${prev}`);
    // 切り替えたら（切り替えに落ちても）、サイトのコードが動くコンテナを起こす（中のプロセスは全部新しくなる）
    await startAfterRebuild(ctx);
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
    if (checks.length === 0 || checks.some((c) => c.status === "FAIL")) {
        // 戻した先も通らないなら、分からない状態を増やさない — 元の release へ戻す
        const back = await switchCurrent(siteFsContext(ctx), cur);
        fail({
            what: `戻した先（${prev}）も health が通りませんでした`,
            why: back.ok
                ? `元の release（${curId}）へ戻しました`
                : `元の release へも戻せませんでした: ${back.detail ?? "-"}`,
            fix: back.ok
                ? `${n.appDir}/shared/storage/logs/ を見て原因を直し、もう一度 deploy してください`
                : `ssh で ln -sfn ${cur} ${n.appDir}/current を流してください`,
        });
        return 1;
    }
    ok(`rollback 完了: ${prev} が稼働中です`);
    return 0;
}
