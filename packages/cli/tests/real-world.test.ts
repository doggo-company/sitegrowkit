import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterAll, describe, expect, it } from "vitest";

import { analyzeTemplateDir } from "../src/inspect/analyze.js";
import {
  hashContent,
  POST_WRITE_WIRING,
  pruneStaleFiles,
} from "../src/importer/apply.js";
import type { ApplyRecord } from "../src/importer/apply.js";
import { generateFromPlan } from "../src/importer/generate.js";
import { renderPlanMarkdown } from "../src/importer/plan-md.js";
import { distributesContentType } from "../src/distribution.js";
import type { TemplatePlan } from "../src/importer/plan-types.js";
import { buildPlan } from "../src/importer/plan.js";
import { validatePlanChanges } from "../src/importer/plan-contract.js";
import {
  declaredRouteNames,
  emittedModelClasses,
  emittedRouteNames,
  unresolvedModels,
  unresolvedRoutes,
} from "./support/output-guard.js";
import { cardRows, datedRows } from "../src/importer/seeds.js";
import { generateSeeder } from "../src/importer/seeder.js";
import type { ContentSchema } from "../src/importer/plan-types.js";

/**
 * Phase 5.1 Real World Acceptance Test の一般化検証。
 * Real World Acceptance で発見された構造を合成 fixture として再現する:
 * - 0 byte index.html / entry 明示指定 / 単一ファイル inspect
 * - 作業ファイル混在（psd/docx/py/bak）と Reference-Based Asset Allowlist
 * - 装飾繰り返し（sticker）と Content Media の分離
 * - method=get のモック contact フォーム + server-side constant
 * - time 要素の無い「日付 + テキスト」News
 * - 詳細ページリンク付き Works カード（category 概念なし）
 */

const cleanups: string[] = [];
afterAll(() => {
  for (const dir of cleanups) rmSync(dir, { recursive: true, force: true });
});

function makeFixture(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-realworld-"));
  cleanups.push(dir);
  mkdirSync(join(dir, "images"), { recursive: true });
  mkdirSync(join(dir, "css"), { recursive: true });
  mkdirSync(join(dir, "docs"), { recursive: true });
  mkdirSync(join(dir, "psd"), { recursive: true });

  /*
   * ナビは**全ページが持つ**。standalone（preserve）モードは layout へ分解しないので、
   * 原本のナビがページの数だけ複製される（実案件で 12 ファイル・実測）。
   */
  /*
   * フッターの導線は `<ul>` を使わず、`<a>` を ` / ` で区切って `<br>` で折り返す。
   * **実案件にこの形がある**ので、見本もその形にしてある（B-58）。
   */
  const FOOTER =
    '<footer><h4>サイトマップ</h4><a href="main.html">ホーム</a> / <a href="contact.html">お問い合わせ</a><br><a href="news.html">お知らせ</a></footer>';

  const NAV =
    '<nav id="navbar"><img src="images/logo.png" alt="logo"><ul class="nav-links"><li><a href="main.html">ホーム</a></li><li><a href="contact.html">お問い合わせ</a></li></ul><button class="hamburger" onclick="toggleNav()">≡</button></nav>';

  const page = (title: string, body: string): string =>
    `<!doctype html><html lang="ja"><head><title>${title}</title><link rel="stylesheet" href="css/site.css"><style>body { background: url("images/bg.png"); }</style></head><body>${NAV}${body}${FOOTER}<script>function toggleNav() { document.body.classList.toggle("open"); }</script></body></html>`;

  // 実質空の index.html（実案件でトップページではないのに存在していた）
  writeFileSync(join(dir, "index.html"), "");

  writeFileSync(
    join(dir, "main.html"),
    page(
      "リアルワールド社",
      `
<section id="news">
  <div class="news-list">
    <a class="news-item" href="#">2026.04.01 春のキャンペーンを開始しました。</a>
    <a class="news-item" href="#">2026.03.15 スタッフが加わりました。</a>
    <a class="news-item" href="#">2026.2.20 サイトを公開しました。</a>
  </div>
</section>
<section id="works">
  <div id="worksTrack">
    <article class="works-slide"><img src="images/work-a.png"><h3>事例A</h3><p class="meta">説明A</p><a href="works-01.html">詳しく見る</a></article>
    <article class="works-slide"><img src="images/work-b.png"><h3>事例B</h3><p class="meta">説明B</p><a href="works-02.html">詳しく見る</a></article>
    <article class="works-slide"><img src="images/work-a.png"><h3>事例C</h3><p class="meta">説明C</p><a href="works-03.html">詳しく見る</a></article>
  </div>
</section>
<div id="characterScroll">
  <div class="sticker-item"><img src="images/sticker-1.png"></div>
  <div class="sticker-item"><img src="images/sticker-2.png"></div>
  <div class="sticker-item"><img src="images/sticker-3.png"></div>
</div>
<a href="docs/guide.pdf">パンフレット</a>
`,
    ),
  );

  writeFileSync(
    join(dir, "news.html"),
    page(
      "お知らせ",
      `
<main class="sub-page">
  <section class="card-box"><h2>2026.04.01</h2><p>春のキャンペーンを開始しました。詳細はお問い合わせください。（ダミーテキスト）</p></section>
  <section class="card-box"><h2>2026.03.15</h2><p>スタッフが加わりました。仲間を募集中です。（ダミーテキスト）</p></section>
  <section class="card-box"><h2>2026.02.20</h2><p>サイトを公開しました。今後ともよろしくお願いいたします。（ダミーテキスト）</p></section>
</main>
`,
    ),
  );

  writeFileSync(
    join(dir, "contact.html"),
    page(
      "お問い合わせ",
      `
<form method="get">
  <label for="name">お名前</label><input type="text" id="name" name="name" required>
  <label for="email">メールアドレス</label><input type="email" id="email" name="email" required>
  <label for="message">ご相談内容</label><textarea id="message" name="message"></textarea>
  <button type="submit">送信</button>
</form>
`,
    ),
  );

  const details: [string, string][] = [
    ["01", "事例A"],
    ["02", "事例B"],
    ["03", "事例C"],
  ];
  for (const [n, t] of details) {
    writeFileSync(
      join(dir, `works-${n}.html`),
      page(`施工${n} ${t}`, `<main><h1>${t}</h1><p>詳細本文</p></main>`),
    );
  }

  writeFileSync(
    join(dir, "company.html"),
    page(
      "会社概要",
      `
<main class="sub-page">
  <section class="company-info">
    <div class="info-row">会社名 株式会社 リアルワールド</div>
    <div class="info-row">所在地 架空県サンプル市1-2-3</div>
    <div class="info-row">TEL / FAX 準備中</div>
  </section>
</main>
`,
    ),
  );

  writeFileSync(
    join(dir, "css", "site.css"),
    "body { background: url(../images/bg.png); }",
  );

  // 参照される Web asset
  for (const img of [
    "logo.png",
    "work-a.png",
    "work-b.png",
    "sticker-1.png",
    "sticker-2.png",
    "sticker-3.png",
    "bg.png",
  ]) {
    writeFileSync(join(dir, "images", img), "png");
  }
  writeFileSync(join(dir, "docs", "guide.pdf"), "pdf");

  // 作業ディレクトリに混在する非公開ファイル（実案件で public copy されかけたもの）
  writeFileSync(join(dir, "psd", "design.psd"), "psd");
  writeFileSync(join(dir, "議事録.docx"), "docx");
  writeFileSync(join(dir, "convert.py"), "print('x')");
  writeFileSync(join(dir, "main.html.bak"), "<html>old</html>");
  writeFileSync(join(dir, "images", "unused.png"), "png");

  return dir;
}

const SCHEMAS: Record<string, ContentSchema> = {
  works: {
    id: "works",
    fields: [
      { key: "title", type: "text", required: true },
      { key: "category", type: "select", required: true, choices: ["web"] },
      { key: "main_image_id", type: "media" },
      { key: "excerpt", type: "textarea" },
    ],
  },
  news: {
    id: "news",
    fields: [
      { key: "title", type: "text", required: true },
      { key: "body", type: "richtext", required: true },
      { key: "published_at", type: "datetime" },
    ],
  },
  contact: {
    id: "contact",
    fields: [
      { key: "name", type: "text", required: true },
      { key: "email", type: "email", required: true },
      { key: "company", type: "text" },
      {
        key: "subject",
        type: "select",
        required: true,
        choices: ["estimate", "other"],
      },
      { key: "message", type: "textarea", required: true },
    ],
  },
};

describe("real-world inspect (Phase 5.1 generalizations)", () => {
  const dir = makeFixture();
  const manifest = analyzeTemplateDir(dir, "realworld", { entry: "main.html" });

  it("excludes effectively-empty html from page candidates with a warning", () => {
    expect(manifest.pages.map((p) => p.path)).not.toContain("index.html");
    const w = manifest.warnings.find((x) => x.kind === "empty-page");
    expect(w?.file).toBe("index.html");
  });

  it("resolves --entry and never falls back to the empty index.html", () => {
    expect(manifest.entry).toBe("main.html");
    const auto = analyzeTemplateDir(dir, "realworld");
    expect(auto.entry).toBeNull(); // index.html は空なので自動 entry にならない
  });

  it("rejects an entry that is not a detected page", () => {
    expect(() =>
      analyzeTemplateDir(dir, "realworld", { entry: "nope.html" }),
    ).toThrow(/entry/);
  });

  it("tracks referenced assets (html attrs + css url() + non-page links)", () => {
    expect(manifest.referenced_assets).toContain("css/site.css");
    expect(manifest.referenced_assets).toContain("images/bg.png");
    expect(manifest.referenced_assets).toContain("docs/guide.pdf");
    expect(manifest.referenced_assets).not.toContain("images/unused.png");
    expect(manifest.referenced_assets).not.toContain("psd/design.psd");
  });

  it("classifies decorative repeats as static and content repeats as media", () => {
    const media = manifest.image_classification.media_candidates.map(
      (m) => m.path,
    );
    expect(media).toContain("images/work-a.png");
    expect(media).toContain("images/work-b.png");
    expect(media).not.toContain("images/sticker-1.png");
    const sticker = manifest.image_classification.static_assets.find(
      (s) => s.path === "images/sticker-1.png",
    );
    expect(sticker?.repeat).toBe("DIV.sticker-item");
  });

  it("single-file inspect scopes source to the page and its referenced assets", () => {
    const single = analyzeTemplateDir(dir, "realworld", {
      onlyPage: "main.html",
    });
    expect(single.entry).toBe("main.html");
    expect(single.pages.map((p) => p.path)).toStrictEqual(["main.html"]);
    const files = single.fingerprint.files.map((f) => f.path);
    expect(files).toContain("main.html");
    expect(files).toContain("images/work-a.png");
    expect(files).not.toContain("psd/design.psd");
    expect(files).not.toContain("議事録.docx");
    expect(files).not.toContain("images/unused.png");
  });
});

describe("real-world plan (Phase 5.1 generalizations)", () => {
  const dir = makeFixture();
  const manifest = analyzeTemplateDir(dir, "realworld", { entry: "main.html" });
  const plan = buildPlan(manifest, SCHEMAS);

  it("maps the entry page to home as CONFIDENT", () => {
    expect(plan.entry).toBe("main.html");
    const home = plan.pages.find((p) => p.source === "main.html")!;
    expect(home.route).toBe("home");
    expect(home.url).toBe("/");
    expect(home.confidence).toBe("CONFIDENT");
    expect(home.reason).toContain("entry");
    expect(plan.pages.some((p) => p.source === "index.html")).toBe(false);
  });

  it("copies only referenced web-safe assets (reference-based allowlist)", () => {
    const staticFrom = plan.assets.static.map((s) => s.from);
    expect(staticFrom).toContain("css/site.css");
    expect(staticFrom).toContain("images/bg.png");
    expect(staticFrom).toContain("images/logo.png");
    expect(staticFrom).toContain("images/sticker-1.png");
    for (const banned of [
      "psd/design.psd",
      "議事録.docx",
      "convert.py",
      "main.html.bak",
      "images/unused.png",
      "docs/guide.pdf",
    ]) {
      expect(staticFrom).not.toContain(banned);
    }
  });

  it("records why each asset was excluded", () => {
    const byPath = new Map(plan.assets.excluded.map((e) => [e.path, e.kind]));
    expect(byPath.get("psd/design.psd")).toBe("blocked-extension");
    expect(byPath.get("議事録.docx")).toBe("blocked-extension");
    expect(byPath.get("convert.py")).toBe("blocked-extension");
    expect(byPath.get("main.html.bak")).toBe("blocked-extension");
    expect(byPath.get("images/unused.png")).toBe("unreferenced");
    expect(byPath.get("docs/guide.pdf")).toBe("needs-approval");
    // 参照されている pdf は人間確認へ
    expect(
      plan.uncertainties.some((u) => u.subject === "asset docs/guide.pdf"),
    ).toBe(true);
  });

  it("registers only content media of integrated repeats in the media library", () => {
    const media = plan.assets.media.map((m) => m.from);
    expect(media).toContain("images/work-a.png");
    expect(media).toContain("images/work-b.png");
    expect(media).not.toContain("images/sticker-1.png");
  });

  it("proposes a REVIEW works integration for link-style cards without category", () => {
    const works = plan.cms_integrations.find((i) => i.id === "works-cards")!;
    expect(works.confidence).toBe("REVIEW");
    expect(works.schema).toBe("works");
    expect(cardRows(plan)).toHaveLength(3);
    expect(cardRows(plan)[0]).toMatchObject({
      title: "事例A",
      category: "",
      completed_year: null,
    });
    const override = plan.schema_changes.find(
      (c) => c.schema === "works" && c.change === "make_optional",
    )!;
    expect(override.scope).toBe("project");
    // project scope override（content/schemas/overrides/）として apply が適用する。
    // Core schema 本体は変更しない
    expect(override.confidence).toBe("CONFIDENT");
  });

  it("proposes a REVIEW news integration for date-prefixed items and dedupes across pages", () => {
    const news = plan.cms_integrations.find((i) => i.id === "news-list")!;
    expect(news.confidence).toBe("REVIEW");
    expect(news.regions.map((r) => r.page)).toStrictEqual([
      "main.html",
      "news.html",
    ]);
    // 一覧 3 件とサブページ 3 件（延長テキスト）は同一記事 → 3 件へ dedupe。
    // 日付はゼロ埋め正規化、title は最初の出現（一覧側）を採用
    expect(datedRows(plan)).toHaveLength(3);
    expect(datedRows(plan).map((n) => n.date)).toStrictEqual([
      "2026-04-01",
      "2026-03-15",
      "2026-02-20",
    ]);
    expect(datedRows(plan)[0]!.title).toBe("春のキャンペーンを開始しました。");
    expect(news.missing_schema_fields.some((f) => f.key === "body")).toBe(true);
  });

  it("connects the mock GET form to contact with server-side constants (no UI fields added)", () => {
    const contact = plan.cms_integrations.find((i) => i.id === "contact-form")!;
    expect(contact.confidence).toBe("REVIEW");
    expect(contact.field_mapping).toMatchObject({
      name: "name",
      email: "email",
      message: "message",
    });
    const subject = contact.server_constants.find((c) => c.key === "subject")!;
    expect(subject.value).toBe("Webサイトからのお問い合わせ");
    // 任意の company は未入力のまま（UI へ追加しない）
    const company = contact.missing_schema_fields.find(
      (f) => f.key === "company",
    )!;
    expect(company.note).toContain("追加しない");
    // schema は変更しない（server constant のみ — 承認済み判断）
    expect(plan.schema_changes.some((c) => c.schema === "contact")).toBe(false);
  });

  it("routes detail pages to works.show, news to news.index, leftovers to generic routes", () => {
    const w1 = plan.pages.find((p) => p.source === "works-01.html")!;
    expect(w1.route).toBe("works.show");
    expect(w1.target).toBe("modules/Works/views/show.blade.php");
    const news = plan.pages.find((p) => p.source === "news.html")!;
    expect(news.route).toBe("news.index");
    const company = plan.pages.find((p) => p.source === "company.html")!;
    expect(company.route).toBe("company");
    expect(company.url).toBe("/company");
    expect(cardRows(plan)[0]).toMatchObject({
      excerpt: "説明A",
      source_page: "works-01.html",
    });
  });

  it("extracts site settings from company info rows without fabricating unknown values", () => {
    expect(plan.seeds.site).toStrictEqual({
      site_name: "株式会社 リアルワールド",
      address: "架空県サンプル市1-2-3",
      // TEL は「準備中」のため投入しない / 代表者相当のラベルは field が無いため投入しない
    });
  });

  it("gives every non-integrated repeat an explicit keep-static disposition", () => {
    const regions = plan.keep_static.map((k) => k.region);
    expect(regions).toContain("div#characterScroll > DIV.sticker-item");
    // 統合済み繰り返しは keep_static に現れない
    expect(regions).not.toContain("div#worksTrack > ARTICLE.works-slide");
    expect(regions).not.toContain("div.news-list > A.news-item");
  });

  it("generates a null-safe seeder when works have no year", () => {
    const seeder = generateSeeder(plan);
    expect(seeder).toContain("'year' => null");
    // 全 works が year 無し → completed_at を捏造しない（PHPStan 常真比較も出さない）
    expect(seeder).toContain("$completedAt = null");
    expect(seeder).not.toContain("CarbonImmutable::create($row['year']");
    expect(seeder).not.toContain("'year' => undefined");
  });

  it("does not fabricate news bodies or works prose in the seeder", () => {
    const seeder = generateSeeder(plan);
    expect(seeder).toContain("'body' => ''"); // news body は空
    expect(seeder).not.toContain("の制作実績です"); // works 本文を捏造しない
    expect(seeder).toContain("e($row['excerpt'])");
  });
});

/**
 * **利用者のプロジェクト（受け皿の型が無い）で、preserve モードの生成物が壊れないこと**
 * （R-13 8 件目）。
 *
 * 実案件の再走で `Route [works.show] not defined (View: public/home.blade.php)` が出た。
 * Stage 2 で「CMS が持つ型は名前付き route を作らない」と決めたのに、
 * **preserve モードのカード → 詳細リンクだけが module 規約のまま**だった。
 *
 * kit の fixture は「型が在る世界」しか通らないので、この枝を一度も踏んでいなかった
 * （同じ形の 5 例目）。**利用者の形で、出口の門を通す。**
 */
describe("受け皿の型が無い preserve モードでも、無い route を呼ばない", () => {
  const dir = makeFixture();
  const manifest = analyzeTemplateDir(dir, "realworld", { entry: "main.html" });
  // 配布境界の向こう側（生成プロジェクトは works / news を持たない）
  const plan = buildPlan(manifest, {});
  const result = generateFromPlan(dir, manifest, plan);
  const all = result.files
    .map((f) => `${f.target}\n${String(f.content)}`)
    .join("\n");

  const declared = declaredRouteNames(
    String(
      result.files.find((f) => f.target === "routes/imported.php")?.content ??
        "",
    ),
  );

  it("そもそも型を作る計画になっている（この試験の前提）", () => {
    expect(plan.content_types.map((t) => t.id)).toContain("works");
  });

  /**
   * **案内も世界で変える**（R-13 9 件目）。
   * 「Works module の show route」は module が在る世界の話で、
   * CMS が持つ型の世界には module も名前付き route も無い。
   */
  it("module が無い世界で、module 前提の案内を出さない", () => {
    const notes = plan.uncertainties.map((u) => u.note).join("\n");

    expect(notes).not.toContain("Works module の show route");
    expect(notes).toContain("投稿タイプ [works] の詳細（/works/{slug}）");
  });

  it("針が鳴ることを、同じ導出で確かめる", () => {
    expect(emittedRouteNames(all).length).toBeGreaterThan(0);
    expect(unresolvedRoutes(all, [], new Set()).length).toBeGreaterThan(0);
  });

  /**
   * **route の針だけでは足りない**（R-13 12 件目）。
   *
   * 詳細ページの生成は `route()` を 1 つも書かず、
   * `\Modules\Works\Models\Work::published()` と**クラスを直接呼ぶ**。
   * route を見ているだけでは通り抜けて、公開面が 500 になった。
   */
  it("呼んだモデルのクラスが、plan の宣言と一致する", () => {
    /*
     * 呼んでよいのは 2 種類だけ:
     * ① plan が投入先として宣言したクラス
     * ② **配られる** kit の module のクラス（画像は Media に載る）
     */
    expect(distributesContentType("media"), "media は配られる前提").toBe(true);

    const known = [
      ...plan.seeds.collections.map((c) => c.model),
      "Modules\\Media\\Models\\Media",
    ];
    const unresolved = unresolvedModels(all, known);

    expect(
      unresolved,
      `plan が宣言していないクラスを呼んでいます: ${unresolved.join(", ")}`,
    ).toStrictEqual([]);
  });

  it("モデルの針が鳴る（見張っている証拠）", () => {
    expect(emittedModelClasses(all).length).toBeGreaterThan(0);
    expect(unresolvedModels(all, []).length).toBeGreaterThan(0);
  });

  it("呼んだ route が 1 つ残らず解決できる", () => {
    const unresolved = unresolvedRoutes(
      all,
      ["home", "about", "service", "contact.show", "contact.store"],
      declared,
    );

    expect(
      unresolved,
      `生成物が、どこにも無い route を呼んでいます: ${unresolved.join(", ")}`,
    ).toStrictEqual([]);
  });

  /**
   * **route が無いページを、丸ごと飛ばさない**。
   *
   * preserve モードは `route === null` を「行き先が決まっていない」と読んで
   * 生成を打ち切っていた。CMS が持つ型のページは route を持たない（fallback が受ける）ので、
   * そのままだと**詳細ページも一覧ページも 1 枚も出なくなる**。
   */
  /**
   * **生成されただけでは足りない — 中身が CMS 駆動であること**（監査 指摘 43）。
   *
   * 詳細ページの判定を `route === "works.show"` に戻す変異は、
   * **同じ target を作り続ける**（別の枝が静的な写しとして書く）ので、
   * 「ファイルが在る」だけを見ていると通り抜ける。
   * 実際に変わるのは中身で、**1 件目の seed がベタ書きされた静的ページ**になる。
   */
  it("詳細ページの中身が CMS 駆動である（静的な写しではない）", () => {
    const show = String(
      result.files.find(
        (f) => f.target === "resources/views/public/works/show.blade.php",
      )?.content ?? "",
    );

    // 先頭で対象を引いてくる = 動的
    // 先頭は生成の由来コメント。その直後で対象を引いてくる = 動的
    // 🔴 **短い形は使わない**（ブロック形と混ざると丸ごと食われる）
    expect(show).toContain("@php\n$__works =");
    expect(show).toContain("::published()");

    // 1 件目の seed がベタ書きされていない（静的な写しになっていない）
    const first = cardRows(plan)[0]!;

    expect(show, `seed「${first.title}」がベタ書きされている`).not.toContain(
      first.title,
    );
  });

  /**
   * **`detail_route` が無い記録でも `route("undefined")` を書かない**（監査 指摘 44）。
   *
   * `detail_route` は Stage 2 で足した項目なので、**それ以前の記録には無い**
   * （kit に commit 済みの記録の 3 つの integration すべてに無い）。
   * 生成側が `undefined` を素通しすると `route("undefined", …)` を書き出し、
   * 公開ページが 500 になる（**8 件目が実案件で踏んだのと同じ壊れ方**）。
   *
   * commit 済みの記録は layout モードで、しかも詳細ページを持たないので
   * **preserve 側の枝を踏めない**。ここでは同じ形（項目が無い記録）を、
   * 詳細ページを持つこの fixture の plan から作って通す。
   */
  it('detail_route が無い記録でも route("undefined") を書かない', () => {
    const old: TemplatePlan = {
      ...plan,
      cms_integrations: plan.cms_integrations.map((i) => {
        const copy = { ...i };

        delete copy.detail_route;

        return copy;
      }),
    };

    const all = generateFromPlan(dir, manifest, old)
      .files.map((f) => `${f.target}\n${String(f.content)}`)
      .join("\n");

    expect(all).not.toContain("undefined");
  });

  it("名前付き route を持たないページも生成する", () => {
    const targets = result.files.map((f) => f.target);

    expect(targets).toContain("resources/views/public/works/show.blade.php");
    expect(targets).toContain("resources/views/public/news/index.blade.php");
  });
});

/**
 * **2 度目以降の plan でも、使う型を書き続ける**（R-13 10 件目の残余）。
 *
 * `content_types` を「作る型」の意味にしていたので、型が在る回（= 2 度目の apply）は
 * plan から消え、**公開の後付けが二度と走らなかった**（型は在るのに `/works` が 404）。
 * 冪等の意味は「作った回だけ効く」ではなく「**目標の状態へ揃える**」である。
 */
describe("型を作ったあとの再 plan でも、使う型を書き続ける", () => {
  const dir = makeFixture();
  const manifest = analyzeTemplateDir(dir, "realworld", { entry: "main.html" });

  /** CMS が作った works（`managedBy: "cms"`）が既に在る世界 */
  const cmsWorks: ContentSchema = {
    id: "works",
    managedBy: "cms",
    fields: [
      { key: "title", type: "text", required: true },
      { key: "slug", type: "slug", required: true },
      { key: "category", type: "select", choices: ["web"] },
      { key: "main_image_id", type: "media" },
      { key: "excerpt", type: "textarea" },
    ],
  };

  const plan = buildPlan(manifest, { works: cmsWorks });

  it("在る型も content_types に出る（作るとは言わない）", () => {
    const works = plan.content_types.find((t) => t.id === "works");

    expect(works, "型が在ると plan から消えてしまう").toBeDefined();
    expect(works?.reason).toContain("既に在るので作成はせず");
    expect(works?.reason).toContain("変更できません");
  });

  /**
   * **承認する人が読む所に出す**（R-13 Stage 2 / 裁定 (i)）。
   *
   * 投稿タイプの新設はテーブルを作る不可逆な操作で、ID はあとから変えられない。
   * plan の承認がその門なので、**人が読む Markdown に出ていなければ
   * 「plan に明記した」ことにならない**（JSON にだけ在っても届かない）。
   */
  it("人が読む計画にも出る", () => {
    const md = renderPlanMarkdown(buildPlan(manifest, {}));

    expect(md).toContain("## 投稿タイプ");
    expect(md).toContain("ID はあとから変更できません");
    expect(md).toContain("`works`");
  });

  it("在る型を書いても apply の contract を通る", () => {
    expect(
      validatePlanChanges(plan, { schemas: { works: cmsWorks } }),
    ).toStrictEqual([]);
  });

  /** kit の module 型（`managedBy` 無し）は従来どおり — 書かない */
  it("kit の module 型は content_types に出さない", () => {
    const moduleWorks: ContentSchema = { ...cmsWorks };

    delete moduleWorks.managedBy;

    // news は持っていないので新設として出る。見るのは works の側だけ
    expect(
      buildPlan(manifest, { works: moduleWorks }).content_types.map(
        (t) => t.id,
      ),
    ).not.toContain("works");
  });
});

describe("real-world standalone generate (preserve mode)", () => {
  const dir = makeFixture();
  const manifest = analyzeTemplateDir(dir, "realworld", { entry: "main.html" });
  const plan = buildPlan(manifest, SCHEMAS);
  const result = generateFromPlan(dir, manifest, plan);
  const byTarget = new Map(
    result.files.map((f) => [f.target, f.content.toString()]),
  );

  it("keeps the whole document (inline css / nav / scripts) — no layout extraction", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    expect(home).toContain("<style"); // inline CSS は文書ごと保持
    expect(home).not.toContain("@extends");
    expect(home).toContain("<nav");
    expect(home).toContain("@foreach ($works as $work)");
    expect(home).toContain("@foreach ($news as $item)");
    expect(home).toContain("{{ route('works.show', $work->slug) }}");
    // 装飾繰り返し（sticker）は static asset 参照のまま保持
    expect(home).toContain("asset('imported/images/sticker-1.png')");
  });

  it("🔴 生成したどのファイルにも `@php(` を出さない", () => {
    /*
     * Blade は `@php ... @endphp` を**ファイル全体から先に拾う**ので、
     * **短い形がブロック形より前に 1 つでもあると、そこから最初の `@endphp` までが
     * 丸ごと 1 つの PHP ブロックとして食われる。**
     *
     * 施工事例の詳細 view は先頭に短い形が 2 つ、後ろにブロック形が 1 つあり、
     * **生成した時点から全ページ 500 だった**（2026-08-28・実案件で実測）。
     * 古いコンパイル結果が残っていたので、しばらく 200 に見えていた。
     *
     * ▷ **1 ファイルずつではなく、生成物すべてを見る。**
     *   同じ罠を踏むのは 1 か所とは限らない（実際に 3 回踏んだ）。
     */
    const offenders = result.files
      .filter((f) => f.target.endsWith(".blade.php"))
      .filter((f) => f.content.toString().includes("@php("))
      .map((f) => f.target);

    expect(offenders).toEqual([]);
  });

  it("`@php` と `@endphp` の数が合う", () => {
    for (const f of result.files.filter((x) => x.target.endsWith(".blade.php"))) {
      const body = f.content.toString();
      const open = (body.match(/@php\b/g) ?? []).length;
      const close = (body.match(/@endphp\b/g) ?? []).length;

      expect(open, f.target).toBe(close);
    }
  });

  it("取り込んだナビを CMS のメニューから描く（B-56）", () => {
    // 🔴 **layout 1 枚では足りない。**standalone はページごとに html を持つので、
    //    ナビもページの数だけ複製されている（実案件で 12 ファイル・実測）
    const home = byTarget.get("resources/views/public/home.blade.php")!;

    expect(home).toContain("MenuTree::class");
    expect(home).toContain("{{ $__item['url'] }}");
    expect(home).toContain("{{ $__item['title'] }}");
  });

  it("メニューが空なら取り込んだ並びをそのまま出す（B-56）", () => {
    // 入れた瞬間にナビが消える形にしない
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    const empty = home.slice(home.indexOf("@empty"), home.indexOf("@endforelse"));

    expect(empty).toContain("ホーム");
  });

  it("ナビは 1 ページだけでなく全ページで繋がる（B-56）", () => {
    // 🔴 トップだけ直すと、ほかのページのナビは焼き付いたまま残る
    const others = result.files.filter(
      (f) =>
        f.target.endsWith(".blade.php") &&
        f.target !== "resources/views/public/home.blade.php" &&
        f.content.toString().includes("nav-links"),
    );

    expect(others.length).toBeGreaterThan(0);

    for (const f of others) {
      expect(f.content.toString(), f.target).toContain("forKey('header'");
    }
  });

  it("フッターも全ページで繋がる（B-56 / footer）", () => {
    // 🔴 **別経路が 1 本あるだけで、そのページだけ古いまま残る。**
    //    画面は正常に見える（`generateWorksShow()` で実際に起きた）
    const withFooter = result.files.filter(
      (f) =>
        f.target.endsWith(".blade.php") &&
        f.content.toString().includes("<footer"),
    );

    expect(withFooter.length).toBeGreaterThan(1);

    for (const f of withFooter) {
      expect(f.content.toString(), f.target).toContain("forKey('footer'");
    }
  });

  it("フッターの導線も CMS のメニューから描く（B-56 / footer）", () => {
    // `<ul>` を使っていない形。**header と同じ嘘がもう 1 本残らないように**
    const home = byTarget.get("resources/views/public/home.blade.php")!;

    expect(home).toContain("forKey('footer'");
    // 折り返す位置は生成の時点で数え切って埋める（原本は 2 / 1）
    expect(home).toContain("in_array($loop->index, [1], true)");
  });

  it("フッターの見出しは分岐の外に残る（B-56 / footer）", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    const foot = home.slice(home.indexOf("<footer"));

    expect(foot.slice(0, foot.indexOf("@php"))).toContain("<h4>サイトマップ</h4>");
  });

  it("🔴 検索と共有に必要な tag が、全ページに出る", () => {
    /*
     * standalone は**ページごとに html を丸ごと持つ**ので、
     * layout を 1 枚直しても**トップしか直らない**
     * （2026-08-28 実測。実案件で 7 ページが 0 件のまま残った）。
     *
     * **効くのは検索と SNS 共有。**貼っても**タイトルしか出ない**状態になる。
     */
    const pages = result.files.filter(
      (f) => f.target.endsWith(".blade.php") && f.content.toString().includes("<head"),
    );

    expect(pages.length).toBeGreaterThan(1);

    for (const f of pages) {
      const body = f.content.toString();

      expect(body, f.target).toContain('name="description"');
      expect(body, f.target).toContain('rel="canonical"');
      expect(body, f.target).toContain('property="og:title"');
    }
  });

  it("同じ tag を二重に出さない", () => {
    for (const f of result.files.filter(
      (x) => x.target.endsWith(".blade.php") && x.content.toString().includes("<head"),
    )) {
      const body = f.content.toString();

      expect((body.match(/name="description"/g) ?? []).length, f.target).toBe(1);
      expect((body.match(/rel="canonical"/g) ?? []).length, f.target).toBe(1);
    }
  });

  it("ナビの意匠は 1 バイトも動かさない（B-56）", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;

    expect(home).toContain(`<ul class="nav-links">`);
    expect(home).toContain(`<nav id="navbar">`);
    expect(home).toContain(`class="hamburger"`);
  });

  it("generates a single works show template with ordinal slots", () => {
    const show = byTarget.get("modules/Works/views/show.blade.php")!;
    expect(show).toContain("$__works");
    expect(show).toContain("{{ $item->title }}");
    expect(show).toContain("sprintf('%02d', $__num)");
    // 4 ページ分の重複生成はしない
    const showCount = result.files.filter(
      (f) => f.target === "modules/Works/views/show.blade.php",
    ).length;
    expect(showCount).toBe(1);
  });

  it("wires the mock form to contact.store as POST with csrf and turnstile", () => {
    const contact = byTarget.get("modules/Contact/views/form.blade.php")!;
    expect(contact).toContain(`action="{{ route('contact.store') }}"`);
    expect(contact).toContain(`method="post"`);
    expect(contact).toContain("@csrf");
    expect(contact).toContain("cf-turnstile");
    expect(contact).toContain("challenges.cloudflare.com/turnstile");
    // subject / company の UI 追加はしない（hidden input も禁止）
    expect(contact).not.toContain('name="subject"');
    expect(contact).not.toContain('name="company"');
    expect(contact).not.toContain('type="hidden" name=');
  });

  it("emits routes/imported.php for non-standard pages and requests the require patch", () => {
    /*
     * **生成そのものが配線の合図である**（指摘 34）。
     * 「作った」を別の真偽値で持たない — 持つと、真偽値だけ false にしても
     * ファイルは作られたままで試験が全緑になり、利用者の手元でだけ壊れる。
     */
    expect(POST_WRITE_WIRING["routes/imported.php"]).toBeTypeOf("function");

    const routes = byTarget.get("routes/imported.php")!;
    expect(routes).toContain(
      "Route::get('/company', ImportedPageController::class)->defaults('view', 'pages.company')->defaults('layout', 'layouts.preserved.company')->name('company')",
    );
    expect(routes).toContain("PublicCspMiddleware");
    expect(result.contactConstants.map((c) => c.key)).toStrictEqual([
      "subject",
    ]);
  });

  it("externalizes inline scripts so the public CSP does not block them", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    // 実行コードは inline に残さない（CSP script-src 'self' でブロックされる）
    expect(home).not.toContain("function toggleNav");
    expect(home).toContain("asset('imported/js/main-inline-1.js')");
    const js = byTarget.get("public/imported/js/main-inline-1.js")!;
    expect(js).toContain("function toggleNav");
  });

  it("converts inline event handler attributes to addEventListener (CSP)", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    expect(home).not.toContain("onclick=");
    expect(home).toContain('data-agentcms-ev="1"');
    const js = byTarget.get("public/imported/js/main-handlers.js")!;
    expect(js).toContain("addEventListener('click'");
    expect(js).toContain("toggleNav()");
  });

  it("rewrites inline css url() references to imported asset paths", () => {
    const home = byTarget.get("resources/views/public/home.blade.php")!;
    // css/site.css は外部ファイルなので link href が asset() 化される
    expect(home).toContain("asset('imported/css/site.css')");
    // inline <style> の url() はリテラル asset パスへ
    expect(home).toContain('url("/imported/images/bg.png")');
  });
});

describe("project isolation (stale import pruning)", () => {
  it("removes stale files, restores module views from backup, keeps modified files", () => {
    const project = mkdtempSync(join(tmpdir(), "agentcms-prune-"));
    cleanups.push(project);
    const backupDir = join(project, ".agentcms", "manifest", "backup");
    const write = (rel: string, content: string): void => {
      mkdirSync(join(project, rel, ".."), { recursive: true });
      writeFileSync(join(project, rel), content);
    };

    // 旧 import の生成物: ページ view / asset / モジュール view（backup あり）/ 手動変更済み
    write("resources/views/public/about.blade.php", "old about");
    write("public/imported/css/old.css", "old css");
    write("modules/Works/views/index.blade.php", "old works index (generated)");
    mkdirSync(join(backupDir, "modules/Works/views"), { recursive: true });
    writeFileSync(
      join(backupDir, "modules/Works/views/index.blade.php"),
      "pristine works index",
    );
    write("resources/views/pages/edited.blade.php", "hand-edited after apply");

    const previous: ApplyRecord = {
      version: 1,
      manifest_fingerprint: "x",
      plan_sha256: "y",
      applied_at: "2026-01-01T00:00:00Z",
      files: [
        {
          target: "resources/views/public/about.blade.php",
          sha256: hashContent("old about"),
          source: "about.html",
        },
        {
          target: "public/imported/css/old.css",
          sha256: hashContent("old css"),
          source: "css/old.css",
        },
        {
          target: "modules/Works/views/index.blade.php",
          sha256: hashContent("old works index (generated)"),
          source: "works.html",
        },
        {
          target: "resources/views/pages/edited.blade.php",
          sha256: hashContent("original generated content"),
          source: "edited.html",
        },
        {
          target: "resources/views/public/home.blade.php",
          sha256: hashContent("home"),
          source: "index.html",
        },
      ],
    };

    // 新 import は home だけを生成する
    const result = pruneStaleFiles(
      project,
      previous,
      new Set(["resources/views/public/home.blade.php"]),
      backupDir,
    );

    expect(result.deleted).toStrictEqual([
      "public/imported/css/old.css",
      "resources/views/public/about.blade.php",
    ]);
    expect(result.restored).toStrictEqual([
      "modules/Works/views/index.blade.php",
    ]);
    expect(result.kept).toStrictEqual([
      "resources/views/pages/edited.blade.php",
    ]);

    expect(
      existsSync(join(project, "resources/views/public/about.blade.php")),
    ).toBe(false);
    expect(existsSync(join(project, "public/imported/css/old.css"))).toBe(
      false,
    );
    expect(
      readFileSync(
        join(project, "modules/Works/views/index.blade.php"),
        "utf8",
      ),
    ).toBe("pristine works index");
    expect(
      readFileSync(
        join(project, "resources/views/pages/edited.blade.php"),
        "utf8",
      ),
    ).toBe("hand-edited after apply");
  });

  it("🔴 backup が無くても `modules/` は消さない", () => {
    /*
     * `modules/` は PROJECT OWNED（`docs/UPGRADING.md`）で、**利用者のものである。**
     *
     * ▷ **実際に壊した**（2026-08-28・実案件）。新しい Plan が news を
     *   別の場所へ置く前提だったので、`modules/News/views/index.blade.php` を
     *   「旧 Plan の生成物」として消した。ところが `routes/public.php` は
     *   **module が居ればそちらへ繋ぐ**ので、**消した瞬間に `/news` が 500 になった。**
     *
     * ▷ 元は **backup が在るときだけ戻す**形だった。**在る／無いで挙動を変えない。**
     */
    const project = mkdtempSync(join(tmpdir(), "agentcms-prune-nobackup-"));
    const backupDir = mkdtempSync(join(tmpdir(), "agentcms-prune-nobackup-bk-"));

    mkdirSync(join(project, "modules/News/views"), { recursive: true });
    writeFileSync(
      join(project, "modules/News/views/index.blade.php"),
      "module news index",
    );

    const previous: ApplyRecord = {
      version: 1,
      manifest_fingerprint: "x",
      plan_sha256: "y",
      applied_at: "2026-01-01T00:00:00Z",
      files: [
        {
          target: "modules/News/views/index.blade.php",
          sha256: hashContent("module news index"),
          source: "news.html",
        },
      ],
    };

    // 新 Plan は news を別の場所へ置く（= このファイルは「新 Plan に無い」）
    const result = pruneStaleFiles(
      project,
      previous,
      new Set(["resources/views/public/news/index.blade.php"]),
      backupDir,
    );

    expect(result.deleted).toStrictEqual([]);
    expect(result.kept).toStrictEqual(["modules/News/views/index.blade.php"]);
    expect(
      existsSync(join(project, "modules/News/views/index.blade.php")),
    ).toBe(true);

    rmSync(project, { recursive: true, force: true });
    rmSync(backupDir, { recursive: true, force: true });
  });
});
