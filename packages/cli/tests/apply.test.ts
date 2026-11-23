import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterAll, describe, expect, it } from "vitest";

import {
  applyEnvAdditions,
  classifyFiles,
  hashContent,
  writeSchemaChoicesOverride,
} from "../src/importer/apply.js";
import { generateFromPlan } from "../src/importer/generate.js";
import { buildPlan } from "../src/importer/plan.js";
import type { ContentSchema } from "../src/importer/plan-types.js";
import { analyzeTemplateDir } from "../src/inspect/analyze.js";

const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");
const cleanups: string[] = [];

afterAll(() => {
  for (const dir of cleanups) rmSync(dir, { recursive: true, force: true });
});

function tempDir(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-apply-"));
  cleanups.push(dir);
  return dir;
}

function loadSchema(id: string): ContentSchema {
  const schema = JSON.parse(
    readFileSync(resolve(SCHEMAS, `${id}.json`), "utf8"),
  ) as ContentSchema;
  // apply 済みの作業ツリーに依存しないよう、works.category は Phase 4 時点の choices に固定する
  if (id === "works") {
    const category = schema.fields.find((f) => f.key === "category");
    if (category) category.choices = ["web", "graphic", "branding"];
  }
  return schema;
}

const manifest = analyzeTemplateDir(FIXTURE, "fixture");
const plan = buildPlan(manifest, {
  works: loadSchema("works"),
  news: loadSchema("news"),
  contact: loadSchema("contact"),
});
const result = generateFromPlan(FIXTURE, manifest, plan);

describe("generateFromPlan", () => {
  const byTarget = new Map(result.files.map((f) => [f.target, f]));
  const text = (target: string): string =>
    byTarget.get(target)!.content.toString();

  it("is deterministic", () => {
    const again = generateFromPlan(FIXTURE, manifest, plan);
    expect(
      again.files.map((f) => [f.target, hashContent(f.content)]),
    ).toStrictEqual(
      result.files.map((f) => [f.target, hashContent(f.content)]),
    );
  });

  it("copies assets preserving structure and separates media sources", () => {
    expect(byTarget.has("public/imported/css/style.css")).toBe(true);
    expect(byTarget.has("public/imported/images/logo.png")).toBe(true);
    expect(byTarget.has("public/imported/images/work1.png")).toBe(false);
    expect(byTarget.has("database/seeders/template-import/work1.png")).toBe(
      true,
    );
    // CSS は書き換えない（Preserve First）
    expect(text("public/imported/css/style.css")).toBe(
      readFileSync(join(FIXTURE, "css/style.css"), "utf8"),
    );
  });

  it("generates a layout with shared header/footer and rewritten refs", () => {
    const layout = text("resources/views/layouts/imported.blade.php");
    expect(layout).toContain("@yield('content')");
    expect(layout).toContain('header class="header"');
    expect(layout).toContain("{{ route('works.index') }}");
    expect(layout).toContain("{{ asset('imported/css/style.css') }}");
    expect(layout).toContain("fonts.googleapis.com/css2"); // 外部依存は保持
    expect(layout).toContain('href="tel:075-000-0000"'); // tel は変換しない
    expect(layout).toContain('href="mailto:hello@greenleaf.example"');
  });

  it("converts works cards into a Blade loop bound to the works schema", () => {
    const works = text("modules/Works/views/index.blade.php");
    expect(works).toContain("@foreach ($items as $work)");
    expect(works).toContain("{{ $work->title }}");
    expect(works).toContain(
      "{{ $work->category }} / {{ $work->completed_at?->format('Y') }}",
    );
    expect(works).toContain('class="work-card"'); // 元の class を保持
    expect(works).not.toContain("花屋のオンラインストア"); // 静的カードは残さない

    const home = text("resources/views/public/home.blade.php");
    expect(home).toContain("@foreach ($works as $work)");
    expect(home).toContain("@foreach ($news as $item)");
    expect(home).toContain("{{ route('news.show', $item->slug) }}");
    expect(home).toContain("{{ route('service') }}#plans"); // anchor 保持
  });

  it("connects the contact form to the pipeline while preserving its design", () => {
    const form = text("modules/Contact/views/form.blade.php");
    expect(form).toContain("action=\"{{ route('contact.store') }}\"");
    expect(form).toContain("@csrf");
    expect(form).toContain('name="subject"'); // type → subject rename
    expect(form).toContain("@selected(old('subject') === 'estimate')");
    expect(form).toContain("cf-turnstile");
    expect(form).toContain("@error('name')");
    expect(form).toContain('name="privacy"'); // 未対応フィールドも捨てない
    expect(form).toContain("challenges.cloudflare.com/turnstile");
  });

  it("keeps static sections static (service page keeps its items inline)", () => {
    const service = text("resources/views/public/service.blade.php");
    expect(service).toContain("service-item");
    expect(service).toContain("ウェブサイト制作");
    expect(service).toContain('id="plans"');
    expect(service).toContain("@include('components.imported.cta')");
  });

  it("generates an idempotent seeder with fixture-parity data", () => {
    const seeder = text("database/seeders/TemplateContentSeeder.php");
    expect(seeder).toContain("updateOrCreate");
    expect(seeder).toContain("花屋のオンラインストア");
    expect(seeder).toContain("'category' => 'EC'");
    expect(seeder).toContain("imported-news-1");
    expect(seeder).toContain("runningUnitTests");
  });

  it("emits schema patches and CSP env additions from the plan", () => {
    expect(result.schemaPatches).toHaveLength(1);
    expect(result.envAdditions["AGENTCMS_CSP_SCRIPT_SRC"]).toBe(
      "https://cdn.jsdelivr.net",
    );
  });
});

describe("apply safety primitives", () => {
  it("classifies new / unchanged / ours / modified / foreign correctly", () => {
    const project = tempDir();
    const files = [
      { target: "a.txt", content: "A", source: "s" },
      { target: "b.txt", content: "B", source: "s" },
      { target: "c.txt", content: "C", source: "s" },
      { target: "d.txt", content: "D", source: "s" },
    ];
    writeFileSync(join(project, "b.txt"), "B"); // unchanged
    writeFileSync(join(project, "c.txt"), "old-c"); // 前回 apply のまま
    writeFileSync(join(project, "d.txt"), "hand-edited"); // 前回 apply 後に変更
    const record = {
      version: 1 as const,
      manifest_fingerprint: "x",
      plan_sha256: "y",
      applied_at: "2026-01-01T00:00:00Z",
      files: [
        { target: "c.txt", sha256: hashContent("old-c"), source: "s" },
        { target: "d.txt", sha256: hashContent("old-d"), source: "s" },
      ],
    };
    const states = classifyFiles(project, files, record);
    expect(states.map((s) => s.state)).toStrictEqual([
      "new",
      "unchanged",
      "ours",
      "ours-modified",
    ]);

    const foreign = classifyFiles(project, files, null);
    expect(foreign.map((s) => s.state)).toStrictEqual([
      "new",
      "unchanged",
      "foreign",
      "foreign",
    ]);
  });

  it("adds choices to the project override and never touches the base schema", () => {
    const project = tempDir();
    mkdirSync(join(project, "content", "schemas"), { recursive: true });
    const schemaPath = join(project, "content", "schemas", "works.json");
    const baseText = readFileSync(resolve(SCHEMAS, "works.json"), "utf8");
    writeFileSync(schemaPath, baseText);
    const baseHash = hashContent(baseText);

    // 既存の override（body を optional 化）を保持したまま choices を足せること
    mkdirSync(join(project, "content", "schemas", "overrides"), {
      recursive: true,
    });
    const overridePath = join(
      project,
      "content",
      "schemas",
      "overrides",
      "works.json",
    );
    writeFileSync(
      overridePath,
      `${JSON.stringify({ id: "works", fields: { body: { required: false } } }, null, 2)}\n`,
    );

    const works = {
      id: "works",
      fields: [
        {
          key: "category",
          type: "select",
          choices: ["web", "graphic", "branding"],
        },
      ],
    };
    const first = writeSchemaChoicesOverride(project, works, "category", [
      "EC",
      "LP",
    ]);
    expect(first.changed).toBe(true);

    const override = JSON.parse(readFileSync(overridePath, "utf8")) as {
      fields: Record<string, Record<string, unknown>>;
    };
    // base の選択肢を保ったまま末尾へ追加する（削除も並べ替えもしない）
    expect(override.fields.category?.choices).toStrictEqual([
      "web",
      "graphic",
      "branding",
      "EC",
      "LP",
    ]);
    // 既存の override は消えない
    expect(override.fields.body?.required).toBe(false);
    // base は 1 バイトも変わらない
    expect(hashContent(readFileSync(schemaPath, "utf8"))).toBe(baseHash);

    // 同じ値の再適用は no-op（重複しない）
    const second = writeSchemaChoicesOverride(project, works, "category", [
      "EC",
    ]);
    expect(second.changed).toBe(false);

    // 大文字小文字が違うだけの値は **別物**として扱う（勝手にまとめない）
    const third = writeSchemaChoicesOverride(project, works, "category", [
      "ec",
    ]);
    expect(third.changed).toBe(true);
    expect(third.choices).toContain("EC");
    expect(third.choices).toContain("ec");
  });

  it("adds and merges env keys without duplicating origins", () => {
    const project = tempDir();
    writeFileSync(join(project, ".env"), "APP_NAME=test\n");
    const first = applyEnvAdditions(project, {
      AGENTCMS_CSP_SCRIPT_SRC: "https://cdn.example",
    });
    expect(first[0]?.action).toBe("added");
    const second = applyEnvAdditions(project, {
      AGENTCMS_CSP_SCRIPT_SRC: "https://cdn.example,https://two.example",
    });
    expect(second[0]?.action).toBe("merged");
    const env = readFileSync(join(project, ".env"), "utf8");
    expect(env).toContain(
      "AGENTCMS_CSP_SCRIPT_SRC=https://cdn.example,https://two.example",
    );
    expect(env.match(/AGENTCMS_CSP_SCRIPT_SRC/g)).toHaveLength(1);
  });
});
