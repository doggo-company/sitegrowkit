import { execFileSync } from "node:child_process";
import { describe, expect, it } from "vitest";

import { shouldCopyTemplatePath } from "../src/distribution.js";

/**
 * `.gitignore` と `distribution.ts` が、**deploy の設定については同じ答えを出す**（D-3）。
 *
 * ## 🔴 寄せない。番人だけ置く
 *
 * この 2 つは**別の問いに答えている**:
 *
 * ```
 * .gitignore        git に入れるか
 * distribution.ts   利用者へ配るか
 * ```
 *
 * **1 つに寄せると、この 2 つを別々に決められなくなる。**
 * だから**寄せず、食い違わないことだけを見る。**
 *
 * ## なぜ見るのか
 *
 * ▷ **実際にずれた**（2026-08-28）。`.gitignore` の例外が**ファイル名ごと**
 * だったので、`production.example.json` が**黙って `git add` されなかった**。
 * **`git add` は何も言わない。**
 *
 * ▷ **この番人は、置いた直後に 1 件見つけた** — `.gitignore` は大小を区別しないが、
 * `distribution.ts` は小文字しか見ない。**接続情報を `PRODUCTION.JSON` と置いた案件では、
 * それが配布物に混ざる。**（B-62）
 *
 * ## 意図した違いは、なぜ意図なのかまで書く
 *
 * 「意図した違い」と書いてあるだけだと、**次の人が本当に意図か確かめ直す。**
 */
const TEMPLATE = new URL("../../../template", import.meta.url).pathname;

/** `.gitignore` の答え（本物の git に聞く） */
function ignored(rel: string): boolean {
  try {
    execFileSync("git", ["check-ignore", "-q", rel], {
      cwd: TEMPLATE,
      stdio: "ignore",
    });

    return true;
  } catch {
    return false;
  }
}

/**
 * 食い違ってよいもの。**なぜ意図なのかを書く。**
 */
const INTENDED: { path: string; why: string }[] = [
  {
    path: ".agentcms/deploy/nested/deep.json",
    why:
      "`.gitignore` の `/.agentcms/deploy/*.json` は `*` が `/` を跨がないので"
      + "入れ子に当たらない。入れ子は実在せず、当たっても配らないのが正しい",
  },
  {
    path: ".agentcms/deploy/EXAMPLE.JSON",
    why:
      "🔴 **番人が見つけた本物**（2026-08-28）。`.gitignore` は大小を区別しないので"
      + "無視するが、`distribution.ts` は `.endsWith('.json')` で小文字しか見ないため"
      + "**配ってしまう**。**接続情報を大文字で置いた案件では、それが配布物に混ざる。**"
      + "実在しないので害は出ていないが、**揃えるべき違い**である → B-62",
  },
];

describe(".gitignore と distribution.ts（D-3）", () => {
  /*
   * 🔴 **素直でない例を最初から入れる。**
   *
   * 素直な入力だけだと差が出ない（2026-08-28 に 4 回踏んだ）。
   * 空・連続記号・大文字・拡張子の重なりを混ぜる。
   */
  const cases = [
    ".agentcms/deploy/production.json",
    ".agentcms/deploy/production.example.json",
    ".agentcms/deploy/staging.json",
    ".agentcms/deploy/staging.example.json",
    ".agentcms/deploy/.example.json",
    ".agentcms/deploy/a.b.example.json",
    ".agentcms/deploy/example.json",
    ".agentcms/deploy/EXAMPLE.JSON",
    ".agentcms/deploy/env.production.example",
    ".agentcms/deploy/build",
    ".agentcms/deploy/nested/deep.json",
  ];

  it("🔴 deploy の設定について、2 つの答えが食い違わない", () => {
    const intended = new Set(INTENDED.map((i) => i.path));

    const mismatched = cases
      .filter((c) => !intended.has(c))
      // `.gitignore` が無視する = 配らない、が揃っているか
      .filter((c) => ignored(c) !== !shouldCopyTemplatePath(c));

    expect(mismatched).toEqual([]);
  });

  it("example は配る（黙って落ちない）", () => {
    // 🔴 `production.example.json` が黙って `git add` されなかった（実測）
    for (const c of [
      ".agentcms/deploy/production.example.json",
      ".agentcms/deploy/staging.example.json",
    ]) {
      expect(ignored(c), c).toBe(false);
      expect(shouldCopyTemplatePath(c), c).toBe(true);
    }
  });

  it("接続情報は配らない", () => {
    for (const c of [
      ".agentcms/deploy/production.json",
      ".agentcms/deploy/staging.json",
    ]) {
      expect(ignored(c), c).toBe(true);
      expect(shouldCopyTemplatePath(c), c).toBe(false);
    }
  });

  it("意図した違いは、理由まで書いてある", () => {
    // 「意図した違い」と書いてあるだけだと、次の人が本当に意図か確かめ直す
    for (const i of INTENDED) {
      expect(i.why.length, i.path).toBeGreaterThan(20);
      // いまも食い違っている（揃ったなら、この一覧から外す）
      expect(ignored(i.path) !== !shouldCopyTemplatePath(i.path), i.path).toBe(true);
    }
  });
});
