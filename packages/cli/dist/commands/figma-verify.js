/**
 * agentcms figma verify（SPEC §100 #39）。
 * Figma import の整合検証:
 *  1. Manifest fingerprint の再計算一致（改ざん・手動編集検出）
 *  2. Plan ↔ Manifest fingerprint 一致
 *  3. apply record（provenance）と生成物 hash の一致
 *  4. Component Registry の mapped target 実在
 *  5. secret 混入なし（manifest / plan / registry に figd_ が無い）
 *  6. Client Design Data の gitignore（cache / project.json が Git 管理外）
 *  7. HTTP 検証（app 起動時のみ: 生成 route が 200 / css / asset 到達）
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import { evaluateGeneratedFiles, loadApplyRecord } from "../importer/apply.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import { manifestFingerprint } from "../figma/normalize.js";
import { loadRegistry } from "../figma/registry.js";
import { figmaApplyRecordPath } from "./figma-apply.js";
import { figmaManifestPath } from "./figma-inspect.js";
import { figmaPlanPath } from "./figma-plan.js";
export async function figmaVerify() {
    const agentcmsDir = findAgentcmsDir();
    const projectRoot = findProjectRoot();
    if (!agentcmsDir || !projectRoot) {
        fail({
            what: "figma verify を実行できません",
            why: ".agentcms/ またはプロジェクトルートが見つかりません",
            fix: "SiteGrowKit のプロジェクト内で実行してください",
        });
        return 1;
    }
    const checks = [];
    const push = (name, status, detail) => {
        checks.push({ name, status, detail });
        if (status === "pass")
            ok(`${name}: ${detail}`);
        else if (status === "skip")
            info(`${name}: SKIP — ${detail}`);
        else
            warn(`${name}: FAIL — ${detail}`);
    };
    heading("figma verify");
    // 1. Manifest fingerprint
    const manifestPath = figmaManifestPath(agentcmsDir);
    let manifest = null;
    if (!existsSync(manifestPath)) {
        push("manifest", "fail", `${manifestPath} がありません（figma inspect 未実行）`);
    }
    else {
        manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
        const recomputed = manifestFingerprint(manifest);
        push("manifest fingerprint", recomputed === manifest.source.fingerprint ? "pass" : "fail", recomputed === manifest.source.fingerprint
            ? `一致（${recomputed.slice(0, 12)}…）`
            : "再計算と不一致 — manifest が手動編集されています。figma inspect を再実行してください");
    }
    // 2. Plan ↔ Manifest
    const planPath = figmaPlanPath(agentcmsDir);
    let plan = null;
    if (!existsSync(planPath)) {
        push("plan", "skip", "figma plan 未実行");
    }
    else if (manifest) {
        plan = JSON.parse(readFileSync(planPath, "utf8"));
        push("plan fingerprint", plan.manifest_fingerprint === manifest.source.fingerprint
            ? "pass"
            : "fail", plan.manifest_fingerprint === manifest.source.fingerprint
            ? "Manifest と一致"
            : "stale plan — figma plan を再実行してください");
    }
    // 3. apply record / provenance
    const record = loadApplyRecord(figmaApplyRecordPath(agentcmsDir));
    if (!record) {
        push("apply record", "skip", "figma apply 未実行");
    }
    else {
        if (manifest) {
            push("apply ↔ manifest", record.manifest_fingerprint === manifest.source.fingerprint
                ? "pass"
                : "fail", record.manifest_fingerprint === manifest.source.fingerprint
                ? "適用済み provenance が最新 Manifest と一致"
                : "apply 後に Manifest が更新されています（diff → plan → 再 apply を検討）");
        }
        // generated hash 一致 or 承認済み AI interpretation（記録 hash 一致）のみ PASS。
        // 未承認変更・記録後の再変更・stale 記録は FAIL（hash 保護は弱めない — Phase 6.1 Gap 3）
        const evaluation = evaluateGeneratedFiles(projectRoot, record);
        for (const b of evaluation.broken)
            warn(`  ${b.target}: ${b.why}`);
        push("生成物 hash", evaluation.broken.length === 0 ? "pass" : "fail", evaluation.broken.length === 0
            ? evaluation.interpreted.length === 0
                ? `${record.files.length} ファイルすべて一致`
                : `generated ${evaluation.matched.length} / 承認済み interpretation ${evaluation.interpreted.length}（記録 hash と一致）`
            : `${evaluation.broken.length} ファイルが欠落 / 未承認変更（figma interpret record で承認、または再 apply）`);
    }
    // 4. Component Registry
    const registry = loadRegistry(agentcmsDir);
    if (registry.components.length === 0) {
        push("component registry", "skip", "entries なし（figma plan 未実行）");
    }
    else {
        const mapped = registry.components.filter((c) => c.status === "mapped");
        const missing = mapped.filter((c) => c.code.target && !existsSync(join(projectRoot, c.code.target)));
        push("component registry", missing.length === 0 ? "pass" : "fail", missing.length === 0
            ? `${registry.components.length} entries（mapped ${mapped.length}）`
            : `mapped の code target が存在しない: ${missing.map((c) => c.component_id).join(", ")}`);
    }
    // 5. secret 混入なし
    const textTargets = [manifestPath, planPath].filter(existsSync);
    const leaked = textTargets.filter((p) => /figd_[A-Za-z0-9_-]{8,}(?!\*)/.test(readFileSync(p, "utf8").replaceAll("figd_***", "")));
    push("secret scan (figma)", leaked.length === 0 ? "pass" : "fail", leaked.length === 0
        ? "manifest / plan に token 混入なし"
        : `token らしき文字列: ${leaked.join(", ")} — 即時 revoke してください`);
    // 6. Client Design Data gitignore（SPEC §90）
    try {
        const tracked = execFileSync("git", ["ls-files"], {
            cwd: projectRoot,
            encoding: "utf8",
        }).split("\n");
        const agentRel = relative(projectRoot, agentcmsDir);
        const prefix = agentRel === "" || agentRel.startsWith("..") ? ".agentcms" : agentRel;
        const bad = tracked.filter((f) => f.includes(`cache/figma/`) ||
            f.endsWith(`${prefix}/figma/project.json`) ||
            f.endsWith(".agentcms/figma/project.json"));
        push("client data gitignore", bad.length === 0 ? "pass" : "fail", bad.length === 0
            ? "Raw Cache / project.json は Git 管理外"
            : `Client Design Data が Git 管理下: ${bad.join(", ")}（gitignore + git rm --cached）`);
    }
    catch {
        push("client data gitignore", "skip", "git リポジトリではありません");
    }
    // 7. HTTP（app 起動時のみ。対象 app の URL はプロジェクトの .env で判定 —
    //    別プロジェクトの起動中 app を誤検査しないため、APP_URL が無ければ skip）
    const appUrl = resolveAppUrl(projectRoot);
    if (!appUrl) {
        push("HTTP", "skip", "APP_URL が未設定（env または .env）のため対象なし");
    }
    else if (plan && record) {
        const base = appUrl;
        let reachable = true;
        try {
            await fetch(`${base}/`, { signal: AbortSignal.timeout(3000) });
        }
        catch {
            reachable = false;
        }
        if (!reachable) {
            push("HTTP", "skip", `${base} に接続できません（agentcms dev で起動後に再実行）`);
        }
        else {
            let failed = 0;
            for (const s of plan.screens) {
                try {
                    const res = await fetch(`${base}${s.route}`, {
                        signal: AbortSignal.timeout(10000),
                    });
                    if (res.status !== 200) {
                        failed++;
                        warn(`  ${s.route} → ${res.status}`);
                    }
                }
                catch {
                    failed++;
                    warn(`  ${s.route} → 接続エラー`);
                }
            }
            try {
                const css = await fetch(`${base}/figma/css/figma.css`, {
                    signal: AbortSignal.timeout(10000),
                });
                if (css.status !== 200) {
                    failed++;
                    warn(`  /figma/css/figma.css → ${css.status}`);
                }
            }
            catch {
                failed++;
            }
            push("HTTP", failed === 0 ? "pass" : "fail", failed === 0
                ? `${plan.screens.length} screen + css が 200`
                : `${failed} 件の HTTP エラー`);
        }
    }
    else {
        push("HTTP", "skip", "apply 未実行のため対象なし");
    }
    const failed = checks.filter((c) => c.status === "fail").length;
    heading("結果");
    info(`pass ${checks.filter((c) => c.status === "pass").length} / skip ${checks.filter((c) => c.status === "skip").length} / fail ${failed}`);
    if (failed > 0) {
        fail({
            what: "figma verify で問題を検出しました",
            why: checks
                .filter((c) => c.status === "fail")
                .map((c) => c.name)
                .join(", "),
            fix: "各 FAIL の指示に従って修正後、再実行してください",
        });
        return 1;
    }
    ok("figma verify: すべて通過");
    return 0;
}
/** 検査対象 app の URL。env APP_URL > プロジェクト .env の APP_URL。無ければ null（skip） */
function resolveAppUrl(projectRoot) {
    const fromEnv = process.env["APP_URL"]?.trim();
    if (fromEnv)
        return fromEnv;
    const envPath = join(projectRoot, ".env");
    if (!existsSync(envPath))
        return null;
    const m = /^APP_URL=(.+)$/m.exec(readFileSync(envPath, "utf8"));
    const value = m?.[1]?.trim();
    return value ? value : null;
}
