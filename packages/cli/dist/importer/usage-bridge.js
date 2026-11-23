import { parseResponse } from "./term-bridge.js";
import { composeUserArgs } from "../runtime-user.js";
import { run } from "../run.js";
/**
 * 取り込みが生成した部品・枠が、いまどのページに使われているかを CMS に聞く（読み取り専用）。
 *
 * `term-bridge.ts` と同じ座（shell 文字列を組まない・payload は stdin・決め打ちの 1 コマンド）。
 *
 * ## 「読めなかった」を「未使用」にしない
 *
 * ここの答えで **ファイルを消す**。CMS が起動していない・応答が壊れている、を
 * 「使われていない」と読むと、使われている部品を消して公開面を壊す。
 * 確認できなければ `unknown` を返し、呼び出し側は**何も書かずに止まる**。
 */
const COMMAND = "agentcms:imported-usage";
export async function fetchImportedUsage(components, layouts, options) {
    if (components.length === 0 && layouts.length === 0) {
        return { kind: "ok", usage: { components: {}, layouts: {} } };
    }
    let result;
    try {
        result = await run("docker", [
            "compose",
            "exec",
            "-T",
            ...composeUserArgs(options.root),
            "app",
            "php",
            "artisan",
            COMMAND,
        ], { cwd: options.root, input: JSON.stringify({ components, layouts }) });
    }
    catch (e) {
        return {
            kind: "unknown",
            message: `CMS の応答を確認できませんでした: ${e instanceof Error ? e.message : String(e)}`,
        };
    }
    if (result.stderr.trim() !== "")
        options.onDiagnostic?.(result.stderr.trim());
    const body = parseResponse(result.stdout);
    if (!body) {
        return {
            kind: "unknown",
            message: `応答を解釈できませんでした（exit=${result.code}）`,
        };
    }
    if (result.code !== 0 || body.status !== "success") {
        return {
            kind: "unknown",
            message: body.message ?? `使用状況を読み取れませんでした（exit=${result.code}）`,
        };
    }
    const usage = normalize(body, components, layouts);
    if (usage === null) {
        return { kind: "unknown", message: "使用状況の応答を解釈できませんでした" };
    }
    return { kind: "ok", usage };
}
/**
 * 問うたものが**全部**答えに在ることを確かめる。欠けていれば「分からない」。
 * 欠けたものを未使用と読むと、そこだけ消してしまう。
 */
function normalize(body, components, layouts) {
    const read = (raw, keys) => {
        if (keys.length === 0)
            return {};
        if (!raw || typeof raw !== "object" || Array.isArray(raw))
            return null;
        const out = {};
        for (const key of keys) {
            const slugs = raw[key];
            if (!Array.isArray(slugs) || slugs.some((s) => typeof s !== "string"))
                return null;
            out[key] = slugs;
        }
        return out;
    };
    const c = read(body.components, components);
    const l = read(body.layouts, layouts);
    return c === null || l === null ? null : { components: c, layouts: l };
}
