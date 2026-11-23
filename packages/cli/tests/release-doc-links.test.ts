/**
 * 配布物の文書リンクが、配布物の中で生きているか（2026-09-06 / F-16）。
 *
 * `docs/` は一覧（`scripts/customer-docs.mjs`）で選んで入れているので、
 * **一覧から漏れたファイルへのリンクは、買った人の手元でだけ 404 になる**。
 * 書いた本人は自分のリポジトリで開くので、気づけない形で壊れる。
 *
 * 実測（0.9.4-rc2）: `MIGRATE_FROM_WORDPRESS.md` が一覧に無く、
 * README_FIRST §7 のリンクも、`wxr-plan` が止まったときの案内も、
 * **配布物に存在しないファイルを指していた**。
 *
 * ここは `package-release.mjs` を走らせずに同じ判断を試す
 * （本体は build も npm pack も smoke test もするので、走らせないと試せない形では
 * この門は試されないまま置かれる — `release-guard.test.ts` と同じ作法）。
 */
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

// @ts-expect-error — repo 直下のスクリプト（型定義は持たない。release-guard.test.ts と同じ扱い）
import { CUSTOMER_DOCS } from "../../../scripts/customer-docs.mjs";
// @ts-expect-error — 同上
import { findBrokenDocLinks } from "../../../scripts/doc-links.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../../..");

const customerDocs: string[] = CUSTOMER_DOCS;

/** `package-release.mjs` が bundle（zip を開いた中身）へ置くファイル */
const bundleFiles = (): string[] => [
  "README_FIRST.md",
  "LICENSE",
  "COPYRIGHT",
  "THIRD-PARTY-LICENSES.md",
  "release-manifest.json",
  "SHA256SUMS",
  ...customerDocs.map((d) => `docs/${d}`),
];

/** `npm pack` に入る（＝プロジェクト生成側が読む）文書 */
const tgzDocFiles = (): string[] => [
  "README.md",
  "CHANGELOG.md",
  "LICENSE",
  "COPYRIGHT",
  "THIRD-PARTY-LICENSES.md",
  ...customerDocs.map((d) => `docs/${d}`),
];

const readFromRepo = (rel: string): string =>
  readFileSync(join(repoRoot, rel), "utf8");

describe("配布物の文書リンク（F-16）", () => {
  it.each(customerDocs)("docs/%s が repo に在る", (doc) => {
    expect(existsSync(join(repoRoot, "docs", doc))).toBe(true);
  });

  it("bundle（zip）の中でリンクが全部生きている", () => {
    expect(
      findBrokenDocLinks({ files: bundleFiles(), read: readFromRepo }),
    ).toEqual([]);
  });

  it(".tgz の中でリンクが全部生きている", () => {
    expect(
      findBrokenDocLinks({ files: tgzDocFiles(), read: readFromRepo }),
    ).toEqual([]);
  });

  /**
   * **一覧から抜けたら気づけること**を、ここで直接示す。
   * 抜いた状態を作って「壊れる」と言えないなら、上の 2 つは何も守っていない。
   */
  it("MIGRATE_FROM_WORDPRESS.md を一覧から抜くと、README_FIRST のリンクが切れると言う", () => {
    const without = bundleFiles().filter(
      (f) => f !== "docs/MIGRATE_FROM_WORDPRESS.md",
    );

    const broken = findBrokenDocLinks({ files: without, read: readFromRepo });

    expect(broken).toHaveLength(1);
    expect(broken[0]).toContain("README_FIRST.md");
    expect(broken[0]).toContain("docs/MIGRATE_FROM_WORDPRESS.md");
  });

  /**
   * 案内文（`wxr-plan` が止まったときに出す行）が名指しするファイルは、
   * **配布物に在る**こと。README_FIRST のリンクとは別の壊れ方だったので別に見る。
   */
  it("wxr-plan の案内が名指しする文書が配布物に在る", () => {
    const command = readFromRepo(
      "template/core/src/Wxr/Console/PlanWxrCommand.php",
    );
    const named = [...command.matchAll(/docs\/([A-Za-z0-9_/.-]+\.md)/g)].map(
      (m) => m[1],
    );

    expect(named.length).toBeGreaterThan(0);

    for (const doc of new Set(named)) {
      expect(customerDocs).toContain(doc);
    }
  });
});

describe("findBrokenDocLinks の見かた", () => {
  const read = (files: Record<string, string>) => (rel: string) => files[rel];

  it("外の世界（http / mailto）は見ない", () => {
    const files = {
      "a.md": "[x](https://example.com/y.md) [m](mailto:a@example.com)",
    };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual([]);
  });

  it("ページ内リンク（#見出し）は見ない", () => {
    const files = { "a.md": "[x](#midashi)" };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual([]);
  });

  it("アンカー付きでも、ファイルの実在だけを見る", () => {
    const files = { "a.md": "[x](docs/b.md#anywhere)", "docs/b.md": "" };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual([]);
  });

  it("相対の .. をたどる", () => {
    const files = { "docs/a.md": "[x](../LICENSE)", LICENSE: "" };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual([]);
  });

  it("配布物の外へ出る .. は切れているとみなす", () => {
    const files = { "a.md": "[x](../outside.md)" };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toHaveLength(1);
  });

  it("コードブロックの中の丸括弧はリンクと読まない", () => {
    const files = {
      "a.md": "```bash\n--taxonomy=[a](b/c.md)\n```\n",
    };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual([]);
  });

  it("無いファイルを指していたら言う", () => {
    const files = { "a.md": "[x](docs/nope.md)" };

    expect(
      findBrokenDocLinks({ files: Object.keys(files), read: read(files) }),
    ).toEqual(["a.md → docs/nope.md（配布物にありません）"]);
  });
});
