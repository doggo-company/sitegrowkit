/**
 * 厳密な semver（2.0.0）の最小 parser / comparator。
 *
 * ## なぜ自作か
 *
 * `packages/cli` に semver 系の依存は無い。更新確認に要るのは
 * 「配布物の版番号と、プロジェクトが生成された版番号の前後関係」だけで、
 * range（`^` / `~`）は要らない。新しい外部依存を 1 つ増やすより、
 * 仕様どおりの比較を 100 行で持つ方が配布物の攻撃面が小さい。
 *
 * ## 何を守るか
 *
 * - `1.0.0-rc1 < 1.0.0`（prerelease は同じ本体の release より前）
 * - `1.0.0-rc2 < 1.0.0-rc10`（数値識別子は数として比較）
 * - `1.0.0-alpha < 1.0.0-alpha.1 < 1.0.0-beta`（識別子の個数と辞書順）
 * - build metadata（`+...`）は比較に使わない
 * - 先頭ゼロ・空識別子・`v` 接頭辞は **受け付けない**（配布物の版番号は機械が書く）
 */
const NUMERIC = /^(0|[1-9]\d*)$/;
const IDENT = /^[0-9A-Za-z-]+$/;
/** 厳密に parse する。形式に合わなければ null */
export function parseSemver(input) {
    if (typeof input !== "string" || input.length === 0 || input.length > 256) {
        return null;
    }
    const m = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/.exec(input);
    if (!m)
        return null;
    const prerelease = [];
    if (m[4] !== undefined) {
        for (const id of m[4].split(".")) {
            if (!IDENT.test(id))
                return null;
            if (/^\d+$/.test(id)) {
                if (!NUMERIC.test(id))
                    return null; // 先頭ゼロの数値識別子は不正
                prerelease.push(Number(id));
            }
            else {
                prerelease.push(id);
            }
        }
    }
    const build = [];
    if (m[5] !== undefined) {
        for (const id of m[5].split(".")) {
            if (!IDENT.test(id))
                return null;
            build.push(id);
        }
    }
    return {
        major: Number(m[1]),
        minor: Number(m[2]),
        patch: Number(m[3]),
        prerelease,
        build,
        raw: input,
    };
}
/** a < b → 負、a == b → 0、a > b → 正（build metadata は無視） */
export function compareSemver(a, b) {
    if (a.major !== b.major)
        return a.major < b.major ? -1 : 1;
    if (a.minor !== b.minor)
        return a.minor < b.minor ? -1 : 1;
    if (a.patch !== b.patch)
        return a.patch < b.patch ? -1 : 1;
    // 同じ本体: release は prerelease より後
    if (a.prerelease.length === 0 && b.prerelease.length === 0)
        return 0;
    if (a.prerelease.length === 0)
        return 1;
    if (b.prerelease.length === 0)
        return -1;
    const n = Math.min(a.prerelease.length, b.prerelease.length);
    for (let i = 0; i < n; i++) {
        const x = a.prerelease[i];
        const y = b.prerelease[i];
        if (x === y)
            continue;
        const xNum = typeof x === "number";
        const yNum = typeof y === "number";
        if (xNum && yNum)
            return x < y ? -1 : 1;
        if (xNum)
            return -1; // 数値識別子は文字識別子より前
        if (yNum)
            return 1;
        return x < y ? -1 : 1;
    }
    // 前方が全て同じなら、識別子が多い方が後
    if (a.prerelease.length === b.prerelease.length)
        return 0;
    return a.prerelease.length < b.prerelease.length ? -1 : 1;
}
/** 文字列同士の比較。どちらかが不正なら null */
export function compareVersionStrings(a, b) {
    const pa = parseSemver(a);
    const pb = parseSemver(b);
    if (!pa || !pb)
        return null;
    return compareSemver(pa, pb);
}
