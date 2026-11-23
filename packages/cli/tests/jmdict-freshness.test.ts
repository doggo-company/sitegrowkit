import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

// @ts-expect-error — repo 直下の配布スクリプト（型定義は持たない。release-guard.test.ts と同じ扱い）
import * as freshness from "../../../scripts/jmdict-freshness.mjs";

const { jmdictRefusal, MAX_AGE_DAYS } = freshness;

/**
 * JMdict の表が新しい版から作られていなければ、版を切らない（EDRDG の許諾・UX 一周 20 周目 R2）。
 */
describe("jmdictRefusal", () => {
  const sha = "a".repeat(64);
  const meta = { jmdict_created: "2026-11-05", table_sha256: sha };
  const day = (iso: string) => new Date(`${iso}T12:00:00Z`);

  it("版の日付から上限の日数以内なら進む", () => {
    expect(
      jmdictRefusal({ meta, tableSha256: sha, today: day("2026-11-06") }),
    ).toBeNull();
    expect(
      jmdictRefusal({ meta, tableSha256: sha, today: day("2026-12-06") }),
    ).toBeNull();
  });

  it("上限を超えたら止め、作り直し方を言う", () => {
    const refusal = jmdictRefusal({
      meta,
      tableSha256: sha,
      today: day("2026-12-07"),
    });

    expect(MAX_AGE_DAYS).toBe(31);
    expect(refusal).toContain("古い");
    expect(refusal).toContain("build-katakana-english.py --fetch");
  });

  it("表が記録と合わない（手で書き換えた）なら止める", () => {
    expect(
      jmdictRefusal({
        meta,
        tableSha256: "b".repeat(64),
        today: day("2026-11-06"),
      }),
    ).toContain("合いません");
  });

  it("記録や表が無ければ止める", () => {
    expect(
      jmdictRefusal({ meta: null, tableSha256: sha, today: day("2026-11-06") }),
    ).toContain("記録");
    expect(
      jmdictRefusal({ meta, tableSha256: null, today: day("2026-11-06") }),
    ).toContain("ありません");
  });

  it("同梱の表は、記録の sha256 と合っている", () => {
    const dir = resolve(
      dirname(fileURLToPath(import.meta.url)),
      "../../../template/core/resources/reading",
    );
    const recorded = JSON.parse(
      readFileSync(resolve(dir, "katakana-english.json"), "utf8"),
    );
    const actual = createHash("sha256")
      .update(readFileSync(resolve(dir, "katakana-english.tsv.gz")))
      .digest("hex");

    expect(recorded.table_sha256).toBe(actual);
    expect(
      readFileSync(resolve(dir, "katakana-english.NOTICE"), "utf8"),
    ).toContain(`JMdict created: ${recorded.jmdict_created}`);
  });
});
