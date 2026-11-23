import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import { findPlaceholders } from "../src/scan-patterns.js";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

/* ---------------------------------------------------------------- *
 * Phase 8 Stage L — user-facing documents / release hygiene
 * ---------------------------------------------------------------- */

const USER_DOCS = [
  "LICENSE",
  "THIRD-PARTY-LICENSES.md",
  "COPYRIGHT",
];

describe("user-facing documents (Phase 8 Stage L)", () => {
  it.each(USER_DOCS)("%s が存在する", (rel) => {
    expect(existsSync(join(repoRoot, rel))).toBe(true);
  });

  it.each(USER_DOCS)("%s に未解決 placeholder がない", (rel) => {
    const content = readFileSync(join(repoRoot, rel), "utf8");
    expect(findPlaceholders(content)).toEqual([]);
  });

  it("entry document は README_FIRST.md ただ一つ（START_HERE.md 不存在 — D11/R10）", () => {
    const tracked = execFileSync("git", ["ls-files"], {
      cwd: repoRoot,
      encoding: "utf8",
    });
    expect(tracked).not.toMatch(/(^|\/)START_HERE\.md$/im);
    expect(existsSync(join(repoRoot, "START_HERE.md"))).toBe(false);
  });

  /**
   * LICENSE は FSF の GPLv3 本文そのもの（1 文字も変えない）。
   * 著作権の表示と「or later」の宣言は COPYRIGHT に分けて置く。
   * sha256 は https://www.gnu.org/licenses/gpl-3.0.txt を 2026-11-23 に取得して測った値。
   */
  it("LICENSE は GPLv3 の本文そのもので、著作権の表示は COPYRIGHT にある", () => {
    const license = readFileSync(join(repoRoot, "LICENSE"));
    expect(createHash("sha256").update(license).digest("hex")).toBe(
      "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986",
    );
    const copyright = readFileSync(join(repoRoot, "COPYRIGHT"), "utf8");
    expect(copyright).toContain("Copyright (C) 2026 DOGGO Company株式会社");
    expect(copyright).toContain("either version 3 of the License, or");
    expect(copyright).toContain("THIRD-PARTY-LICENSES");
  });
});

describe("THIRD-PARTY-LICENSES inventory (Phase 8 §7.4)", () => {
  const doc = readFileSync(join(repoRoot, "THIRD-PARTY-LICENSES.md"), "utf8");

  it("生成マーカーを持つ", () => {
    expect(doc).toContain("AUTO-GENERATED:DEPS START");
    expect(doc).toContain("AUTO-GENERATED:DEPS END");
  });

  it("要確認依存の個別記録を持つ（htmlpurifier LGPL / shadcn-vue / Instrument Sans）", () => {
    expect(doc).toContain("ezyang/htmlpurifier");
    expect(doc).toContain("LGPL-2.1-or-later");
    expect(doc).toContain("shadcn-vue");
    expect(doc).toContain("Instrument Sans");
    expect(doc).toContain("LEGAL REVIEW REQUIRED");
  });

  it("依存セットと同期している（generate-third-party-licenses --check）", () => {
    execFileSync(
      "node",
      [join(repoRoot, "scripts/generate-third-party-licenses.mjs"), "--check"],
      { cwd: repoRoot, encoding: "utf8" },
    );
  });
});

/* ---------------------------------------------------------------- *
 * F-20 — README_FIRST は版番号を書き写さない（2026-09-06）
 * ---------------------------------------------------------------- */

/**
 * §4 の注記に `agent-cms-kit-v0.9.3` / `agent-cms-kit-0.9.3.tgz` と
 * **0.9.3 のまま**書いてあった（0.9.4-rc2 の実測）。ほかの行は `<version>` を
 * 使っていて、`package-release.mjs` が配布時に実際の版へ置き換えている。
 *
 * **版を上げるたびに直し忘れる形にしない。** 直接書かせないことで、
 * 「直し忘れ」という状態そのものを無くす。
 */
describe("README_FIRST の版（F-20）", () => {
  const readmeFirst = readFileSync(join(repoRoot, "README_FIRST.md"), "utf8");

  it("版番号を直接書かない（<version> を使う）", () => {
    expect(readmeFirst.match(/\d+\.\d+\.\d+(-rc\d+)?/g) ?? []).toEqual([]);
  });

  it("配布時に <version> がいまの版へ置き換わる", () => {
    const version = readFileSync(join(repoRoot, "VERSION"), "utf8").trim();
    // package-release.mjs が bundle へ書き出すときと同じ置換
    const rendered = readmeFirst.replaceAll("<version>", version);

    expect(rendered).not.toContain("<version>");
    // zip とフォルダには v が付き、.tgz には付かない（§4 の注記そのもの）
    expect(rendered).toContain(`agent-cms-kit-v${version}.zip`);
    expect(rendered).toContain(`agent-cms-kit-${version}.tgz`);
  });
});
