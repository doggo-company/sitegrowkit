import { existsSync, realpathSync } from "node:fs";
import { homedir } from "node:os";
import { isAbsolute, join, resolve, sep } from "node:path";
import { romanizeKana } from "./kana.js";
import { readSiteName } from "./reading.js";
/** project ディレクトリ名として許可する形（allowlist） */
const PROJECT_NAME_RE = /^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/;
/** OS / ツールが特別扱いする名前 */
const RESERVED_NAMES = new Set([
    "con",
    "prn",
    "aux",
    "nul",
    "com1",
    "com2",
    "com3",
    "com4",
    "lpt1",
    "lpt2",
    "lpt3",
    "node_modules",
    "vendor",
    ".git",
    ".",
    "..",
]);
/**
 * サイト名から project 名の候補を作る（ユーザーが打ち直さなくて済むように）。
 *
 * - かなはローマ字にする（UX 一周 3 周目 M1: 「ベーカリーこむぎ堂」→ `bekari-komugi`）
 * - 読めないときは `my-site`
 * - **同じ名前のフォルダが既にあれば `-2`, `-3` … を付ける**（2 周目は 1 周目の `my-site` と
 *   ぶつかり、押した後に「同じ名前のフォルダがすでにあります」で止まった）
 */
export function suggestProjectName(siteName, taken = () => false) {
    return finishProjectName(romanizeKana(siteName), taken);
}
/**
 * サイト名から候補を作る（漢字も読む・UX 一周 17 周目 G1）。読みはページの URL の候補と同じ関数
 * （`reading.ts` → template の `reading-slug.mjs`・kuromoji）。読めなければ `my-site`。
 */
export async function suggestProjectNameByReading(siteName, taken = () => false, read = readSiteName) {
    return finishProjectName(await read(siteName), taken);
}
function finishProjectName(romaji, taken) {
    const ascii = romaji
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "")
        .slice(0, 40)
        .replace(/-+$/g, "");
    const base = PROJECT_NAME_RE.test(ascii) ? ascii : "my-site";
    if (!taken(base))
        return base;
    for (let n = 2; n < 100; n += 1) {
        const suffix = `-${n}`;
        const candidate = `${base.slice(0, 40 - suffix.length).replace(/-+$/g, "")}${suffix}`;
        if (PROJECT_NAME_RE.test(candidate) && !taken(candidate))
            return candidate;
    }
    return base;
}
export function validateProjectName(value) {
    const name = value.trim();
    if (name === "") {
        return {
            field: "projectName",
            code: "project_name.empty",
            message: "プロジェクト名を入力してください。",
        };
    }
    if (name.length > 40) {
        return {
            field: "projectName",
            code: "project_name.too_long",
            message: "プロジェクト名は 40 文字以内で入力してください。",
        };
    }
    if (!PROJECT_NAME_RE.test(name)) {
        return {
            field: "projectName",
            code: "project_name.invalid",
            message: "プロジェクト名は半角の英小文字・数字・ハイフンだけで、英数字で始めてください。",
        };
    }
    if (RESERVED_NAMES.has(name)) {
        return {
            field: "projectName",
            code: "project_name.reserved",
            message: "この名前は使用できません。別の名前を入力してください。",
        };
    }
    return null;
}
export function validateSiteName(value) {
    const name = value.trim();
    if (name === "") {
        return {
            field: "siteName",
            code: "site_name.empty",
            message: "サイト名を入力してください。",
        };
    }
    if (name.length > 60) {
        return {
            field: "siteName",
            code: "site_name.too_long",
            message: "サイト名は 60 文字以内で入力してください。",
        };
    }
    // 制御文字は表示・保存の両面で扱いを誤りやすいため拒否する
    if (/[\0-\x1f\x7f]/.test(name)) {
        return {
            field: "siteName",
            code: "site_name.invalid",
            message: "サイト名に使用できない文字が含まれています。",
        };
    }
    return null;
}
/** 既定の保存場所（人が読める場所。path 文字列を Primary UI で見せすぎない） */
export function defaultLocation() {
    return join(homedir(), "SiteGrowKit Projects");
}
/**
 * 保存場所の検証。
 * - 絶対パスであること（相対 path は cwd 依存になるため拒否）
 * - `..` や NUL を含まないこと
 * - 実在する祖先を realpath 解決して home 配下（または明示許可 root 配下）であること
 */
export function validateLocation(value, allowedRoots = [homedir()]) {
    const raw = value.trim();
    const invalid = (code, message) => ({
        ok: false,
        error: { field: "location", code, message },
    });
    if (raw === "") {
        return invalid("location.empty", "保存場所を選んでください。");
    }
    if (raw.includes("\0")) {
        return invalid("location.invalid", "保存場所に使用できない文字が含まれています。");
    }
    if (!isAbsolute(raw)) {
        return invalid("location.not_absolute", "保存場所はフォルダを選び直してください。");
    }
    if (raw.split(/[/\\]/).some((segment) => segment === ".." || segment === "~")) {
        return invalid("location.traversal", "保存場所を選び直してください。");
    }
    const target = resolve(raw);
    // 実在する最も近い祖先を realpath 解決する（symlink で許可 root の外へ出るのを防ぐ）
    const anchor = nearestExisting(target);
    if (!anchor) {
        return invalid("location.unavailable", "保存場所を確認できませんでした。");
    }
    let realAnchor;
    try {
        realAnchor = realpathSync(anchor);
    }
    catch {
        return invalid("location.unavailable", "保存場所を確認できませんでした。");
    }
    const roots = allowedRoots.map((r) => {
        try {
            return realpathSync(r);
        }
        catch {
            return resolve(r);
        }
    });
    if (!roots.some((root) => isInside(realAnchor, root))) {
        return invalid("location.outside_allowed_root", "この場所には作成できません。ホームフォルダ内の場所を選んでください。");
    }
    return { ok: true, path: target };
}
/** project ディレクトリの重複チェック（既存を勝手に上書きしない） */
export function checkProjectDirConflict(location, projectName) {
    const target = join(location, projectName);
    if (existsSync(target)) {
        return {
            field: "projectName",
            code: "project_dir.exists",
            message: "同じ名前のフォルダがすでにあります。別の名前にするか、保存場所を変えてください。",
        };
    }
    return null;
}
function nearestExisting(target) {
    let dir = target;
    for (;;) {
        if (existsSync(dir))
            return dir;
        const parent = resolve(dir, "..");
        if (parent === dir)
            return null;
        dir = parent;
    }
}
function isInside(child, root) {
    if (child === root)
        return true;
    return child.startsWith(root.endsWith(sep) ? root : root + sep);
}
