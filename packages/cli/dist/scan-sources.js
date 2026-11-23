import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { join, relative, sep } from "node:path";
/**
 * 走査しないディレクトリ（依存・生成物・キャッシュ・ローカル秘密）。
 * template/.gitignore.dist と「source ではないもの」を明示的に列挙する。
 */
export const EXCLUDED_DIRS = [
    ".git",
    "node_modules",
    "vendor",
    "storage",
    "bootstrap/cache",
    "bootstrap/ssr",
    "public/build",
    "public/hot",
    "public/storage",
    ".phpunit.cache",
    ".lighthouseci",
    "test-results",
    "tests/e2e/.auth",
    "tests/e2e/__screenshots__",
    "resources/js/actions",
    "resources/js/routes",
    "resources/js/wayfinder",
    // ローカル秘密・クライアントデータ・deploy 生成物（Git 管理外が正）
    ".agentcms/local",
    ".agentcms/cache",
    ".agentcms/deploy/build",
    // エディタ設定
    ".idea",
    ".vscode",
    ".fleet",
    ".nova",
    ".zed",
];
/**
 * walk mode で「存在していて当然のローカルファイル」。
 * git mode でも（gitignore されているため）走査対象にならないので、
 * 除外しても検出範囲は変わらない。
 */
export function isExpectedLocalFile(relPath) {
    const base = relPath.split("/").pop() ?? relPath;
    if (base === ".env")
        return true;
    if (base.startsWith(".env.") && base !== ".env.example")
        return true;
    if (relPath === "auth.json")
        return true;
    if (base === ".DS_Store")
        return true;
    return false;
}
/** 走査対象ファイルを列挙する（git があれば git、無ければ filesystem） */
export function collectScanSources(root = process.cwd()) {
    try {
        const files = execFileSync("git", ["ls-files"], {
            encoding: "utf8",
            cwd: root,
            stdio: ["ignore", "pipe", "ignore"],
        })
            .split("\n")
            .filter(Boolean);
        return { mode: "git", files };
    }
    catch {
        // git 不在 / Git リポジトリでない → filesystem を走査する
        return {
            mode: "filesystem",
            files: walk(root),
            note: "Git を使わずにプロジェクトのファイルを直接走査しました（依存・生成物・ローカル秘密ファイルは除外）",
        };
    }
}
function walk(root) {
    const out = [];
    const excluded = new Set(EXCLUDED_DIRS);
    const visit = (dir) => {
        let entries;
        try {
            entries = readdirSync(dir, { withFileTypes: true });
        }
        catch {
            return;
        }
        for (const entry of entries) {
            const abs = join(dir, entry.name);
            const rel = relative(root, abs).split(sep).join("/");
            if (excluded.has(rel))
                continue;
            if (entry.isSymbolicLink())
                continue; // リンク先の外部ツリーへ出ない
            if (entry.isDirectory()) {
                visit(abs);
                continue;
            }
            if (entry.isFile() && !isExpectedLocalFile(rel))
                out.push(rel);
        }
    };
    visit(root);
    return out.sort();
}
