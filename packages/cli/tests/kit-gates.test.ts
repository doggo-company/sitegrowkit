/**
 * kit 側の門の判断（B-19）。
 *
 * 2026-08-20 の 1 日で **3 回**すり抜けが起きた穴を塞ぐ。3 件とも
 * 「**門は在ったが、走らせる側が選び間違えた**」なので、**選択を機械へ移す**のが根治である。
 * ここはその判断だけを試す（`release-guard.test.ts` と同じ作法）。
 */
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

// @ts-expect-error — repo 直下のスクリプト（型定義は持たない。release-guard.test.ts と同じ扱い）
import * as gates from "../../../scripts/kit-gates.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../../..");

describe("触った場所から門を決める", () => {
  it("kit 半分は、何も触っていなくても要る", () => {
    // 「変更が無い＝回さなくてよい」は、**HEAD が壊れている場合を見逃す**
    expect(gates.requiredGates([]).kit.required).toBe(true);
  });

  /**
   * **ここが今日の実測そのものである。**
   *
   * `packages/cli` を触っていなくても、`template/` だけの変更で kit の試験は赤くなった
   * （catalog は PHP 側に在り、配る側の PHP テストは kit の検査が見ている）。
   */
  it("template だけ触っても kit 半分は要る", () => {
    expect(
      gates.requiredGates(["template/app/Support/OperationCatalog.php"]).kit
        .required,
    ).toBe(true);
  });

  it("template を触っていなければ template 半分は要らない", () => {
    const result = gates.requiredGates([
      "packages/cli/src/mcp/tools.ts",
      "docs/BACKLOG.md",
    ]);

    expect(result.template.required).toBe(false);
    expect(result.template.why).toContain("触っていない");
  });

  it("template を触ったら template 半分が要る", () => {
    const result = gates.requiredGates([
      "template/resources/js/app.ts",
      "scripts/kit-gates.mjs",
    ]);

    expect(result.template.required).toBe(true);
    // **何件が根拠になったか**まで言う（1 件だけ見て決めていないことが読める）
    expect(result.template.why).toContain("1 件");
  });

  /** **前方一致で拾い過ぎない。** `templates/` は `template/` ではない */
  it("名前が似ているだけの path を template と読まない", () => {
    expect(
      gates.requiredGates(["templates/legacy.html", "template-notes.md"])
        .template.required,
    ).toBe(false);
  });
});

describe("触り心地の門（docs/feel-bar.md）", () => {
  it("画面を触ったら必ず回す", () => {
    for (const path of [
      "template/resources/js/components/ui/button/index.ts",
      "template/resources/css/app.css",
      "template/app/Http/Controllers/Admin/PageAdminController.php",
      "template/tests/e2e/feel/feel.spec.ts",
      "docs/feel-bar.md",
    ]) {
      expect(gates.requiredGates([path]).feel.required, path).toBe(true);
    }
  });

  it("画面に効かない所だけなら回さない（理由を言う）", () => {
    const result = gates.requiredGates([
      "packages/cli/src/mcp/tools.ts",
      "template/content/pages/about.json",
    ]);

    expect(result.feel.required).toBe(false);
    expect(result.feel.why).toContain("触っていない");
  });

  it("kit-verify から外されていない", () => {
    const verify = readFileSync(join(repoRoot, "scripts/kit-verify.mjs"), "utf8");

    expect(verify).toContain("FEEL_GATE");
    expect(verify).toContain("playwright.feel.config.ts");
  });
});

describe("何と比べて決めたかを言う", () => {
  it("基準と件数を 1 行で出す", () => {
    const line = gates.basisLine(["a.ts", "b.ts", ""]) as string;

    expect(line).toContain("HEAD");
    expect(line).toContain("untracked");
    // 空行は数えない（`git` の出力を split した名残で件数が水増しされる）
    expect(line).toContain("2 件");
  });
});

describe("回さなかった門を緑と言わせない", () => {
  it("回していない半分には、回す 1 行を添える", () => {
    const lines = gates.closingLines([
      { name: "kit", ran: true, ok: true, how: "" },
      {
        name: "template",
        ran: false,
        ok: false,
        how: "node packages/cli/dist/index.js verify",
      },
    ]) as string[];

    expect(lines[0]).toContain("✔");
    expect(lines[1]).toContain("回していません");
    expect(lines[1]).toContain("node packages/cli/dist/index.js verify");
  });

  it("落ちた門は ✔ にならない", () => {
    const lines = gates.closingLines([
      { name: "kit", ran: true, ok: false, how: "" },
    ]) as string[];

    expect(lines[0]).toContain("✖");
    expect(lines[0]).not.toContain("✔");
  });
});

/**
 * **commit の前に回す門へ HTTP を混ぜない**（B-20）。
 *
 * `template verify` の HTTP 検査は `APP_URL`（既定 `http://localhost:8180`）の
 * **そこに居るものを何でも見る**。2026-08-20 に実際、:8180 に居た**別のプロジェクト**の
 * `/works` 404 でこの門が赤くなった。**たまたま何が動いているかで色が変わる門**は、
 * いずれ「また赤い」と読み飛ばされる（B-2 の教訓）。
 */
describe("template 半分に載せる門", () => {
  it("agentcms verify と template verify の 2 つを回す", () => {
    const names = (gates.TEMPLATE_GATES as { name: string }[]).map(
      (g) => g.name,
    );

    expect(names).toEqual([
      "agentcms verify",
      "agentcms template verify --offline",
    ]);
  });

  it("template verify は必ず --offline で回す", () => {
    const gate = (gates.TEMPLATE_GATES as { args: string[] }[]).find((g) =>
      g.args.includes("template"),
    );

    expect(
      gate?.args,
      "HTTP 検査が混ざると、別プロジェクトの応答で赤くなる",
    ).toContain("--offline");
  });
});

/**
 * **利用者の門に kit の都合を混ぜない**（B-19 の設計の核心）。
 *
 * `agentcms verify` は生成プロジェクトの中で回るものなので、そこに `packages/cli` は無い。
 * 「ついでに verify で kit の試験も回す」という将来の変更を、ここで赤にして止める。
 */
describe("verify は利用者のサイトの門のままである", () => {
  it("verify が packages/cli を参照していない", () => {
    const source = readFileSync(
      join(repoRoot, "packages/cli/src/commands/verify.ts"),
      "utf8",
    );

    expect(source).not.toContain("packages/cli");
  });
});
