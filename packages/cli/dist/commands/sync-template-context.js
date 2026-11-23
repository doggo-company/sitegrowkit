import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { buildProjectContext, writeContextFiles } from "../agent-context.js";
import { findAgentcmsDir } from "../project.js";
import { fail, heading, info, ok } from "../ui.js";
/**
 * Kit canonical .agentcms/ → template/.agentcms/ の決定論的同期（Decision 3）。
 * template 側 context は必ずこのコマンドで生成する（手書き二重管理は禁止）。
 * `--check` は package/CI 用のドリフト検証（差分があれば非 0 で終了）。
 */
export async function syncTemplateContext(opts) {
    const canonical = findAgentcmsDir();
    const repoRoot = canonical ? dirname(canonical) : null;
    const templateDir = repoRoot ? join(repoRoot, "template") : null;
    if (!canonical || !templateDir || !existsSync(join(templateDir, "artisan"))) {
        fail({
            what: "Kit リポジトリではありません",
            why: "canonical .agentcms/ と template/ の両方が必要です（このコマンドは kit 開発用です）",
            fix: "SiteGrowKit リポジトリの root で実行してください",
        });
        return 1;
    }
    heading(opts.check
        ? "agentcms sync-template-context --check — ドリフト検証"
        : "agentcms sync-template-context — template/.agentcms を生成します");
    const files = buildProjectContext(canonical);
    const targetDir = join(templateDir, ".agentcms");
    if (opts.check) {
        const drift = [];
        for (const f of files) {
            const p = join(targetDir, f.rel);
            const current = existsSync(p) ? readFileSync(p, "utf8") : null;
            if (current !== f.content)
                drift.push(f.rel);
        }
        if (drift.length > 0) {
            fail({
                what: "template/.agentcms が canonical .agentcms と同期していません",
                why: `差分: ${drift.join(", ")}`,
                fix: "`agentcms sync-template-context` を実行して再生成し、コミットしてください",
            });
            return 1;
        }
        ok(`同期済み（${files.length} ファイル）`);
        return 0;
    }
    writeContextFiles(targetDir, files);
    for (const f of files)
        info(`  生成: template/.agentcms/${f.rel}`);
    ok(`template/.agentcms を生成しました（${files.length} ファイル）`);
    return 0;
}
