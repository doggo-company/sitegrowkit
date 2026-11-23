import { mkdirSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, describe, expect, it } from "vitest";

import { romanizeKana } from "../src/wizard/kana.js";
import {
  suggestProjectName,
  validateProjectName,
} from "../src/wizard/validate.js";

/**
 * UX 一周 2 周目 M1（迷った）: 「ベーカリーこむぎ堂」でフォルダ名が `my-site` になり、
 * 1 周目の `my-site` が残っていたため、押した後に「同じ名前のフォルダがすでにあります」で止まった。
 */
describe("フォルダ名の候補", () => {
  it("かなはローマ字にする（漢字は読まない）", () => {
    expect(suggestProjectName("ベーカリーこむぎ堂")).toBe("bekari-komugi");
    expect(suggestProjectName("ひだまり整骨院")).toBe("hidamari");
    expect(suggestProjectName("きっちん・しゃぼん")).toBe("kitchin-shabon");
    expect(suggestProjectName("カフェ ティータイム")).toBe("kafe-titaimu");
  });

  it("英字はそのまま、読めなければ my-site", () => {
    expect(suggestProjectName("Yamada Design")).toBe("yamada-design");
    expect(suggestProjectName("山田整骨院")).toBe("my-site");
    expect(suggestProjectName("")).toBe("my-site");
  });

  it("同じ名前があれば番号を付けて避ける", () => {
    const taken = new Set(["my-site", "my-site-2"]);
    expect(suggestProjectName("山田整骨院", (n) => taken.has(n))).toBe(
      "my-site-3",
    );
    expect(
      suggestProjectName("ベーカリーこむぎ堂", (n) => n === "bekari-komugi"),
    ).toBe("bekari-komugi-2");
  });

  it("出した候補はいつもそのまま通る名前（40 文字以内・使える文字だけ）", () => {
    const long = "ア".repeat(60);
    for (const name of [
      suggestProjectName(long),
      suggestProjectName(long, () => true),
      suggestProjectName("ベーカリーこむぎ堂", () => true),
      suggestProjectName("っっっー"),
    ]) {
      expect(validateProjectName(name)).toBeNull();
    }
  });

  it("促音・長音", () => {
    expect(romanizeKana("ちょっと")).toBe("chotto");
    expect(romanizeKana("コーヒー")).toBe("kohi");
  });
});

describe("セットアップ画面の候補は、保存場所に既にあるフォルダを避ける", () => {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-project-name-"));
  afterAll(() => rmSync(dir, { recursive: true, force: true }));

  it("既にある my-site を避けて my-site-2", async () => {
    mkdirSync(join(dir, "my-site"));
    const { validateLocation, checkProjectDirConflict } =
      await import("../src/wizard/validate.js");
    const loc = validateLocation(dir, [tmpdir(), dir]);
    expect(loc.ok).toBe(true);
    const name = suggestProjectName(
      "山田整骨院",
      (n) => checkProjectDirConflict(loc.path as string, n) !== null,
    );
    expect(name).toBe("my-site-2");
  });
});

describe("フォルダ名の候補（漢字も読む・UX 一周 17〜19 周目）", () => {
  /*
   * ページの URL（PHP・IPADIC の Viterbi）と同じ例で、同じ候補になる。例は 1 か所
   * （template/tests/fixtures/reading-examples.json）。PHP 側は UxRelease20261106Round19Test が同じ表を見る。
   */
  const cases = JSON.parse(
    readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../../template/tests/fixtures/reading-examples.json"), "utf8"),
  ) as [string, string][];

  it("例が今夜の分そろっている", () => {
    expect(cases.length).toBeGreaterThanOrEqual(30);
  });

  it.each(cases)("%s → %s", async (title, expected) => {
    const { readSiteName } = await import("../src/wizard/reading.js");
    expect(await readSiteName(title)).toBe(expected);
  });

  it("フォルダ名は、読めなければ my-site", async () => {
    const { suggestProjectNameByReading } = await import("../src/wizard/validate.js");
    expect(await suggestProjectNameByReading("〒〇〇")).toBe("my-site");
    expect(await suggestProjectNameByReading("松本珈琲店")).toBe("matsumoto-kohi-ten");
  });

  it("setup の読みは template の reading-slug.mjs（決まりを PHP と揃えてある）", async () => {
    const { readingSlugPath } = await import("../src/wizard/reading.js");
    expect(readingSlugPath()).toContain("template/resources/js/lib/reading-slug.mjs");
  });
});
