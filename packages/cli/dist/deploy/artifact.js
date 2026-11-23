import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync, writeFileSync, } from "node:fs";
import { join, relative, sep } from "node:path";
/**
 * Deploy Artifact の内容定義。
 * 「何を含め・何を含めないか」をコードで固定し、テストで secret 除外を保証する。
 * ここは Provider 非依存（Laravel プロジェクトの形にのみ依存）。
 */
/** artifact に含めるプロジェクト直下のエントリ（allow-list 方式） */
export const ARTIFACT_INCLUDES = [
    "artisan",
    "composer.json",
    "composer.lock",
    "app",
    "bootstrap",
    "config",
    "content",
    "core",
    "database",
    "modules",
    "public",
    "resources",
    "routes",
    "vendor",
];
/**
 * include 済みツリー内でも除外するパス（相対パス・/ 区切りで前方一致 or 完全一致）。
 * .env / storage / node_modules / tests はそもそも include に無いが、
 * ネストして現れるもの・実行時に不要な開発ファイルをここで落とす。
 */
const EXCLUDE_PREFIXES = [
    "bootstrap/cache/", // 中身はサーバー側で生成（ディレクトリ自体は作る）
    "public/hot",
    "public/storage", // サーバー側で shared/storage への link を張り直す
    "core/node_modules",
];
const EXCLUDE_BASENAMES = new Set([
    ".env",
    ".env.backup",
    ".env.production",
    ".env.staging",
    ".DS_Store",
    "auth.json",
    "node_modules",
    ".git",
]);
/**
 * 鍵らしきファイルの除外。vendor/ 配下は対象外
 * （CA bundle の cacert.pem 等は公開情報でありランタイムに必要）。
 */
const EXCLUDE_SUFFIXES = [".sqlite", ".key", ".pem"];
function inVendor(relPath) {
    return relPath.startsWith("vendor/") || relPath.startsWith("core/vendor/");
}
/** modules/<Name>/tests は実行時不要（かつ配布物を軽くする） */
const MODULE_TESTS = /^modules\/[^/]+\/tests(\/|$)/;
/** 相対パス（/ 区切り）を artifact に含めるか。純関数（テスト対象） */
export function shouldInclude(relPath) {
    const parts = relPath.split("/");
    const top = parts[0] ?? "";
    if (!ARTIFACT_INCLUDES.includes(top))
        return false;
    for (const part of parts) {
        if (EXCLUDE_BASENAMES.has(part))
            return false;
    }
    for (const prefix of EXCLUDE_PREFIXES) {
        if (relPath === prefix.replace(/\/$/, "") || relPath.startsWith(prefix))
            return false;
    }
    if (!inVendor(relPath)) {
        for (const suffix of EXCLUDE_SUFFIXES) {
            if (relPath.endsWith(suffix))
                return false;
        }
    }
    if (MODULE_TESTS.test(relPath))
        return false;
    return true;
}
/** 除外理由の要約（deploy plan の excluded 表示に使う） */
export const EXCLUDED_SUMMARY = [
    ".env* / auth.json / *.key / *.pem（秘密情報）",
    "node_modules / .git / .DS_Store",
    "tests・modules/*/tests・docs（実行時不要）",
    "storage/（サーバー側 shared/storage を使用）",
    "*.sqlite（ローカル DB）",
    "public/storage・public/hot・bootstrap/cache/*（サーバー側で生成）",
];
/**
 * プロジェクトから build ディレクトリへ allow-list コピーする。
 * Node 実装（rsync 非依存・Windows 対応）。
 */
export function copyProjectTree(projectRoot, destDir, opts = {}) {
    const skip = new Set(opts.skipTopLevel ?? []);
    rmSync(destDir, { recursive: true, force: true });
    mkdirSync(destDir, { recursive: true });
    let fileCount = 0;
    let sizeBytes = 0;
    const walk = (rel) => {
        const abs = join(projectRoot, rel);
        const st = statSync(abs, { throwIfNoEntry: false });
        if (!st)
            return;
        const relPosix = rel.split(sep).join("/");
        if (!shouldInclude(relPosix))
            return;
        if (st.isDirectory()) {
            for (const entry of readdirSync(abs))
                walk(join(rel, entry));
        }
        else if (st.isFile()) {
            const dest = join(destDir, rel);
            mkdirSync(join(dest, ".."), { recursive: true });
            // symlink（core の composer path repo 等）は実体をコピーする
            cpSync(abs, dest, { dereference: true });
            fileCount++;
            sizeBytes += st.size;
        }
    };
    for (const top of ARTIFACT_INCLUDES) {
        if (skip.has(top))
            continue;
        if (existsSync(join(projectRoot, top)))
            walk(top);
    }
    // Laravel が実行時に要求する空ディレクトリ（中身はサーバー側で生成）
    mkdirSync(join(destDir, "bootstrap", "cache"), { recursive: true });
    return { fileCount, sizeBytes };
}
/** コピーせずに artifact の対象ファイル数・サイズを見積もる（deploy plan 用） */
export function estimateProjectTree(projectRoot) {
    let fileCount = 0;
    let sizeBytes = 0;
    const walk = (rel) => {
        const abs = join(projectRoot, rel);
        const st = statSync(abs, { throwIfNoEntry: false });
        if (!st)
            return;
        const relPosix = rel.split(sep).join("/");
        if (!shouldInclude(relPosix))
            return;
        if (st.isDirectory()) {
            for (const entry of readdirSync(abs))
                walk(join(rel, entry));
        }
        else if (st.isFile()) {
            fileCount++;
            sizeBytes += st.size;
        }
    };
    for (const top of ARTIFACT_INCLUDES) {
        if (existsSync(join(projectRoot, top)))
            walk(top);
    }
    return { fileCount, sizeBytes };
}
/**
 * build ディレクトリに秘密情報が紛れていないかの最終検査。
 * ファイル名ベース（.env / 鍵ファイル）。見つかったら相対パスを返す。
 */
export function scanForSecrets(destDir) {
    const hits = [];
    const walk = (dir) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const abs = join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(abs);
                continue;
            }
            const name = entry.name;
            const rel = relative(destDir, abs).split(sep).join("/");
            if (name === ".env" ||
                name.startsWith(".env.") ||
                name === "auth.json" ||
                (!inVendor(rel) && EXCLUDE_SUFFIXES.some((s) => name.endsWith(s)))) {
                hits.push(rel);
            }
        }
    };
    walk(destDir);
    return hits;
}
/** release メタデータ（artifact 同梱。秘密情報なし） */
export function writeReleaseManifest(destDir, meta) {
    writeFileSync(join(destDir, "release.json"), JSON.stringify(meta, null, 2) + "\n");
}
