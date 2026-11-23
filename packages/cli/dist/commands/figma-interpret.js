/**
 * agentcms figma interpret（Phase 6.1 Gap 3 — AI Interpretation Provenance）。
 * deterministic apply の生成物への「承認済み AI interpretation」を provenance へ記録する。
 *
 *   generated → interpreted → (visual verification)
 *
 * - 記録対象は apply record にある deterministic 生成物のみ
 * - hash 保護は弱めない: figma verify は current hash が記録済み interpreted hash と
 *   一致するときのみ PASS する（記録後の再変更・stale 記録は FAIL）
 * - 実行 = 人（または人に承認されたエージェント）による interpretation の承認
 */
import { readFileSync } from "node:fs";
import { existsSync } from "node:fs";
import { evaluateGeneratedFiles, loadApplyRecord, recordInterpretation, saveApplyRecord, } from "../importer/apply.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import { figmaApplyRecordPath } from "./figma-apply.js";
import { figmaManifestPath } from "./figma-inspect.js";
export async function figmaInterpret(opts = {}) {
    const agentcmsDir = findAgentcmsDir();
    const projectRoot = findProjectRoot();
    if (!agentcmsDir || !projectRoot) {
        fail({
            what: "figma interpret を実行できません",
            why: ".agentcms/ またはプロジェクトルートが見つかりません",
            fix: "SiteGrowKit のプロジェクト内で実行してください",
        });
        return 1;
    }
    const recordPath = figmaApplyRecordPath(agentcmsDir);
    const record = loadApplyRecord(recordPath);
    if (!record) {
        fail({
            what: "apply record（provenance）がありません",
            why: `${recordPath} が存在しません`,
            fix: "先に agentcms figma apply を実行してください",
        });
        return 1;
    }
    // Manifest との stale 検査（inspect し直した後の記録は無効）
    const manifestPath = figmaManifestPath(agentcmsDir);
    if (existsSync(manifestPath)) {
        const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
        if (manifest.source.fingerprint !== record.manifest_fingerprint) {
            fail({
                what: "apply record が現在の Manifest と一致しません",
                why: `record fingerprint ${record.manifest_fingerprint.slice(0, 12)}… ≠ manifest ${manifest.source.fingerprint.slice(0, 12)}…`,
                fix: "figma plan → figma apply をやり直してから interpretation を記録してください",
            });
            return 1;
        }
    }
    heading("figma interpret");
    if (opts.status) {
        const evaluation = evaluateGeneratedFiles(projectRoot, record);
        info(`matched ${evaluation.matched.length} / interpreted ${evaluation.interpreted.length} / broken ${evaluation.broken.length}`);
        for (const b of evaluation.broken)
            warn(`  ${b.target}: ${b.why}`);
        info(record.interpretation
            ? `interpretation: ${record.interpretation.status}（${record.interpretation.files.length} ファイル / ${record.interpretation.recorded_at}）`
            : "interpretation: 未記録");
        return evaluation.broken.length === 0 ? 0 : 1;
    }
    const result = recordInterpretation(projectRoot, record);
    if (result.missing.length > 0) {
        fail({
            what: "生成物が欠落しているため interpretation を記録できません",
            why: result.missing.join(", "),
            fix: "欠落ファイルを復元（再 apply）してから記録してください",
        });
        return 1;
    }
    saveApplyRecord(recordPath, result.record);
    ok(result.interpreted.length === 0
        ? "変更された生成物はありません（全ファイル generated のまま）"
        : `${result.interpreted.length} ファイルを承認済み AI interpretation として記録しました`);
    for (const t of result.interpreted)
        info(`  interpreted: ${t}`);
    info(`unchanged: ${result.unchanged.length} ファイル（generated のまま）`);
    info("次: agentcms figma verify（記録済み hash と一致すれば PASS）");
    return 0;
}
