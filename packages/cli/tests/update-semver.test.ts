import { describe, expect, it } from "vitest";

import { compareVersionStrings, parseSemver } from "../src/updates/semver.js";

/**
 * 更新確認の「新しい／同じ／古い」は、この比較 1 つに乗っている。
 * rc と stable の前後を間違えると、**rc を使っている人に stable を「古い」と言う**か、
 * その逆で「新しい版なし」と言って止まる。
 */
describe("semver 厳密 parser", () => {
  it("正しい形式を読む", () => {
    expect(parseSemver("0.9.4")).toMatchObject({
      major: 0,
      minor: 9,
      patch: 4,
      prerelease: [],
    });
    expect(parseSemver("1.0.0-rc.1+build.5")).toMatchObject({
      prerelease: ["rc", 1],
      build: ["build", "5"],
    });
    expect(parseSemver("0.9.4-rc3")).toMatchObject({ prerelease: ["rc3"] });
  });

  it.each([
    "v1.0.0",
    "1.0",
    "1.0.0.0",
    "01.0.0",
    "1.0.0-",
    "1.0.0-rc..1",
    "1.0.0-01",
    "1.0.0-rc_1",
    "",
    " 1.0.0",
    "1.0.0 ",
    "latest",
  ])("不正な形式を拒む: %j", (v) => {
    expect(parseSemver(v)).toBeNull();
  });
});

describe("semver 比較", () => {
  const lt = (a: string, b: string) => {
    expect(compareVersionStrings(a, b)).toBeLessThan(0);
    expect(compareVersionStrings(b, a)).toBeGreaterThan(0);
  };

  it("本体の大小", () => {
    lt("0.9.3", "0.9.4");
    lt("0.9.10", "0.10.0");
    lt("0.10.0", "1.0.0");
  });

  it("prerelease は同じ本体の stable より前", () => {
    lt("0.9.4-rc3", "0.9.4");
    lt("1.0.0-alpha", "1.0.0");
  });

  it("prerelease 同士: 数値は数として、文字は辞書順、数値 < 文字", () => {
    lt("0.9.4-rc.2", "0.9.4-rc.10");
    lt("1.0.0-alpha", "1.0.0-alpha.1");
    lt("1.0.0-alpha.1", "1.0.0-alpha.beta");
    lt("1.0.0-alpha.beta", "1.0.0-beta");
    lt("1.0.0-beta.2", "1.0.0-beta.11");
    lt("1.0.0-beta.11", "1.0.0-rc.1");
  });

  it("この製品の実際の版番号（rc は識別子が 1 つ・辞書順になる）", () => {
    // `rc3` と `rc10` は 1 識別子で辞書順なので rc10 < rc3。
    // 発行側が `rc.N` を使わない限りこうなる — 知らずに驚かないよう固定しておく
    lt("0.9.4-rc10", "0.9.4-rc3");
    lt("0.9.4-rc3", "0.9.4");
    lt("0.8.0-rc16", "0.9.4-rc3");
  });

  it("build metadata は比較に影響しない", () => {
    expect(compareVersionStrings("1.0.0+a", "1.0.0+b")).toBe(0);
  });

  it("不正な版番号は比較しない（null）", () => {
    expect(compareVersionStrings("v1.0.0", "1.0.0")).toBeNull();
    expect(compareVersionStrings("1.0.0", "")).toBeNull();
  });
});
