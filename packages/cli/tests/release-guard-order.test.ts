import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../../..");

/**
 * 門は、消す前に立っていなければ意味がない（F-17）。
 *
 * `overwriteRefusal()` は `existsSync()` の結果で判断する。呼び出しが
 * `rmSync()` より後ろにあると、**消えた後の「無い」を見て通してしまう** —
 * 門はそこに在るのに、何も止めない。
 *
 * 実測（ccb 発）: 呼び出しを `rmSync` の後ろへ移しても、
 * `release-guard.test.ts` を含む 602 件が**全部緑のまま**だった。
 * 判断そのものは正しく、**呼ぶ位置だけが壊れている**からである。
 *
 * ## この検査が固定するもの・しないもの
 *
 * 固定するのは**ソースの中での前後**だけである。呼び出しを条件分岐の中へ入れる、
 * 別関数へ包んで遅らせる、といった形は**捕まえられない**。
 * それでも、**無防備な並べ替え**（今回のような移動）は止まる。
 * `RollbackWxrTest` の到達可能性の検査と同じ作法で、届く範囲を書いておく。
 *
 * ## 落ちたときに、何をすればよいかを言う
 *
 * この検査は**形**を見ているので、**安全な書き換えでも赤くなる**。
 * 落ちた行だけを見た人が「テストの方が古い」と判断して消せてしまうと、
 * 門は残ったまま検査だけが消える。だから 1 つ 1 つの assertion に
 * **なぜ落ちたか**と**形を変えたならこの検査も直すこと**を書いてある。
 */
describe("package-release の門の位置", () => {
    const source = readFileSync(
        join(repoRoot, "scripts/package-release.mjs"),
        "utf8",
    );

    it("門は最初の rmSync より前で呼ばれる", () => {
        const guard = source.indexOf("overwriteRefusal(");
        const firstRemoval = source.indexOf("rmSync(");

        expect(
            guard,
            "package-release.mjs に overwriteRefusal( の呼び出しが見つかりません。門を外したなら、この検査も一緒に外すか作り直してください",
        ).toBeGreaterThan(-1);
        expect(
            firstRemoval,
            "package-release.mjs に rmSync( が見つかりません。消し方を変えたなら、この検査が見る目印も直してください",
        ).toBeGreaterThan(-1);

        expect(
            guard,
            "門は最初の rmSync より前で呼ぶこと。後ろだと、消えた後の「無い」を見て通します（F-15 がそのまま戻ります）。形を変えたなら、この検査も一緒に直してください",
        ).toBeLessThan(firstRemoval);
    });

    it("門の答えで止まる（呼ぶだけで捨てていない）", () => {
        expect(
            source,
            "門の答えで止めること（`if (refusal) fail(refusal)`）。呼ぶだけでは何も守りません。止め方を変えたなら、この検査が見る形も直してください",
        ).toMatch(/if\s*\(refusal\)\s*fail\(refusal\)/);
    });

    it("判断に使う existsSync は、消す前の状態を見ている", () => {
        const bundleExists = source.indexOf("bundleExists: existsSync(");
        const firstRemoval = source.indexOf("rmSync(");

        expect(
            bundleExists,
            "bundleExists: existsSync( が見つかりません。在るかどうかの調べ方を変えたなら、この検査も直してください",
        ).toBeGreaterThan(-1);
        expect(
            bundleExists,
            "在るかどうかは rmSync より前で調べること。呼び出しだけ前にあっても、引数の評価が後ろなら消えた後を見ます。形を変えたなら、この検査も一緒に直してください",
        ).toBeLessThan(firstRemoval);
    });
});
