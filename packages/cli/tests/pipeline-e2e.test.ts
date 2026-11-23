import { execFileSync } from "node:child_process";
import {
  appendFileSync,
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, sep } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

// Template Import Pipeline の CLI 一気通貫テスト（spec §59: 1–6, 27, 28）。
// 一時 workspace 上で inspect → plan → apply → 再 apply(no-op) → source 変更 → stale 拒否 を実行する。

const CLI = resolve(__dirname, "../dist/index.js");
const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

let ws: string;

function run(
  args: string[],
  opts: { cwd: string; expectFail?: boolean },
): { code: number; out: string } {
  try {
    const out = execFileSync("node", [CLI, ...args], {
      cwd: opts.cwd,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    });
    return { code: 0, out };
  } catch (e) {
    const err = e as { status?: number; stdout?: string; stderr?: string };
    if (!opts.expectFail) {
      throw new Error(
        `CLI failed: ${args.join(" ")}\n${err.stdout ?? ""}${err.stderr ?? ""}`,
      );
    }
    return {
      code: err.status ?? 1,
      out: `${err.stdout ?? ""}${err.stderr ?? ""}`,
    };
  }
}

beforeAll(() => {
  expect(
    existsSync(CLI),
    "dist が必要です（pnpm -C packages/cli build を先に実行）",
  ).toBe(true);
  ws = mkdtempSync(join(tmpdir(), "agentcms-pipeline-"));
  // source テンプレート
  cpSync(FIXTURE, join(ws, "site-template"), { recursive: true });
  // 最小プロジェクト（artisan + docker-compose.yml + schemas + .agentcms + .env）
  const project = join(ws, "project");
  mkdirSync(join(project, "content", "schemas"), { recursive: true });
  // **配布されるのは base だけ。** overrides/ は開発機の状態なので持ち込まない
  cpSync(SCHEMAS, join(project, "content", "schemas"), {
    recursive: true,
    filter: (src) => !src.includes(`${sep}overrides`),
  });
  writeFileSync(join(project, "artisan"), "");
  writeFileSync(join(project, "docker-compose.yml"), "services: {}\n");
  writeFileSync(join(project, ".env"), "APP_NAME=pipeline-test\n");
  writeFileSync(join(project, ".env.example"), "APP_NAME=pipeline-test\n");
  mkdirSync(join(project, ".agentcms"), { recursive: true });
  execFileSync("git", ["init", "-q"], { cwd: project });
});

afterAll(() => {
  rmSync(ws, { recursive: true, force: true });
});

describe("template pipeline (CLI end-to-end)", () => {
  it("1–2. inspect が Manifest を生成する", () => {
    const project = join(ws, "project");
    const res = run(["template", "inspect", "../site-template"], {
      cwd: project,
    });
    expect(res.out).toContain("Manifest を出力しました");
    expect(
      existsSync(join(project, ".agentcms", "manifest", "template.json")),
    ).toBe(true);
  });

  it("3. Manifest は決定論的（再 inspect で同一内容）", () => {
    const project = join(ws, "project");
    const manifestPath = join(
      project,
      ".agentcms",
      "manifest",
      "template.json",
    );
    const before = readFileSync(manifestPath, "utf8");
    run(["template", "inspect", "../site-template"], { cwd: project });
    expect(readFileSync(manifestPath, "utf8")).toBe(before);
  });

  it("4. plan が生成される", () => {
    const project = join(ws, "project");
    const res = run(["template", "plan"], { cwd: project });
    expect(res.out).toContain("Plan を出力しました");
    expect(
      existsSync(join(project, ".agentcms", "manifest", "template-plan.json")),
    ).toBe(true);
    expect(existsSync(join(project, "docs", "template-import-plan.md"))).toBe(
      true,
    );
  });

  it("5–6. apply が成功し、成果物とレコードが揃う", () => {
    const project = join(ws, "project");
    const res = run(["template", "apply"], { cwd: project });
    expect(res.out).toContain("適用完了");
    for (const target of [
      "resources/views/layouts/imported.blade.php",
      "resources/views/public/home.blade.php",
      "modules/Works/views/index.blade.php",
      "modules/Contact/views/form.blade.php",
      "public/imported/css/style.css",
      "database/seeders/TemplateContentSeeder.php",
      ".agentcms/manifest/template-apply.json",
    ]) {
      expect(existsSync(join(project, target)), target).toBe(true);
    }
    // schema へ choices が追加されている
    const works = readFileSync(
      join(project, "content", "schemas", "works.json"),
      "utf8",
    );
    expect(works).toContain('"EC"');
    // .env へ CSP 追加
    expect(readFileSync(join(project, ".env"), "utf8")).toContain(
      "AGENTCMS_CSP_SCRIPT_SRC=https://cdn.jsdelivr.net",
    );
  });

  it("27. 同じ Plan の再 apply は no-op で安全", () => {
    const project = join(ws, "project");
    const home = join(project, "resources/views/public/home.blade.php");
    const before = readFileSync(home, "utf8");
    const res = run(["template", "apply"], { cwd: project });
    expect(res.out).toContain("no-op");
    expect(readFileSync(home, "utf8")).toBe(before);
  });

  it("27b. apply 後の手動変更は conflict として STOP する", () => {
    const project = join(ws, "project");
    const home = join(project, "resources/views/public/home.blade.php");
    appendFileSync(home, "{{-- hand edit --}}\n");
    const res = run(["template", "apply"], { cwd: project, expectFail: true });
    expect(res.code).toBe(1);
    expect(res.out).toContain("conflict");
    // --force で backup の上、復元される
    const forced = run(["template", "apply", "--force"], { cwd: project });
    expect(forced.out).toContain("backup");
    expect(readFileSync(home, "utf8")).not.toContain("hand edit");
  });

  it("28. source 変更後の古い Plan は拒否される（stale plan）", () => {
    const project = join(ws, "project");
    appendFileSync(
      join(ws, "site-template", "index.html"),
      "<!-- changed -->\n",
    );
    const res = run(["template", "apply", "--force"], {
      cwd: project,
      expectFail: true,
    });
    expect(res.code).toBe(1);
    expect(res.out).toContain("stale plan");
    expect(res.out).toContain("inspect");
  });
});
