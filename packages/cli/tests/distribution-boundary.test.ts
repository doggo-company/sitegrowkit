import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";

import {
  distributesContentType,
  filterRegistryForProject,
  shouldCopyTemplatePath,
} from "../src/distribution.js";

/**
 * 配布境界の回帰（PHASE 9.7 / STAGE 20F）。
 *
 * 生成したサイトが**そこだけで**成り立つことを守る。守れないと、
 * 利用者の手元では「在るはずのものが無い」状態になり、静的解析もテストも落ちる。
 *
 * ここで見るのは 3 つ:
 *   1. 配るファイルの中に、配らないものへの参照が残っていないか
 *   2. registry が、配らない型・存在しない consumer を載せていないか
 *   3. その判断が**型名の hardcode ではなく**配布契約から出ているか
 */

const repoRoot = join(import.meta.dirname, "..", "..", "..");
const templateDir = join(repoRoot, "template");

/** 生成 project に実際に置かれる相対パスを列挙する */
function distributedFiles(): string[] {
  const out: string[] = [];
  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const abs = join(dir, entry.name);
      const rel = relative(templateDir, abs).split(sep).join("/");
      if (!shouldCopyTemplatePath(rel)) continue;
      if (entry.isDirectory()) walk(abs);
      else out.push(rel);
    }
  };
  walk(templateDir);
  return out;
}

/**
 * PHP のコメントを落とす。
 *
 * 見たいのは「静的解析が辿る参照」であって、なぜ配らないかを説明する文章ではない。
 */
function stripComments(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|\s)\/\/[^\n]*/g, "$1")
    .replace(/(^|\s)#[^\n]*/g, "$1");
}

/**
 * 静的解析・品質ゲートの設定から、説明文を落とす。
 *
 * neon / yaml は `#`、xml は `<!-- -->`、json 系は `//` と `/* *\/`。
 * 「なぜ配らないか」を書いたコメントを参照と見なさないため。
 */
function stripConfigComments(rel: string, source: string): string {
  if (rel.endsWith(".xml")) {
    return source.replace(/<!--[\s\S]*?-->/g, "");
  }

  if (rel.endsWith(".neon") || rel.endsWith(".yml") || rel.endsWith(".yaml")) {
    return source.replace(/(^|\s)#[^\n]*/g, "$1");
  }

  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|\s)\/\/[^\n]*/g, "$1");
}

/**
 * 配られる**静的解析・品質ゲートの設定**。
 *
 * PHP だけ見ていると、ここが素通りする（STAGE 20F 是正）。設定に
 * 「配らない module のクラス」が残っていても解析は通ってしまうため、
 * 落ちないまま契約だけが破れる。
 */
function distributedAnalysisConfigs(): string[] {
  const NAMES =
    /^(phpstan|pint|phpunit|psalm|rector|infection)\.(neon|json|xml|php|dist)$/;

  return distributedFiles().filter((rel) => {
    const base = rel.split("/").pop() ?? "";

    return NAMES.test(base) || base.endsWith(".neon");
  });
}

/**
 * `create` がコピー後に当てるのと同じ変換を、同じ入力（template の索引）へ当てる。
 */
function projectRegistry(rel: string): string {
  const source = readFileSync(join(templateDir, ".agentcms", rel), "utf8");
  const filtered = filterRegistryForProject(rel, source);

  expect(filtered, `${rel} が変換されていない`).not.toBeNull();

  return filtered ?? source;
}

describe("配布境界（STAGE 20F）", () => {
  it("配る PHP / Blade が、配らない module を参照しない", () => {
    // 配らないと決めた module の namespace を、配布契約そのものから引く
    const undistributedModules = readdirSync(join(templateDir, "modules"))
      .filter((name) => !shouldCopyTemplatePath(`modules/${name}`))
      .map((name) => `Modules\\${name}\\`);

    expect(
      undistributedModules.length,
      "配らない module が 1 つも無いなら、この回帰は意味を失っている",
    ).toBeGreaterThan(0);

    const offenders: string[] = [];
    for (const rel of distributedFiles()) {
      if (!/\.(php|blade\.php)$/.test(rel)) continue;
      const source = stripComments(
        readFileSync(join(templateDir, rel), "utf8"),
      );
      for (const ns of undistributedModules) {
        // 文字列として持つ参照（`class_exists` で守られた遅延解決）は許す。
        // 静的解析が辿るのは実際の `use` / 型注釈の側だけ
        const asCode = new RegExp(
          `(^|[^'"\\\\])\\\\?${ns.replace(/\\/g, "\\\\")}`,
          "m",
        );
        if (asCode.test(source)) offenders.push(`${rel} → ${ns}`);
      }
    }

    expect(offenders, "配らないものへの参照が配布物に残っている").toEqual([]);
  });

  it("配る静的解析の設定が、配らない module を名指ししない", () => {
    const undistributedModules = readdirSync(join(templateDir, "modules"))
      .filter((name) => !shouldCopyTemplatePath(`modules/${name}`))
      .map((name) => `Modules\\${name}\\`);

    const configs = distributedAnalysisConfigs();

    expect(
      configs,
      "静的解析の設定を 1 つも見ていないなら、この回帰は意味を失っている",
    ).toContain("phpstan.neon");

    const offenders: string[] = [];
    for (const rel of configs) {
      const source = stripConfigComments(
        rel,
        readFileSync(join(templateDir, rel), "utf8"),
      );

      for (const ns of undistributedModules) {
        // JSON では `\` が `\\` で書かれるので、両方の書き方を見る
        const escaped = ns.replace(/\\/g, "\\\\");
        if (source.includes(ns) || source.includes(escaped)) {
          offenders.push(`${rel} → ${ns}`);
        }
      }
    }

    expect(offenders, "配らない module を指す設定が配布物に残っている").toEqual(
      [],
    );
  });

  it("配る seeder / route が、配らないファイルを require しない", () => {
    const offenders: string[] = [];
    for (const rel of distributedFiles()) {
      if (!rel.endsWith(".php")) continue;
      const source = readFileSync(join(templateDir, rel), "utf8");
      for (const m of source.matchAll(
        /require(?:_once)?\s+__DIR__\s*\.\s*'\/([^']+)'/g,
      )) {
        const target = join(rel, "..", m[1] ?? "")
          .split(sep)
          .join("/");
        // vendor/ は composer install が作る（template には最初から無い）
        if (target.startsWith("vendor/")) continue;
        if (shouldCopyTemplatePath(target)) continue;
        // 在るときだけ読む書き方は正しい振る舞い
        if (source.includes(`file_exists(__DIR__.'/${m[1]}')`)) continue;
        offenders.push(`${rel} → ${target}`);
      }
    }

    expect(offenders, "配らないファイルを無条件に require している").toEqual(
      [],
    );
  });

  /**
   * PHP の `require` だけを見ていると、ここが素通りする。
   *
   * 🔴 **実測（2026-08-30・配布物だけで通した実走）**: 配られる
   * `resources/js/lib/__tests__/form-key.test.ts` が、配られない
   * `tests/fixtures/form-key-cases.json` を `resolve(__dirname, …)` で読んでいた。
   * **kit の vitest は緑**（kit には表が在る）なのに、**利用者の
   * `agentcms verify` は無傷のプロジェクトで赤**になる。
   * `68d47f6`（配布物には無い入れ物へ書いていた）と同じ形である。
   *
   * ▷ **静的解析も型検査も、ファイルを読む先までは追わない。**
   *   `ENOENT` は実際に走らせるまで出ないので、ここで数える。
   */
  it("配る TS / JS が、配らないファイルを読まない", () => {
    const offenders: string[] = [];

    for (const rel of distributedFiles()) {
      if (!/\.(ts|tsx|js|mjs|vue)$/.test(rel)) continue;

      const source = stripConfigComments(
        rel,
        readFileSync(join(templateDir, rel), "utf8"),
      );

      // `resolve(__dirname, '../x')` / `join(import.meta.dirname, "../x")` の第 2 引数
      for (const m of source.matchAll(
        /(?:resolve|join)\s*\(\s*(?:__dirname|import\.meta\.dirname)\s*,\s*['"]([^'"]+)['"]/g,
      )) {
        const target = join(rel, "..", m[1] ?? "")
          .split(sep)
          .join("/");

        // template の外（`../../` で出てしまう指定）はここでは扱わない
        if (target.startsWith("..")) continue;
        // node_modules / vendor は install が作る（template には最初から無い）
        if (target.startsWith("node_modules/")) continue;
        if (target.startsWith("vendor/")) continue;
        if (shouldCopyTemplatePath(target)) continue;

        offenders.push(`${rel} → ${target}`);
      }
    }

    expect(offenders, "配らないファイルを読む試験が配布物に残っている").toEqual(
      [],
    );
  });

  it("content-types registry は配る型だけを載せる", () => {
    const parsed = JSON.parse(
      projectRegistry("registry/content-types.json"),
    ) as {
      types: { id: string; schema: string }[];
    };

    expect(parsed.types.length).toBeGreaterThan(0);
    for (const type of parsed.types) {
      expect(
        distributesContentType(type.id),
        `${type.id} は配られないのに registry に残っている`,
      ).toBe(true);
      // registry が指す定義ファイルも一緒に配られていること
      expect(
        shouldCopyTemplatePath(type.schema.replace(/^template\//, "")),
        `${type.schema} は配られない`,
      ).toBe(true);
    }
  });

  it("taxonomies registry は配らない定義も、居ない consumer も残さない", () => {
    const parsed = JSON.parse(projectRegistry("registry/taxonomies.json")) as {
      taxonomies: { key: string; definition: string; consumers?: string[] }[];
    };

    // template には在る（配らないだけ）ものが、確かに落ちていること
    expect(parsed.taxonomies.map((t) => t.key)).not.toContain("news_category");

    for (const taxonomy of parsed.taxonomies) {
      expect(
        shouldCopyTemplatePath(taxonomy.definition.replace(/^template\//, "")),
        `${taxonomy.key} の定義は配られない`,
      ).toBe(true);
      for (const consumer of taxonomy.consumers ?? []) {
        // `agentcms:taxonomy-check` はここが崩れると落ちる
        expect(
          distributesContentType(consumer),
          `${taxonomy.key} の consumer [${consumer}] は生成 project に居ない`,
        ).toBe(true);
      }
    }
  });

  it("kit と template の registry には例が載ったままである（消すのではなく配らない）", () => {
    /*
     * `template/.agentcms/` は **template 自身の索引**である。template には
     * `news_category` の宣言が実在するので、ここから消すと kit の
     * `agentcms:taxonomy-check` が「宣言は在るのに索引に居ない」と言って落ちる。
     * 落とすのは `create` がコピーした**後**（`filterRegistryForProject`）。
     */
    for (const path of [
      ".agentcms/registry/taxonomies.json",
      "template/.agentcms/registry/taxonomies.json",
    ]) {
      const registry = JSON.parse(
        readFileSync(join(repoRoot, path), "utf8"),
      ) as { taxonomies: { key: string }[] };

      expect(
        registry.taxonomies.map((t) => t.key),
        path,
      ).toContain("news_category");
    }
  });

  /**
   * 視覚回帰の同梱は**例外を 1 本だけ**開けた穴である（R-15 Stage 2）。
   *
   * `tests/e2e` を許可した以上、**新しく置いた e2e が黙って配られる**。
   * 標準 E2E は `globalSetup` が `docker compose exec` と kit の seeder を前提にしていて、
   * 配ると利用者の手元で必ず落ちる。だから穴の大きさをここで固定する。
   */
  it("配る e2e は視覚回帰の 1 本だけ、設定も setup 無しの方だけ", () => {
    const e2e = distributedFiles().filter((rel) => rel.startsWith("tests/e2e"));

    expect(e2e).toStrictEqual(["tests/e2e/template-visual.spec.ts"]);

    expect(shouldCopyTemplatePath("playwright.visual.config.ts")).toBe(true);
    expect(shouldCopyTemplatePath("playwright.config.ts")).toBe(false);
    expect(shouldCopyTemplatePath("playwright.feel.config.ts")).toBe(false);
    expect(shouldCopyTemplatePath("tests/e2e/global-setup.ts")).toBe(false);

    // 例外の書式は配る（実ファイルは kit の例外なので配らない）
    expect(
      shouldCopyTemplatePath(".agentcms/visual-thresholds.example.json"),
    ).toBe(true);
  });

  /**
   * 配る視覚回帰が、**kit の置き場所と kit の型名に寄りかかっていない**こと。
   *
   * この spec が利用者の環境で読み込めないと、`loadPages()` が `null` を返して
   * **1 枚も比べないまま緑になる**（実測: 変更前の spec を利用者の配置に置くと 0 tests）。
   * playwright は品質ゲートに入っていないので、せめて**寄りかかりの形**をここで止める。
   */
  it("配る視覚回帰は、kit の階層も kit の型名も前提にしない", () => {
    const code = stripComments(
      readFileSync(
        join(templateDir, "tests/e2e/template-visual.spec.ts"),
        "utf8",
      ),
    );

    // kit は template/tests/e2e（3 つ上が root）、利用者は tests/e2e（2 つ上）
    expect(code).not.toContain("../../../.agentcms");

    // seed の型名を名指しすると、`works` を持たない利用者で詳細ページが 0 枚になる
    for (const id of ["works", "news", "product"]) {
      expect(code, `seeds.${id} を名指ししている`).not.toContain(`seeds.${id}`);
    }
  });

  it("生成物へ配るテストは smoke だけで、kit の例に依存しない", () => {
    const tests = distributedFiles().filter((rel) => rel.startsWith("tests/"));

    expect(tests.length, "smoke を 1 つも配っていない").toBeGreaterThan(0);
    for (const rel of tests) {
      expect(
        rel === "tests/TestCase.php" ||
          /^tests\/(Unit|Feature)\/Smoke\//.test(rel) ||
          // 視覚回帰だけは例外（R-15 Stage 2）。下の it が「1 本だけ」を見張る
          rel === "tests/e2e/template-visual.spec.ts",
        `${rel} は生成物へ配る対象ではない`,
      ).toBe(true);
    }

    // phpunit.xml の testsuite が指す入れ物が生成物にも在ること
    const phpunit = readFileSync(join(templateDir, "phpunit.xml"), "utf8");
    for (const m of phpunit.matchAll(/<directory>([^<]+)<\/directory>/g)) {
      const dir = (m[1] ?? "").replace(/^\.\//, "");
      // glob は当たらなければ空集合になるだけで、PHPUnit は落ちない
      if (dir.includes("*")) continue;
      expect(
        shouldCopyTemplatePath(dir),
        `phpunit.xml が指す ${dir} が生成物に無い`,
      ).toBe(true);
      expect(statSync(join(templateDir, dir)).isDirectory()).toBe(true);
    }
  });
});
