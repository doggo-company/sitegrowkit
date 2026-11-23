import { describe, expect, it } from "vitest";

import type { ApplyRecord } from "../src/importer/apply.js";
import {
  componentTypeOf,
  importedPruneCandidates,
  layoutViewOf,
  protectedTargets,
} from "../src/importer/prune-guard.js";

/**
 * 別テンプレートの再取り込みで、既存のページが使う部品・枠を消さない（Codex 続き ②）。
 */
const record = (targets: string[]): ApplyRecord => ({
  version: 1,
  manifest_fingerprint: "m",
  plan_sha256: "p",
  applied_at: "2026-01-01T00:00:00Z",
  files: targets.map((target) => ({ target, sha256: "x", source: "s" })),
});

describe("prune-guard", () => {
  it("生成物の target から、問うべき部品の type と枠の view を導く", () => {
    expect(componentTypeOf("content/components/imported_home_hero.json")).toBe(
      "imported_home_hero",
    );
    expect(
      componentTypeOf(
        "resources/views/components/document/imported-home-hero.blade.php",
      ),
    ).toBe("imported_home_hero");
    expect(componentTypeOf("content/components/section.json")).toBeNull();
    expect(
      layoutViewOf("resources/views/layouts/preserved/home.blade.php"),
    ).toBe("layouts.preserved.home");
    expect(
      layoutViewOf("resources/views/layouts/imported.blade.php"),
    ).toBeNull();
  });

  it("前回と今回の両方から候補を集める（消すものも書き直すものも問う）", () => {
    const c = importedPruneCandidates(
      record([
        "content/components/imported_home_hero.json",
        "resources/views/components/document/imported-home-hero.blade.php",
        "resources/views/layouts/preserved/home.blade.php",
        "resources/views/public/home.blade.php",
      ]),
      ["content/components/imported_home_about.json"],
    );

    expect(c).toEqual({
      components: ["imported_home_about", "imported_home_hero"],
      layouts: ["layouts.preserved.home"],
    });
  });

  it("使われている部品は定義と view の両方を守り、枠は view 名で守る", () => {
    const { protect, usedBy } = protectedTargets(
      {
        components: { imported_home_hero: ["home"], imported_home_about: [] },
        layouts: { "layouts.preserved.home": ["home", "top"] },
      },
      [
        "content/components/imported_home_hero.json",
        "resources/views/components/document/imported-home-hero.blade.php",
        "content/components/imported_home_about.json",
        "resources/views/layouts/preserved/home.blade.php",
        "resources/views/public/home.blade.php",
      ],
    );

    expect([...protect].sort()).toEqual([
      "content/components/imported_home_hero.json",
      "resources/views/components/document/imported-home-hero.blade.php",
      "resources/views/layouts/preserved/home.blade.php",
    ]);
    // 片方だけ残ると「一覧には並ぶのに描けない」
    expect(usedBy.get("content/components/imported_home_hero.json")).toEqual([
      "home",
    ]);
    expect(
      usedBy.get("resources/views/layouts/preserved/home.blade.php"),
    ).toEqual(["home", "top"]);
  });

  it("答えに無い部品は守らない（問うたものが全部返ることは bridge が確かめる）", () => {
    const { protect } = protectedTargets({ components: {}, layouts: {} }, [
      "content/components/imported_home_hero.json",
    ]);

    expect(protect.size).toBe(0);
  });
});
