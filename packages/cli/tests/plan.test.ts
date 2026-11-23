import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { analyzeTemplateDir } from "../src/inspect/analyze.js";
import { renderPlanMarkdown } from "../src/importer/plan-md.js";
import { buildPlan } from "../src/importer/plan.js";
import { cardRows } from "../src/importer/seeds.js";
import type { ContentSchema } from "../src/importer/plan-types.js";

const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

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
const schemas = {
  works: loadSchema("works"),
  news: loadSchema("news"),
  contact: loadSchema("contact"),
};

describe("buildPlan (deterministic template plan)", () => {
  const plan = buildPlan(manifest, schemas);

  it("is deterministic", () => {
    expect(buildPlan(manifest, schemas)).toStrictEqual(plan);
  });

  it("carries the manifest fingerprint for stale detection", () => {
    expect(plan.manifest_fingerprint).toBe(manifest.fingerprint.total);
  });

  it("maps all 5 standard pages as CONFIDENT with routes", () => {
    expect(plan.pages).toHaveLength(5);
    expect(plan.pages.every((p) => p.confidence === "CONFIDENT")).toBe(true);
    const works = plan.pages.find((p) => p.source === "works.html")!;
    expect(works.target).toBe("modules/Works/views/index.blade.php");
    expect(works.route).toBe("works.index");
    const home = plan.pages.find((p) => p.source === "index.html")!;
    expect(home.integrations).toStrictEqual(["news-list", "works-cards"]);
  });

  it("integrates works cards with category/year parsing and reasons", () => {
    const works = plan.cms_integrations.find((i) => i.id === "works-cards")!;
    expect(works.confidence).toBe("CONFIDENT");
    expect(works.regions.map((r) => r.page)).toStrictEqual([
      "index.html",
      "works.html",
    ]);
    expect(works.reason).toContain("6件");
    expect(cardRows(plan)).toHaveLength(6);
    expect(cardRows(plan)[0]).toMatchObject({
      title: "花屋のオンラインストア",
      category: "EC",
      completed_year: 2026,
      image: "images/work1.png",
    });
  });

  it("proposes only missing category choices as a schema change", () => {
    expect(plan.schema_changes).toHaveLength(1);
    const change = plan.schema_changes[0]!;
    expect(change.schema).toBe("works");
    expect(change.change).toBe("add_choices");
    expect(change.values).toContain("EC");
    expect(change.values).not.toContain("web"); // 既存 choice は提案しない
  });

  it("maps the contact form onto the contact schema without dropping fields", () => {
    const contact = plan.cms_integrations.find((i) => i.id === "contact-form")!;
    expect(contact.field_mapping).toMatchObject({
      name: "name",
      email: "email",
      message: "message",
      type: "subject",
    });
    const privacy = contact.unmapped_html_fields.find(
      (f) => f.name === "privacy",
    )!;
    expect(privacy.handling).toContain("保持");
    expect(contact.missing_schema_fields.map((f) => f.key)).toContain(
      "company",
    );
  });

  it("keeps unrecognized repeats static as REVIEW", () => {
    expect(plan.keep_static.map((k) => k.region)).toStrictEqual([
      "div.section-inner > DETAILS.faq-item",
      "div.service-items > DIV.service-item",
    ]);
    expect(plan.keep_static.every((k) => k.confidence === "REVIEW")).toBe(true);
  });

  it("keeps all JavaScript (local + external) without Vue conversion", () => {
    expect(plan.javascript).toHaveLength(2);
    expect(plan.javascript.every((j) => j.action.startsWith("keep"))).toBe(
      true,
    );
  });

  it("separates static assets from media candidates", () => {
    const staticFrom = plan.assets.static.map((s) => s.from);
    expect(staticFrom).toContain("images/logo.png");
    expect(staticFrom).toContain("images/hero-bg.png");
    expect(staticFrom).not.toContain("images/work1.png");
    expect(plan.assets.media).toHaveLength(6);
    expect(plan.assets.media[0]!.name).toBe("花屋のオンラインストア");
  });

  it("derives CSP env additions from external deps", () => {
    expect(plan.env_additions).toStrictEqual({
      AGENTCMS_CSP_SCRIPT_SRC: "https://cdn.jsdelivr.net",
      AGENTCMS_CSP_STYLE_SRC: "https://fonts.googleapis.com",
      AGENTCMS_CSP_FONT_SRC: "https://fonts.gstatic.com",
    });
  });

  it("maps internal links to routes and preserves anchors", () => {
    const anchor = plan.link_map.find((l) => l.href === "service.html#plans")!;
    expect(anchor.kind).toBe("anchor");
    expect(anchor.route).toBe("service");
    expect(anchor.fragment).toBe("plans");
    const about = plan.link_map.find((l) => l.href === "about.html")!;
    expect(about.route).toBe("about");
  });

  it("expresses uncertainty instead of deciding silently", () => {
    expect(plan.uncertainties.length).toBeGreaterThanOrEqual(3);
    expect(plan.uncertainties.every((u) => u.level !== "CONFIDENT")).toBe(true);
  });

  it("renders human-readable markdown containing reasons", () => {
    const md = renderPlanMarkdown(plan);
    expect(md).toContain("# Template Import Plan");
    expect(md).toContain("works-cards");
    expect(md).toContain("Uncertainties");
    expect(md).toContain("Preserve First");
  });
});
