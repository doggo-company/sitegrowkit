/**
 * verify の実行 runtime 判定（Phase 6.1 Gap 7 — Verify Runtime Capability）。
 * Docker を単純必須にしない: Docker が起動していなければ、互換な host runtime
 * （PHP version が composer.json の要求を満たし、vendor が install 済み）で PHP ゲートを実行できる。
 * どちらも使えない場合は WHAT / WHY / HOW TO FIX で FAIL する。
 * 環境固有 hack ではなく、Docker 外プロジェクト検証のための generic capability。
 */
/** "^8.4" / ">=8.3" / "8.4.*" などから最小 major.minor を取り出す（読めなければ null） */
export function minimumPhpVersion(constraint) {
    if (!constraint)
        return null;
    const m = /(\d+)\.(\d+)/.exec(constraint);
    if (!m)
        return null;
    return { major: Number(m[1]), minor: Number(m[2]) };
}
export function phpSatisfies(version, constraint) {
    const min = minimumPhpVersion(constraint);
    if (!min)
        return true; // 制約を読めない場合は host 実行を妨げない（結果はゲート自体が検証する）
    const v = /(\d+)\.(\d+)/.exec(version);
    if (!v)
        return false;
    const major = Number(v[1]);
    const minor = Number(v[2]);
    return major > min.major || (major === min.major && minor >= min.minor);
}
export function resolveVerifyRuntime(probe) {
    if (probe.dockerRunning)
        return { kind: "docker" };
    const dockerState = probe.unrelatedDockerRunning
        ? "起動中の Docker コンテナは別プロジェクトのもののため使用しません"
        : "Docker コンテナが起動していません";
    if (probe.hostPhpVersion &&
        probe.vendorPresent &&
        phpSatisfies(probe.hostPhpVersion, probe.requiredPhp)) {
        return {
            kind: "host",
            phpVersion: probe.hostPhpVersion,
            note: `${dockerState} — host PHP ${probe.hostPhpVersion} で実行（composer.json 要求: ${probe.requiredPhp ?? "不明"}。依存は host の vendor/ を使用）`,
        };
    }
    const reasons = [];
    if (!probe.hostPhpVersion)
        reasons.push("host に php がありません");
    else if (!phpSatisfies(probe.hostPhpVersion, probe.requiredPhp))
        reasons.push(`host PHP ${probe.hostPhpVersion} が要求 ${probe.requiredPhp ?? "?"} を満たしません`);
    if (!probe.vendorPresent)
        reasons.push("vendor/ が install されていません（composer install）");
    return {
        kind: "unavailable",
        what: "verify を実行できる runtime がありません",
        why: `${dockerState}。host 実行もできません: ${reasons.join(" / ")}`,
        fix: "`agentcms dev` でこのプロジェクトの Docker を起動するか、host に要求 version の PHP を用意して `composer install` を実行してください",
    };
}
/**
 * 稼働中コンテナが「現在の project」に属するかを compose label
 * （com.docker.compose.project.working_dir）で判定する。
 * compose の project 名が固定でも、別ディレクトリの SiteGrowKit のプロジェクト の
 * コンテナを誤帰属しない（特定 project 名への依存はしない — Phase 6.1 実測の一般化）。
 */
export function composeProjectMatches(projectRoot, containerWorkingDirs) {
    const canon = (p) => p.replace(/\/+$/, "").replace(/^\/private\//, "/");
    const root = canon(projectRoot);
    return containerWorkingDirs.some((dir) => dir !== null && dir !== "" && canon(dir) === root);
}
