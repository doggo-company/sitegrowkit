import { chmodSync, mkdirSync, readFileSync, renameSync, writeFileSync, } from "node:fs";
import { homedir } from "node:os";
import { dirname, isAbsolute, join } from "node:path";
const STAGE_ORDER = ["project_selected", "created", "ready"];
export function setupStateDir(home = homedir()) {
    return join(home, ".agentcms");
}
export function setupStatePath(home = homedir()) {
    return join(setupStateDir(home), "setup-state.json");
}
export function readSetupState(home = homedir()) {
    try {
        const raw = readFileSync(setupStatePath(home), "utf8");
        const parsed = JSON.parse(raw);
        return isSetupState(parsed) ? parsed : null;
    }
    catch {
        return null;
    }
}
/**
 * 状態を書き出す（所有者のみ読み書き可）。
 * 秘密情報を含み得ないフォーマットだが、project の場所は個人情報に近いため 0600 で保護する。
 */
export function writeSetupState(state, home = homedir()) {
    const path = setupStatePath(home);
    mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
    const tmp = `${path}.tmp`;
    writeFileSync(tmp, `${JSON.stringify(state, null, 2)}\n`, {
        encoding: "utf8",
        mode: 0o600,
    });
    renameSync(tmp, path);
    try {
        chmodSync(path, 0o600);
    }
    catch {
        // 権限変更に失敗しても致命的ではない（Windows 等）
    }
}
export function clearSetupState(home = homedir()) {
    try {
        writeFileSync(setupStatePath(home), "", { encoding: "utf8", mode: 0o600 });
    }
    catch {
        // 状態ファイルが無い場合は何もしない
    }
}
/** stage を前進させる（後退はさせない。再開判定が巻き戻るのを防ぐ） */
export function advanceStage(current, next) {
    return STAGE_ORDER.indexOf(next) > STAGE_ORDER.indexOf(current)
        ? next
        : current;
}
function isSetupState(value) {
    if (typeof value !== "object" || value === null)
        return false;
    const v = value;
    return (v.version === 1 &&
        typeof v.projectPath === "string" &&
        // 相対パス・NUL 混入の state は cwd 依存 / 不正操作の余地があるため採用しない
        isAbsolute(v.projectPath) &&
        !v.projectPath.includes("\0") &&
        typeof v.projectName === "string" &&
        typeof v.siteName === "string" &&
        typeof v.stage === "string" &&
        STAGE_ORDER.includes(v.stage) &&
        typeof v.createdAt === "string" &&
        typeof v.updatedAt === "string");
}
