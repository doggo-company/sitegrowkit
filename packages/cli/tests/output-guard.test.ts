import { describe, expect, it } from "vitest";

import { OUTPUT_NEEDLES } from "./support/output-guard.js";

/**
 * **針は、刺さることが確かめられて初めて針である**（監査 指摘 39）。
 *
 * 「0 件だった」は、針が的外れでも同じ顔をする。
 * 座（`support/output-guard.ts`）に針を登録すると `firesOn` が必須になり、
 * ここがその入力で本当に鳴るかを毎回確かめる。
 *
 * ▷ **針を足して canary を忘れる**ことが、型で起きなくなる。
 */
describe("出口の針は、刺さる入力で鳴る", () => {
  it("針が 1 本も登録されていない、が起きていない", () => {
    expect(OUTPUT_NEEDLES.length).toBeGreaterThan(0);
  });

  it.each(OUTPUT_NEEDLES.map((n) => [n.name, n]))("%s", (_name, needle) => {
    expect(
      typeof needle === "object" ? needle.find(needle.firesOn) : [],
      "刺さるはずの入力で 1 つも引っかからない（針が的外れ）",
    ).not.toStrictEqual([]);
  });

  it("刺さらない入力では鳴らない（何にでも鳴る針ではない）", () => {
    for (const needle of OUTPUT_NEEDLES) {
      expect(needle.find("<p>ただの文章です</p>"), needle.name).toStrictEqual(
        [],
      );
    }
  });
});
