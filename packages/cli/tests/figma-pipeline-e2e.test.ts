/**
 * Figma Pipeline の CLI 一気通貫テスト（SPEC §100: 1,3,7,15,21,23,24,25,26,38,39）。
 * 一時 workspace + Synthetic Fixture（AGENTCMS_FIGMA_FIXTURE）で
 * connect → inspect → plan → apply → 再 apply(no-op) → 手動変更 conflict → Figma 更新 stale 拒否
 * を実行する。実 Figma API へは一切接続しない。
 */
import { execFileSync } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, sep } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

const CLI = resolve(__dirname, "../dist/index.js");
const FIXTURE = resolve(__dirname, "fixtures/figma");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

let ws: string;
let project: string;
let fixtureDir: string;

function run(
  args: string[],
  opts: { expectFail?: boolean } = {},
): { code: number; out: string } {
  try {
    const out = execFileSync("node", [CLI, ...args], {
      cwd: project,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      env: { ...process.env, AGENTCMS_FIGMA_FIXTURE: fixtureDir },
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
  ws = mkdtempSync(join(tmpdir(), "agentcms-figma-e2e-"));
  fixtureDir = join(ws, "fixture");
  cpSync(FIXTURE, fixtureDir, { recursive: true });

  project = join(ws, "project");
  mkdirSync(join(project, "content", "schemas"), { recursive: true });
  // **配布されるのは base だけ。** overrides/ は開発機の状態なので持ち込まない
  cpSync(SCHEMAS, join(project, "content", "schemas"), {
    recursive: true,
    filter: (src) => !src.includes(`${sep}overrides`),
  });
  writeFileSync(join(project, "artisan"), "");
  writeFileSync(join(project, "docker-compose.yml"), "services: {}\n");
  writeFileSync(join(project, ".env"), "APP_NAME=figma-test\n");
  mkdirSync(join(project, "routes"), { recursive: true });
  writeFileSync(join(project, "routes", "web.php"), "<?php\n");
  mkdirSync(join(project, "resources", "css"), { recursive: true });
  writeFileSync(
    join(project, "resources", "css", "public.css"),
    ":root {\n    --color-accent: #0f4c46;\n    --color-text: #1c1d20;\n}\n",
  );
  mkdirSync(join(project, ".agentcms"), { recursive: true });
  writeFileSync(
    join(project, ".gitignore"),
    ".agentcms/cache/\n.agentcms/figma/project.json\n",
  );
  execFileSync("git", ["init", "-q"], { cwd: project });
});

afterAll(() => {
  rmSync(ws, { recursive: true, force: true });
});

/** プロジェクト内のファイル内容（byte 比較用。schema 自身は差し替えるので除く） */
function snapshotProject(
  dir = project,
  base = project,
): Record<string, string> {
  const out: Record<string, string> = {};

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === ".git") continue;

    const path = join(dir, entry.name);
    const rel = path.slice(base.length + 1);

    if (entry.isDirectory()) {
      Object.assign(out, snapshotProject(path, base));
    } else if (entry.isFile() && rel !== "content/schemas/works.json") {
      out[rel] = readFileSync(path).toString("base64");
    }
  }

  return out;
}

describe("figma pipeline (CLI end-to-end)", () => {
  it("1. connect が URL を解析し config を保存する", () => {
    const res = run([
      "figma",
      "connect",
      "https://www.figma.com/design/FKEYFIXTURE0001/Northwind?node-id=0-1",
    ]);
    expect(res.out).toContain("FKEYFIXTURE0001");
    const cfg = JSON.parse(
      readFileSync(join(project, ".agentcms", "figma", "project.json"), "utf8"),
    ) as { entry_nodes: string[] };
    expect(cfg.entry_nodes).toEqual(["0:1"]);
  });

  it("2. inspect が Design Manifest を生成する（node-targeted）", () => {
    const res = run(["figma", "inspect"]);
    expect(res.out).toContain("Design Manifest");
    expect(res.out).toContain("Manifest First");
    const manifest = JSON.parse(
      readFileSync(
        join(project, ".agentcms", "manifest", "figma.json"),
        "utf8",
      ),
    ) as { screens: unknown[]; source: { selected_nodes: string[] } };
    expect(manifest.screens).toHaveLength(2);
    expect(manifest.source.selected_nodes).toEqual(["0:1"]);
  });

  it("3. 2 回目の inspect は Raw Cache を使い、--renders が Visual Baseline を保存する", () => {
    const res = run(["figma", "inspect", "--renders"]);
    expect(res.out).toContain("Raw Cache hit");
    expect(res.out).toContain("Visual Baseline");
    const rendersDir = join(
      project,
      ".agentcms",
      "cache",
      "figma",
      "FKEYFIXTURE0001",
      "renders",
    );
    expect(existsSync(join(rendersDir, "1-2-v2001.png"))).toBe(true);
    expect(existsSync(join(rendersDir, "1-3-v2001.png"))).toBe(true);
  });

  it("4. plan が confidence 付き Plan と registry を生成する", () => {
    const res = run(["figma", "plan"]);
    expect(res.out).toContain("Plan:");
    const plan = JSON.parse(
      readFileSync(
        join(project, ".agentcms", "manifest", "figma-plan.json"),
        "utf8",
      ),
    ) as { tokens: { status: string }[]; uncertainties: unknown[] };
    expect(plan.tokens.some((t) => t.status === "CONFLICT")).toBe(true);
    expect(plan.uncertainties.length).toBeGreaterThan(0);
    expect(
      existsSync(join(project, "docs", "figma-implementation-plan.md")),
    ).toBe(true);
    const registry = JSON.parse(
      readFileSync(
        join(project, ".agentcms", "components", "registry.json"),
        "utf8",
      ),
    ) as { components: { status: string }[] };
    expect(registry.components.length).toBeGreaterThanOrEqual(5);
    expect(registry.components.every((c) => c.status !== "mapped")).toBe(true);
  });

  it("4-b. コンテンツ定義が壊れていたら、1 つも書かずに止まる", () => {
    // 定義を根拠に override を書く以上、読めないまま生成物や route を
    // 適用してはいけない（途中まで適用された状態が残る）
    const schema = join(project, "content", "schemas", "works.json");
    const original = readFileSync(schema, "utf8");

    writeFileSync(schema, "{ broken");

    const before = snapshotProject();
    const res = run(["figma", "apply"], { expectFail: true });

    writeFileSync(schema, original);

    expect(res.out).toContain("何も変更していません");
    // 生成物・route・registry・provenance のいずれも変わっていない
    expect(snapshotProject()).toStrictEqual(before);
  });

  it("5. apply が Blade / CSS / routes / seeder / asset を生成する（Figma へ write 0）", () => {
    const res = run(["figma", "apply"]);
    expect(res.out).toContain("書き込みました");
    expect(res.out).toContain("Figma への書き込みは行っていません");
    expect(
      existsSync(join(project, "resources/views/figma/home.blade.php")),
    ).toBe(true);
    expect(
      existsSync(join(project, "resources/views/figma/works.blade.php")),
    ).toBe(true);
    expect(
      existsSync(join(project, "resources/views/figma/layout.blade.php")),
    ).toBe(true);
    expect(
      existsSync(
        join(project, "resources/views/figma/components/works-card.blade.php"),
      ),
    ).toBe(true);
    expect(existsSync(join(project, "public/figma/css/figma.css"))).toBe(true);
    expect(existsSync(join(project, "routes/figma.php"))).toBe(true);
    expect(
      existsSync(join(project, "database/seeders/FigmaContentSeeder.php")),
    ).toBe(true);
    // Reference-Based asset（CONTENT_MEDIA → seeder 用 / hero → static）
    expect(existsSync(join(project, "database/seeders/figma-import"))).toBe(
      true,
    );
    expect(readFileSync(join(project, "routes", "web.php"), "utf8")).toContain(
      "figma.php",
    );
    // schema override（body 必須解除）と choices 追加（Branding / Web）。
    // **base は触らない** — kit が配った定義を import 結果で汚さないため（#23 / #24）
    const baseBefore = readFileSync(join(SCHEMAS, "works.json"), "utf8");
    const override = JSON.parse(
      readFileSync(
        join(project, "content/schemas/overrides/works.json"),
        "utf8",
      ),
    ) as { fields: Record<string, { choices?: string[] }> };

    expect(override.fields.body).toEqual({ required: false });
    // 取り込んだ表記は override 側へ入る
    expect(override.fields.category?.choices).toContain("Web");
    expect(override.fields.category?.choices).toContain("Branding");
    // base 側は配布物のまま（choices も byte も変わらない）
    expect(
      readFileSync(join(project, "content/schemas/works.json"), "utf8"),
    ).toBe(baseBefore);
    // registry が mapped へ昇格
    const registry = JSON.parse(
      readFileSync(
        join(project, ".agentcms", "components", "registry.json"),
        "utf8",
      ),
    ) as { components: { component_id: string; status: string }[] };
    expect(
      registry.components.find((c) => c.component_id === "works-card")?.status,
    ).toBe("mapped");
    // 生成 blade は CMS を Content Source にする（Figma テキストの直書きではなく $works ループ）
    const home = readFileSync(
      join(project, "resources/views/figma/home.blade.php"),
      "utf8",
    );
    expect(home).toContain("@foreach ($works as $work)");
    expect(home).not.toContain("WORKS A"); // sample content は seeder 側（CMS First）
  });

  it("6. 同一 Plan の再 apply は no-op（SPEC §65）", () => {
    const res = run(["figma", "apply"]);
    expect(res.out).toContain("no-op");
  });

  it("7. 手動変更されたファイルへの apply は conflict STOP（SPEC §64）", () => {
    const target = join(project, "resources/views/figma/home.blade.php");
    writeFileSync(
      target,
      `${readFileSync(target, "utf8")}\n{{-- manual edit --}}\n`,
    );
    const res = run(["figma", "apply"], { expectFail: true });
    expect(res.code).toBe(1);
    expect(res.out).toContain("conflict");
    // --force は backup を取って上書き
    const forced = run(["figma", "apply", "--force"]);
    expect(forced.out).toContain("backup");
    expect(
      existsSync(
        join(
          project,
          ".agentcms/manifest/backup-figma/resources/views/figma/home.blade.php",
        ),
      ),
    ).toBe(true);
  });

  it("8. figma verify が整合を確認する（HTTP はローカル未起動なら skip）", () => {
    const res = run(["figma", "verify"]);
    expect(res.out).toContain("すべて通過");
    expect(res.out).toContain("Git 管理外");
  });

  it("9. Figma 更新後の apply は stale として拒否される（SPEC §23/§69）", () => {
    for (const f of ["meta.json", "file.json"]) {
      const p = join(fixtureDir, f);
      writeFileSync(
        p,
        readFileSync(p, "utf8").replace(
          '"version": "2001"',
          '"version": "2002"',
        ),
      );
    }
    const res = run(["figma", "apply"], { expectFail: true });
    expect(res.code).toBe(1);
    expect(res.out).toContain("inspect 後に更新");

    // diff は semantic に変更を検出し、自動適用はしない
    const diff = run(["figma", "diff"]);
    expect(diff.out).toContain("2001 → 2002");
    expect(diff.out).toContain("自動では適用しません");

    // 再 inspect 後、plan を作り直さずに apply しても stale plan として拒否
    run(["figma", "inspect"]);
    const res2 = run(["figma", "apply"], { expectFail: true });
    expect(res2.code).toBe(1);
    expect(res2.out).toContain("stale plan");
  });
});
