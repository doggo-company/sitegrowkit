import { describe, expect, it } from "vitest";

// @ts-expect-error — repo 直下の配布スクリプト（型定義は持たない）
import { overwriteRefusal } from "../../../scripts/release-guard.mjs";

/**
 * 配布物を上書きしてよいかの門（F-15 の根治）。
 *
 * ここが無かったあいだに、accepted な `v0.8.0-rc15` の成果物が
 * **手順を実証するための再実行で置き換わり、失われた**。
 * 判断だけを切り出してあるので、build も pack もせずに試せる。
 */
describe("overwriteRefusal", () => {
    const state = (over: Partial<Record<string, unknown>> = {}) => ({
        bundleName: "agent-cms-kit-v0.8.0-rc16",
        bundleExists: false,
        zipExists: false,
        force: false,
        ...over,
    });

    it("何も無ければ進む", () => {
        expect(overwriteRefusal(state())).toBeNull();
    });

    it("配布ディレクトリが在れば止める", () => {
        const refusal = overwriteRefusal(state({ bundleExists: true }));

        expect(refusal).not.toBeNull();
        expect(refusal).toContain("上書きしません");
    });

    it("zip だけ在っても止める（片方だけ残っている状態も上書きである）", () => {
        expect(overwriteRefusal(state({ zipExists: true }))).not.toBeNull();
    });

    /**
     * **止める理由を言う。** 「もう在ります」だけでは、次に来た人は
     * `--force` を付けて同じことをする。
     */
    it("止める理由と、次にできることを書いてある", () => {
        const refusal = String(overwriteRefusal(state({ bundleExists: true })));

        // なぜ止めたか
        expect(refusal).toContain("記録が同時に消えます");
        // 版を上げる道
        expect(refusal).toContain("VERSION");
        // それでも置き換える道と、その代償
        expect(refusal).toContain("--force");
        expect(refusal).toContain("消えたものは戻りません");
    });

    it("--force なら進む", () => {
        expect(
            overwriteRefusal(state({ bundleExists: true, zipExists: true, force: true })),
        ).toBeNull();
    });
});
