import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
export const EMPTY_TEMPLATE_EDITS = { edited: [], removed: [] };
/** `.agentcms/manifest/template-edits.json`。無ければ空（**この検査を新設した日の既定**） */
export function loadTemplateEdits(agentcmsDir) {
    const path = join(agentcmsDir, "manifest", "template-edits.json");
    if (!existsSync(path))
        return EMPTY_TEMPLATE_EDITS;
    try {
        const raw = JSON.parse(readFileSync(path, "utf8"));
        return {
            edited: Array.isArray(raw.edited) ? raw.edited : [],
            removed: Array.isArray(raw.removed) ? raw.removed : [],
        };
    }
    catch {
        // 読めない記録は「無い」と同じに扱う。**壊れた JSON で門ごと落とさない**
        return EMPTY_TEMPLATE_EDITS;
    }
}
/**
 * 生成物の現状を仕分ける。
 *
 * `actual` は「その target の中身の sha256（無ければ null）」を返す。
 * **ファイルの読み方をここへ持ち込まない**ので、置かずに測れる。
 */
export function classifyGenerated(files, edits, actual) {
    const approvedSha = new Map(edits.edited.map((e) => [e.target, e.sha256]));
    const removed = new Set(edits.removed.map((e) => e.target));
    const result = {
        approved: [],
        unknown: [],
        missing: [],
        removed: [],
        contradictions: [],
    };
    for (const file of files) {
        const current = actual(file.target);
        if (removed.has(file.target)) {
            // 配らないと決めたもの。**在るなら記録と食い違っている**
            if (current === null)
                result.removed.push(file.target);
            else
                result.contradictions.push(file.target);
            continue;
        }
        if (current === null) {
            result.missing.push(file.target);
            continue;
        }
        if (current === file.sha256)
            continue;
        if (approvedSha.get(file.target) === current) {
            result.approved.push(file.target);
            continue;
        }
        result.unknown.push({ target: file.target, sha256: current });
    }
    return result;
}
/**
 * seeder が使っている category の値（F-43）。
 *
 * **空振りを緑と読まないため**に、ここは「見つからなかった」を空配列で返す。
 * 呼ぶ側は**空を「不足なし」と読んではいけない** — それは
 * 「一致した」ではなく「**測れていない**」である。
 *
 * 引用符は両方受ける。PHP は `'category'` とも `"category"` とも書けるので、
 * 片方だけを見ていると**書き方を変えただけで網が外れる**。
 */
export function seededCategories(source) {
    return [
        ...source.matchAll(/["\']category["\']\s*=>\s*["\']([^"\']*)["\']/g),
    ].map((m) => m[1] ?? "");
}
