import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
/**
 * Version Single Source of Truth（Phase 7 Decision 2）。
 * runtime の version は CLI package の package.json のみから決定する（git 非依存）。
 * リポジトリ root の VERSION との一致は build/package/test 時に検証される。
 */
/** 互換性判定用の独立 version（template の構造契約） */
export const TEMPLATE_SCHEMA_VERSION = 1;
/** 互換性判定用の独立 version（.agentcms context の構造契約） */
export const AGENT_CONTEXT_VERSION = 1;
let cached = null;
export function kitVersion() {
    if (cached !== null)
        return cached;
    const here = dirname(fileURLToPath(import.meta.url));
    // dist/version.js → ../package.json（repo でも npm install 先でも package root 直下）
    const pkg = JSON.parse(readFileSync(join(here, "..", "package.json"), "utf8"));
    cached = pkg.version ?? "0.0.0";
    return cached;
}
