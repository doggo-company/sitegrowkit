import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * **CLI が人へ見せる名前は SiteGrowKit**（2026-08-25 に改名）。
 *
 * 🔴 2026-08-31、メンテナが**ログイン画面のロゴが旧名**なのを見つけた。
 * 同じ日に `template/` 側は直ったが（`ProductNameTest`）、**CLI 側は 33 箇所残っていた** —
 * `agentcms doctor` の 1 行目・セットアップ画面のタイトル・`create` の見出しなど、
 * **利用者がターミナルで最初に読む文字**である。
 *
 * 試験は全部緑だった。**名前は動作に影響しないので、見に行かなければ古いまま出続ける。**
 *
 * ## 変えてはいけない 2 箇所（allowlist）
 *
 * ここは「旧名が残っていること」に意味がある。**消すと壊れる。**
 */
const REPO_ROOT = join(import.meta.dirname, "..", "..", "..");
const SRC = join(REPO_ROOT, "packages/cli/src");

/** 旧名を残してよい場所と、その理由 */
const ALLOWED = new Map([
  [
    "deploy/release.ts",
    "既に配ったスタブの中に入っている目印。変えると前回の自分を他人と読んで 2 回目のデプロイが止まる（B-44）",
  ],
  [
    "deploy/providers/xserver-setup.ts",
    "顧客サイトの APP_NAME 既定。ここを製品名にすると顧客のサイト名が SiteGrowKit になる",
  ],
]);

const OLD = ["Agent CMS", "AgentCMS", "Agent-CMS"];

function sourceFiles(dir: string): string[] {
  const out: string[] = [];

  for (const entry of readdirSync(dir)) {
    const abs = join(dir, entry);

    if (statSync(abs).isDirectory()) {
      out.push(...sourceFiles(abs));
      continue;
    }

    if (/\.(ts|html|mjs)$/.test(entry)) out.push(abs);
  }

  return out;
}

describe("商品名", () => {
  it("CLI の src に旧名が残っていない（許した 2 箇所を除く）", () => {
    const offenders: string[] = [];

    for (const abs of sourceFiles(SRC)) {
      const rel = relative(SRC, abs).split("\\").join("/");

      if (ALLOWED.has(rel)) continue;

      const body = readFileSync(abs, "utf8");

      for (const old of OLD) {
        if (body.includes(old)) offenders.push(`${rel} → 「${old}」`);
      }
    }

    expect(
      [...new Set(offenders)],
      "古い商品名が CLI に残っている（人が読む文字。ここは利用者が最初に見る）",
    ).toEqual([]);
  });

  /**
   * **許した 2 箇所は、いまも旧名を持っていること。**
   *
   * 「消してはいけない」と書いた以上、**消えたら気づける**必要がある。
   * ここが空振りすると、上の試験だけ緑のまま**デプロイが静かに壊れる**。
   */
  it("変えてはいけない 2 箇所は旧名のまま", () => {
    for (const [rel, why] of ALLOWED) {
      const body = readFileSync(join(SRC, rel), "utf8");

      expect(
        body.includes("Agent CMS"),
        `${rel} から旧名が消えている。${why}`,
      ).toBe(true);
    }
  });

  it("配布 package の description が SiteGrowKit", () => {
    const pkg = JSON.parse(
      readFileSync(join(REPO_ROOT, "packages/cli/package.json"), "utf8"),
    ) as { description: string };

    expect(pkg.description).toContain("SiteGrowKit");
    expect(pkg.description).not.toContain("Agent CMS");
  });
});
