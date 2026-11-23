import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

import { remainingGates } from "../src/commands/template-verify.js";

/**
 * 残りの品質ゲートの案内（R-15 Stage 1）。
 *
 * 以前は常に `pnpm e2e` を案内していたが、**生成プロジェクトには spec も
 * playwright の設定も配っていない**ので、そのとおりにやると動かない。
 * **書いてあるとおりにやると止まる案内**は、案内が無いより悪い
 * （R-13 の seeder・指摘 32 と同じ形で、これで 3 件目）。
 */
function project(files: string[]): string {
    const dir = mkdtempSync(join(tmpdir(), "agentcms-gates-"));

    for (const f of files) {
        if (f.endsWith("/")) {
            mkdirSync(join(dir, f), { recursive: true });
            continue;
        }

        writeFileSync(join(dir, f), "", "utf8");
    }

    return dir;
}

const E2E = "`pnpm e2e`（visual regression 含む）";
const E2E_VISUAL = "`pnpm e2e:visual`（取り込み元との視覚差分）";

describe("在るものだけを案内する", () => {
    it("何も無ければ verify だけ", () => {
        expect(remainingGates(project([]))).toStrictEqual(["`agentcms verify`"]);
    });

    /** **設定と spec の両方**が要る。片方だけでは playwright は何も実行しない */
    it("設定だけでは e2e を案内しない", () => {
        expect(remainingGates(project(["playwright.config.ts"]))).not.toContain(
            E2E,
        );
    });

    it("spec だけでは e2e を案内しない", () => {
        expect(remainingGates(project(["tests/e2e/"]))).not.toContain(E2E);
    });

    it("両方あれば e2e を案内する", () => {
        expect(
            remainingGates(project(["playwright.config.ts", "tests/e2e/"])),
        ).toContain(E2E);
    });

    /**
     * 生成プロジェクトの形（R-15 Stage 2）。
     *
     * 標準 E2E の設定は配っていないので、そこで `pnpm e2e` と案内すると
     * **設定の無い playwright を起動させる**ことになる。案内する命令を変える。
     */
    it("視覚回帰だけの設定なら、視覚回帰の命令を案内する", () => {
        const gates = remainingGates(
            project(["playwright.visual.config.ts", "tests/e2e/"]),
        );

        expect(gates).toContain(E2E_VISUAL);
        expect(gates).not.toContain(E2E);
    });

    /** kit の形。両方在るなら標準 E2E が視覚回帰も含んでいる（2 回走らせない） */
    it("両方の設定が在るときは標準 E2E だけを案内する", () => {
        const gates = remainingGates(
            project([
                "playwright.config.ts",
                "playwright.visual.config.ts",
                "tests/e2e/",
            ]),
        );

        expect(gates).toContain(E2E);
        expect(gates).not.toContain(E2E_VISUAL);
    });

    it("lighthouse の設定が無ければ案内しない", () => {
        expect(remainingGates(project([]))).not.toContain("`pnpm lighthouse`");
    });

    it("順序は e2e → lighthouse → verify", () => {
        expect(
            remainingGates(
                project([
                    "playwright.config.ts",
                    "tests/e2e/",
                    "lighthouserc.json",
                ]),
            ),
        ).toStrictEqual([E2E, "`pnpm lighthouse`", "`agentcms verify`"]);
    });
});
