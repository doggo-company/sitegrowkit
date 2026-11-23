/**
 * Deployment の Provider 非依存型定義。
 * XServer 固有の値・検出ロジックは providers/xserver.ts に置く（Core へ埋め込まない）。
 */
export function worstStatus(checks) {
    if (checks.some((c) => c.status === "FAIL"))
        return "FAIL";
    if (checks.some((c) => c.status === "WARN"))
        return "WARN";
    return "PASS";
}
