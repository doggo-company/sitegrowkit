import { existsSync, mkdirSync, readFileSync, writeFileSync, } from "node:fs";
import { join, resolve } from "node:path";
import { renderPlanMarkdown } from "../importer/plan-md.js";
import { buildPlan } from "../importer/plan.js";
import { emptyCatalog, matchLabel } from "../importer/plan-contract.js";
import { declaredTaxonomyKeys, loadProjectSchemas, SchemaContractError, } from "../importer/project-schemas.js";
import { fetchTaxonomyCatalog } from "../importer/term-bridge.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { failAndExit, heading, info, ok, warn } from "../ui.js";
/**
 * Manifest → 変換計画（template-plan.json + docs/template-import-plan.md）。
 * AI にも人間にも「何を・なぜ・どの確度で」変換するかを提示する。
 */
export async function templatePlan(opts) {
    heading("agentcms template plan");
    const agentcms = findAgentcmsDir();
    const manifestPath = opts.manifest
        ? resolve(opts.manifest)
        : agentcms
            ? join(agentcms, "manifest", "template.json")
            : null;
    if (!manifestPath || !existsSync(manifestPath)) {
        failAndExit({
            what: "Template Manifest が見つかりません",
            why: `${manifestPath ?? ".agentcms/manifest/template.json"} が存在しません`,
            fix: "先に `agentcms template inspect <dir>` を実行してください",
        });
    }
    const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
    if (manifest.version !== 2) {
        failAndExit({
            what: `Manifest version ${String(manifest.version)} には対応していません`,
            why: "template plan は Manifest v2 を前提としています",
            fix: "`agentcms template inspect` を再実行して Manifest を再生成してください",
        });
    }
    const projectRoot = findProjectRoot();
    // **schema は「実際に効いている contract」として読む**（base + override）。
    // 読めない・壊れている場合は推測で進めず止める — その contract を根拠に
    // 「選択肢を足す」「分類項目を作る」を決めてしまうため
    let schemas;
    try {
        schemas = loadProjectSchemas(projectRoot);
    }
    catch (e) {
        if (e instanceof SchemaContractError) {
            failAndExit({
                what: "コンテンツ定義を読み取れないため中止しました（Plan は作っていません）",
                why: `${e.file}: ${e.message}`,
                fix: "content/schemas/ の定義を直してから `agentcms template plan` を実行してください",
            });
        }
        throw e;
    }
    if (Object.keys(schemas).length === 0) {
        warn("content/schemas/*.json が見つからないため、schema 連携なしで計画を生成します");
    }
    // 分類の既存項目を読む（read-only）。読めなければ「無い」ではなく「未確認」にする
    const taxonomyKeys = declaredTaxonomyKeys(schemas);
    const catalog = taxonomyKeys.length > 0 && projectRoot
        ? await fetchTaxonomyCatalog(taxonomyKeys, { root: projectRoot })
        : emptyCatalog();
    const plan = buildPlan(manifest, schemas, catalog);
    const planPath = join(manifestPath, "..", "template-plan.json");
    writeFileSync(planPath, `${JSON.stringify(plan, null, 2)}\n`, "utf8");
    // 人間可読版はリポジトリ直下の docs/（無ければプロジェクト直下 docs/）へ
    const docsDir = agentcms
        ? join(agentcms, "..", "docs")
        : join(projectRoot ?? process.cwd(), "docs");
    mkdirSync(docsDir, { recursive: true });
    const mdPath = join(docsDir, "template-import-plan.md");
    writeFileSync(mdPath, renderPlanMarkdown(plan), "utf8");
    ok(`Plan を出力しました: ${planPath}`);
    ok(`人間確認用: ${mdPath}`);
    info(`pages: ${plan.pages.length}`);
    info(`CMS 統合: ${plan.cms_integrations.map((i) => i.id).join(", ") || "なし"}`);
    info(`keep static: ${plan.keep_static.length} / JS: ${plan.javascript.length}`);
    info(`assets: static ${plan.assets.static.length} / media ${plan.assets.media.length}`);
    if (plan.schema_changes.length > 0)
        info(`schema 変更提案: ${plan.schema_changes.length} 件`);
    if (plan.taxonomy_changes.length > 0) {
        info(`分類項目の提案: ${plan.taxonomy_changes.length} 件`);
        for (const c of plan.taxonomy_changes) {
            const line = `  [${matchLabel(c.match)}] ${c.taxonomy}「${c.source_value}」× ${c.occurrences}`;
            if (c.match.kind === "absent")
                info(line);
            else
                warn(line);
        }
    }
    if (plan.uncertainties.length > 0) {
        warn(`要確認 ${plan.uncertainties.length} 件:`);
        for (const u of plan.uncertainties) {
            warn(`  [${u.level}] ${u.subject} — ${u.note}`);
        }
    }
    return 0;
}
