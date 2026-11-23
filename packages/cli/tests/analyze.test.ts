import { cpSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { afterAll, describe, expect, it } from "vitest";

import { analyzeTemplateDir } from "../src/inspect/analyze.js";

const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const cleanups: string[] = [];

afterAll(() => {
  for (const dir of cleanups) rmSync(dir, { recursive: true, force: true });
});

function tempFixtureCopy(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-inspect-"));
  cpSync(FIXTURE, dir, { recursive: true });
  cleanups.push(dir);
  return dir;
}

describe("analyzeTemplateDir (deterministic template inspect v2)", () => {
  const m = analyzeTemplateDir(FIXTURE, "fixture");

  it("is deterministic — two runs produce identical manifests", () => {
    expect(analyzeTemplateDir(FIXTURE, "fixture")).toStrictEqual(m);
  });

  it("finds all pages with titles and head metadata", () => {
    expect(m.pages.map((p) => p.path)).toStrictEqual([
      "about.html",
      "contact.html",
      "index.html",
      "service.html",
      "works.html",
    ]);
    expect(m.pages.every((p) => p.title !== null)).toBe(true);
    expect(m.pages.every((p) => p.head.description !== null)).toBe(true);
    expect(m.pages.every((p) => p.head.lang === "ja")).toBe(true);

    const home = m.pages.find((p) => p.path === "index.html")!;
    expect(home.head.canonical).toBe("https://greenleaf.example/");
    expect(home.head.og["og:site_name"]).toBe("Green Leaf Design");
  });

  it("classifies assets by type", () => {
    expect(m.assets.css).toStrictEqual(["css/style.css"]);
    expect(m.assets.js).toStrictEqual(["js/main.js"]);
    expect(m.assets.images).toHaveLength(8);
    expect(m.assets.fonts).toStrictEqual(["fonts/leaf-icons.woff2"]);
  });

  it("has a stable fingerprint that changes when a file changes", () => {
    const copy = tempFixtureCopy();
    const before = analyzeTemplateDir(copy, "fixture");
    expect(before.fingerprint.total).toBe(m.fingerprint.total);
    writeFileSync(join(copy, "index.html"), "<html><title>x</title></html>");
    const after = analyzeTemplateDir(copy, "fixture");
    expect(after.fingerprint.total).not.toBe(m.fingerprint.total);
  });

  it("detects external dependencies with category", () => {
    const gsap = m.external.find((e) => e.url.includes("gsap"));
    expect(gsap?.category).toBe("cdn-script");
    expect(gsap?.library).toBe("gsap");
    expect(gsap?.pages).toStrictEqual(["index.html"]);

    const font = m.external.find((e) =>
      e.url.includes("fonts.googleapis.com/css2"),
    );
    expect(font?.category).toBe("google-fonts");
    expect(font?.pages).toHaveLength(5);
  });

  it("detects shared header/footer/nav/cta as partial candidates", () => {
    const kinds = m.shared_candidates.map((s) => s.kind).sort();
    expect(kinds).toStrictEqual(["footer", "header", "nav", "section"]);
    const header = m.shared_candidates.find((s) => s.kind === "header")!;
    expect(header.pages).toHaveLength(5);
    const cta = m.shared_candidates.find((s) => s.kind === "section")!;
    expect(cta.selector).toBe("section.cta");
    expect(cta.pages).toStrictEqual(["service.html", "works.html"]);
  });

  it("derives common structures with confidence and reason", () => {
    const kinds = m.common_structures.map((c) => c.kind);
    for (const expected of [
      "header",
      "footer",
      "navigation",
      "breadcrumb",
      "cta",
      "card_grid",
      "form",
      "section_heading",
    ]) {
      expect(kinds).toContain(expected);
    }
    expect(m.common_structures.every((c) => c.reason.length > 0)).toBe(true);
  });

  it("extracts forms with labeled fields and select options", () => {
    expect(m.forms).toHaveLength(1);
    const form = m.forms[0]!;
    expect(form.page).toBe("contact.html");
    expect(form.method).toBe("post");
    expect(form.external_action).toBe(true);
    expect(form.fields.map((f) => f.name)).toStrictEqual([
      "email",
      "message",
      "name",
      "privacy",
      "type",
    ]);
    const type = form.fields.find((f) => f.name === "type")!;
    expect(type.options).toStrictEqual(["estimate", "other"]);
    expect(form.fields.find((f) => f.name === "name")?.label).toBe("お名前");
  });

  it("detects repeated structures with extracted item content", () => {
    const workCards = m.repeats.filter((r) => r.item === "ARTICLE.work-card");
    expect(
      workCards.map((r) => ({ page: r.page, count: r.count })),
    ).toStrictEqual([
      { page: "index.html", count: 4 },
      { page: "works.html", count: 6 },
    ]);
    const first = workCards[1]!.items[0]!;
    expect(first.heading).toBe("花屋のオンラインストア");
    expect(first.image).toBe("images/work1.png");
    expect(first.meta).toBe("EC / 2026");

    const news = m.repeats.find((r) => r.item === "LI.news-item")!;
    expect(news.items[0]!.time).toBe("2026-07-01");
    expect(news.items[0]!.link_text).toBe("夏季休業のお知らせ");
  });

  it("analyzes CSS: custom properties, media queries, font-face, url refs", () => {
    const css = m.css_analysis.files[0]!;
    expect(css.custom_properties).toContain("--green");
    expect(css.media_queries.some((q) => q.includes("max-width"))).toBe(true);
    expect(css.font_faces[0]?.family).toBe("LeafIcons");
    const bg = css.url_refs.find((r) => r.ref.includes("hero-bg"))!;
    expect(bg.resolved).toBe("images/hero-bg.png");
    expect(bg.exists).toBe(true);
  });

  it("analyzes JS: selectors, listeners, libraries", () => {
    const js = m.js_analysis.files[0]!;
    expect(js.dom_selectors).toContain(".nav-toggle");
    expect(js.dom_selectors).toContain("#global-nav");
    expect(js.event_listeners).toStrictEqual([
      "DOMContentLoaded",
      "click",
      "scroll",
    ]);
    expect(js.libraries).toContain("gsap");
    expect(js.uses_modules).toBe(false);
  });

  it("maps interactive regions to the pages they exist on", () => {
    const navToggle = m.interactive_regions.find(
      (r) => r.selector === ".nav-toggle",
    )!;
    expect(navToggle.pages).toHaveLength(5);
    const hero = m.interactive_regions.find((r) => r.selector === ".hero h1")!;
    expect(hero.pages).toStrictEqual(["index.html"]);
  });

  it("classifies images: repeat items are media candidates, logo/bg are static", () => {
    const media = m.image_classification.media_candidates.map((c) => c.path);
    expect(media).toStrictEqual([
      "images/work1.png",
      "images/work2.png",
      "images/work3.png",
      "images/work4.png",
      "images/work5.png",
      "images/work6.png",
    ]);
    const statics = m.image_classification.static_assets.map((c) => c.path);
    expect(statics).toStrictEqual(["images/hero-bg.png", "images/logo.png"]);
    expect(
      m.image_classification.static_assets.every(
        (c) => c.confidence === "high",
      ),
    ).toBe(true);
  });

  it("warns about the external form action but nothing else on the clean fixture", () => {
    expect(m.warnings.map((w) => w.kind)).toStrictEqual([
      "external-form-action",
    ]);
  });

  it("redacts secrets and flags SPA markers in a poisoned copy", () => {
    const copy = tempFixtureCopy();
    writeFileSync(
      join(copy, "js", "config.js"),
      'var API_KEY = "sk_live_abcdef1234567890";\n',
    );
    writeFileSync(
      join(copy, "app.html"),
      '<html lang="ja"><head><title>app</title></head><body><div id="app"></div></body></html>',
    );
    const poisoned = analyzeTemplateDir(copy, "fixture");
    const secret = poisoned.warnings.find((w) => w.kind === "secret")!;
    expect(secret.file).toBe("js/config.js");
    expect(secret.message).not.toContain("abcdef1234567890");
    const unsupported = poisoned.warnings.find(
      (w) => w.kind === "unsupported",
    )!;
    expect(unsupported.message).toContain("mount point");
  });

  it("records source stats for token-efficiency measurement", () => {
    expect(m.source_stats.files).toBe(16);
    expect(m.source_stats.text_lines).toBeGreaterThan(500);
    expect(m.source_stats.by_type["html"]?.files).toBe(5);
  });

  it("matches the manifest snapshot (regression guard)", () => {
    expect(m).toMatchSnapshot();
  });
});
