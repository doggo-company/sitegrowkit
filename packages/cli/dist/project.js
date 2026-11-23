import { existsSync } from "node:fs";
import { join, resolve } from "node:path";
/**
 * CMS プロジェクトルート（docker-compose.yml と artisan がある場所）を探す。
 * cwd から上へ辿る。キットリポジトリ直下で実行された場合は template/ を候補にする。
 */
export function findProjectRoot(cwd = process.cwd()) {
    let dir = resolve(cwd);
    for (;;) {
        if (isProjectRoot(dir))
            return dir;
        // キットリポジトリ直下なら template/ を許容（開発時の利便性）
        const tpl = join(dir, "template");
        if (isProjectRoot(tpl))
            return tpl;
        const parent = resolve(dir, "..");
        if (parent === dir)
            return null;
        dir = parent;
    }
}
export function isProjectRoot(dir) {
    return (existsSync(join(dir, "artisan")) &&
        existsSync(join(dir, "docker-compose.yml")));
}
/** .agentcms/ ディレクトリ（SSoT）を cwd から上へ探す */
export function findAgentcmsDir(cwd = process.cwd()) {
    let dir = resolve(cwd);
    for (;;) {
        const candidate = join(dir, ".agentcms");
        if (existsSync(candidate))
            return candidate;
        const parent = resolve(dir, "..");
        if (parent === dir)
            return null;
        dir = parent;
    }
}
