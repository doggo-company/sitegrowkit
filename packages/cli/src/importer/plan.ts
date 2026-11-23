import type { Manifest, RepeatEntry } from "../inspect/manifest.js";
import {
  canonicalSlugCandidate,
  classifyValueTarget,
  emptyCatalog,
  matchTerm,
  type TaxonomyCatalog,
  type TaxonomyChange,
} from "./plan-contract.js";
import type {
  CmsIntegration,
  ContentSchema,
  ExcludedAsset,
  JsPlan,
  KeepStatic,
  LinkMapEntry,
  ContentTypePlan,
  DatedSeed,
  PagePlan,
  SchemaChange,
  ServerConstant,
  TemplatePlan,
  Uncertainty,
  CardSeed,
  SeedCollection,
} from "./plan-types.js";

/** kit 標準プロジェクトの公開ページ規約（route / view の対応） */
const PAGE_TARGETS: Record<
  string,
  { route: string; url: string; target: string }
> = {
  "index.html": {
    route: "home",
    url: "/",
    target: "resources/views/public/home.blade.php",
  },
  "about.html": {
    route: "about",
    url: "/about",
    target: "resources/views/public/about.blade.php",
  },
  "service.html": {
    route: "service",
    url: "/service",
    target: "resources/views/public/service.blade.php",
  },
  "works.html": {
    route: "works.index",
    url: "/works",
    target: "modules/Works/views/index.blade.php",
  },
  "contact.html": {
    route: "contact.show",
    url: "/contact",
    target: "modules/Contact/views/form.blade.php",
  },
  "news.html": {
    route: "news.index",
    url: "/news",
    target: "modules/News/views/index.blade.php",
  },
};

/** kit の Laravel 側に最初から存在する route（これ以外は routes/imported.php を生成する） */
export const BUILTIN_ROUTES = new Set([
  "home",
  "about",
  "service",
  "works.index",
  "works.show",
  "news.index",
  "news.show",
  "contact.show",
]);

const META_RE = /^(.{1,60}?)\s*\/\s*((?:19|20)\d{2})$/;

/** "2026.04.01 本文…" / "2026-04-01" 形式の日付先頭テキスト（time 要素を持たない実案件 News） */
const DATE_PREFIX_RE = /^((?:19|20)\d{2})[./-](\d{1,2})[./-](\d{1,2})\s*(.*)$/;

/**
 * Reference-Based Asset Allowlist（security rule / ADR-0010 改訂）:
 * HTML/CSS から実際に参照され、かつ Web 配信用形式のものだけを public へ copy する。
 * 作業ディレクトリに混在する制作・文書ファイル（psd/ai/docx/py/bak 等）は絶対に copy しない
 */
const WEB_SAFE_EXT = new Set([
  ".jpg",
  ".jpeg",
  ".png",
  ".webp",
  ".avif",
  ".gif",
  ".svg",
  ".ico",
  ".woff",
  ".woff2",
  ".ttf",
  ".otf",
  ".css",
  ".js",
  ".mjs",
]);

const BLOCKED_EXT = new Set([
  ".psd",
  ".psb",
  ".ai",
  ".af",
  ".afdesign",
  ".afphoto",
  ".afpub",
  ".sketch",
  ".fig",
  ".xd",
  ".indd",
  ".doc",
  ".docx",
  ".xls",
  ".xlsx",
  ".ppt",
  ".pptx",
  ".py",
  ".sh",
  ".rb",
  ".pl",
  ".php",
  ".bak",
  ".old",
  ".orig",
  ".tmp",
  ".zip",
  ".tar",
  ".gz",
  ".tgz",
  ".7z",
  ".rar",
  ".map",
  ".sql",
  ".log",
  ".env",
]);

/** 明示的な公開指定があるときのみ copy を許可する形式 */
const NEEDS_APPROVAL_EXT = new Set([".pdf"]);

function extOf(path: string): string {
  const base = path.slice(path.lastIndexOf("/") + 1);
  const i = base.lastIndexOf(".");
  return i === -1 ? "" : base.slice(i).toLowerCase();
}

/** .env / .env.* / *.html.bak のような多重拡張子・名前ベースの禁止判定 */
function isBlockedName(path: string): boolean {
  const base = path.slice(path.lastIndexOf("/") + 1).toLowerCase();
  return (
    base === ".env" ||
    base.startsWith(".env.") ||
    /\.(bak|old|orig|tmp)$/.test(base)
  );
}

/**
 * 取り込んだ値の行き先を決めて、対応する変更提案を積む（Phase 9.3B / #23）。
 *
 * **判定は `classifyValueTarget()` ただ 1 つ**に任せる。ここで
 * 「works なら選択肢」「news なら分類」のような分岐を書いた瞬間、
 * schema を変えても import の挙動が追従しなくなる。
 */
function routeImportedValues(
  schemaId: string,
  key: string,
  rawValues: string[],
  schemas: Record<string, ContentSchema | undefined>,
  catalog: TaxonomyCatalog,
  sink: {
    schema_changes: SchemaChange[];
    taxonomy_changes: TaxonomyChange[];
    uncertainties: Uncertainty[];
  },
): void {
  // 出現回数を数える（提案に添える。1 回しか出ない値と全件に付く値は重みが違う）
  const occurrences = new Map<string, number>();

  for (const value of rawValues) {
    const trimmed = value.trim();

    if (trimmed === "") continue; // 空は「分類が無い」であって新しい分類ではない

    occurrences.set(trimmed, (occurrences.get(trimmed) ?? 0) + 1);
  }

  if (occurrences.size === 0) return;

  const schema = schemas[schemaId];
  const target = classifyValueTarget(schema, key);

  if (target.kind === "unknown") {
    sink.uncertainties.push({
      subject: `${schemaId}.${key}`,
      level: "REVIEW",
      note: `${target.reason}。取り込んだ値（${[...occurrences.keys()].sort().join(", ")}）は自動で追加しません`,
    });

    return;
  }

  if (target.kind === "select") {
    const missing = [...occurrences.keys()]
      .filter((v) => !target.choices.includes(v))
      .sort();

    if (missing.length === 0) return;

    sink.schema_changes.push({
      schema: schemaId,
      field: key,
      change: "add_choices",
      scope: "project",
      values: missing,
      confidence: "CONFIDENT",
      reason: `テンプレートのカード表記を保持するため ${key} の選択肢を追加する（project の override のみ。既存の選択肢は変更しない。フィールド追加はしない）`,
    });

    return;
  }

  const entries = [...occurrences.entries()].sort(([a], [b]) =>
    a.localeCompare(b),
  );

  // **由来の値が違っても canonical slug は衝突する**（`foo bar` / `foo-bar` / `foo_bar`）。
  // 作れるのは 1 つだけなので、勝手にまとめず **全部を人の判断へ回す**
  const slugCounts = new Map<string, number>();

  for (const [value] of entries) {
    const slug = canonicalSlugCandidate(value);

    if (slug !== null) slugCounts.set(slug, (slugCounts.get(slug) ?? 0) + 1);
  }

  for (const [value, count] of entries) {
    const slug = canonicalSlugCandidate(value);
    const collision = slug !== null && (slugCounts.get(slug) ?? 0) > 1;
    const match = collision
      ? ({
          kind: "undetermined",
          reason: `取り込んだ値のうち複数が同じ slug [${slug}] になります。どれを採用するか決める必要があります`,
        } as const)
      : matchTerm(catalog, target.key, slug);

    sink.taxonomy_changes.push({
      change: "add_terms",
      scope: "project",
      schema: schemaId,
      taxonomy: target.key,
      source_value: value,
      occurrences: count,
      proposed_name: value,
      // 衝突しているものは slug を提案しない（採用先が決まっていない）
      proposed_slug: collision ? null : slug,
      match,
      confidence: match.kind === "absent" ? "CONFIDENT" : "REVIEW",
      reason: reasonFor(match, value, collision ? null : slug, target.key),
    });
  }
}

function reasonFor(
  match: TaxonomyChange["match"],
  value: string,
  slug: string | null,
  taxonomy: string,
): string {
  switch (match.kind) {
    case "active":
      return `分類 [${taxonomy}] に slug [${match.slug}] の項目が既にあるため、新規作成せずその項目（${match.term_id}）を使う`;
    case "absent":
      return `分類 [${taxonomy}] に slug [${slug}] の項目が無いため、「${value}」を新規作成する提案`;
    case "archived":
      return `分類 [${taxonomy}] の slug [${match.slug}] はアーカイブ済み（${match.term_id}）。自動で復帰も再作成もしない。復帰させるか別の slug にするかを人が決める必要がある`;
    case "undetermined":
      return `既存項目を確認できなかったため作成しない: ${match.reason}`;
  }
}

/**
 * Manifest から変換計画を決定論的に生成する（AI 不要）。
 * 判断できないものは決めずに REVIEW / UNKNOWN として表現する（spec §18）。
 */
/**
 * 取り込み先の型が、**このプロジェクトに在るか**（R-13）。
 *
 * 無いのに計画へ書くと、`plan-contract` が
 * 「schema [works] はこのプロジェクトに登録されていません」で止め、**apply が完走しない**。
 *
 * ▷ **kit には news / works が在り、生成プロジェクトには無い**
 *   （`distribution.ts` が配らないと決めている）。
 *   kit の中だけで試すと**この差を一度も通らない** — 実際そうなっていた。
 *
 * ▷ 無いときは**黙って落とさず、`uncertainty` として言う**。
 *   検出できたのは事実なので、「見つけたが受け皿が無い」と伝えるのが正しい。
 *   受け皿ごと作るのは Stage 2 の仕事である。
 */
/**
 * kit が例として同梱する module のモデル（R-13 Stage 2）。
 *
 * **CMS が作る型と規則が違う。** CTB は `Modules\Works\Models\Works`（Studly を 2 度）だが、
 * kit の例は `Modules\Works\Models\Work` である。どちらを指すかは
 * schema の `managedBy` でしか決まらないので、ここで分岐して plan に書き残す。
 */
const KIT_EXAMPLE_MODELS: Record<string, string> = {
  works: "Modules\\Works\\Models\\Work",
  news: "Modules\\News\\Models\\News",
};

/** その投稿タイプのモデル（FQCN） */
function modelClassFor(
  typeId: string,
  schemas: Record<string, ContentSchema | undefined>,
  planned: ContentTypePlan[],
): string {
  const cmsManaged =
    planned.some((t) => t.id === typeId) ||
    schemas[typeId]?.managedBy === "cms";

  if (!cmsManaged && KIT_EXAMPLE_MODELS[typeId]) {
    return KIT_EXAMPLE_MODELS[typeId];
  }

  // CTB の規則（`ContentTypeIdentifier::modelClass()`）
  const studly = typeId
    .split("_")
    .map((part) => (part[0]?.toUpperCase() ?? "") + part.slice(1))
    .join("");

  return `Modules\\${studly}\\Models\\${studly}`;
}

/** その型を CMS が持つか（既に在る / これから作る） */
function isCmsManaged(
  id: string,
  schemas: Record<string, ContentSchema | undefined>,
  planned: ContentTypePlan[],
): boolean {
  return (
    planned.some((t) => t.id === id) || schemas[id]?.managedBy === "cms"
  );
}

/** 行の束を、投入先つきの collection に組み立てる */
function seedCollections(
  seeds: { works: CardSeed[]; news: DatedSeed[] },
  schemas: Record<string, ContentSchema | undefined>,
  planned: ContentTypePlan[],
): SeedCollection[] {
  const out: SeedCollection[] = [];

  if (seeds.works.length > 0) {
    out.push({
      type: "works",
      model: modelClassFor("works", schemas, planned),
      shape: "cards",
      rows: seeds.works,
    });
  }

  if (seeds.news.length > 0) {
    out.push({
      type: "news",
      model: modelClassFor("news", schemas, planned),
      shape: "dated",
      rows: seeds.news,
    });
  }

  return out;
}

/**
 * 新しく作れる投稿タイプ（R-13 Stage 2 / 裁定 (i)）。
 *
 * `label` と `preset` は **`ContentTypeBuilderPresets` に実在するもの**を指す。
 * 項目定義（fields）は**ここに持たない** — 展開は PHP 側の仕事である（F-18）。
 */
const CREATABLE_TYPES: Record<string, { label: string; preset: string }> = {
  works: { label: "実績", preset: "works" },
  news: { label: "お知らせ", preset: "news" },
};

/**
 * 受け皿の投稿タイプが在るか。無ければ**作る計画を立てる**（R-13 Stage 2）。
 *
 * Stage 1 では「無いので取り込まない」と言うだけだった。取り込めないと、
 * 利用者は**取り込み結果を CMS で編集できない**（実績もお知らせも静的なまま）。
 *
 * ここで作る計画を立てても、**その場では何も起きない**。
 * plan は人が承認する門を既に持っているので、そこに載せる（新しい確認 UI は作らない）。
 */
function schemaAvailable(
  schemas: Record<string, ContentSchema | undefined>,
  id: string,
  found: string,
  sink: { uncertainties: Uncertainty[]; content_types: ContentTypePlan[] },
): boolean {
  if (sink.content_types.some((t) => t.id === id)) return true;

  const creatable = CREATABLE_TYPES[id];
  const existing = schemas[id];

  /*
   * **`content_types` は「作る型」ではなく「この取り込みが使う CMS 管理の型」**
   * （R-13 10 件目の残余）。
   *
   * 「作った時の副作用として公開する」形にしていたので、**型が既に在る回**
   * （= 2 度目以降の apply）は plan から消え、**公開の後付けが二度と走らなかった**
   * （型は在るのに `/works` が 404 のまま）。
   *
   * ▷ 冪等の意味は「作った回だけ効く」ではなく「**目標の状態へ揃える**」である。
   *   在っても書き続け、apply 側が「作る／公開する／何もしない」を実物を見て決める。
   */
  if (existing?.managedBy === "cms" && creatable !== undefined) {
    sink.content_types.push({
      id,
      label: creatable.label,
      preset: creatable.preset,
      confidence: "REVIEW",
      reason: `${found}を検出。投稿タイプ [${id}] は既に在るので作成はせず、公開面（/${id}）を使う。投稿タイプ ID [${id}] は公開 URL とデータのキーなので、あとから変更できません`,
    });

    return true;
  }

  if (existing) return true;

  if (creatable === undefined) {
    sink.uncertainties.push({
      subject: `schema.${id}`,
      level: "REVIEW",
      note: `${found}を検出したが、受け皿の投稿タイプ [${id}] がこのプロジェクトに無いので取り込まない。取り込むには、先に投稿タイプ ID を ${id} にした投稿タイプを作ってください（管理画面の「投稿タイプ」）。表示名は自由です`,
    });

    return false;
  }

  sink.content_types.push({
    id,
    label: creatable.label,
    preset: creatable.preset,
    confidence: "REVIEW",
    reason: `${found}を検出したが、受け皿の投稿タイプ [${id}] がこのプロジェクトに無いので、「${creatable.label}」を新しく作って取り込む（構成: ${creatable.preset}）。投稿タイプ ID [${id}] は公開 URL とデータのキーになるため、作ったあとに変更できません（表示名「${creatable.label}」はいつでも変えられます）`,
  });

  return true;
}

/**
 * kit では **module の view と名前付き route** に載るページ（R-13）。
 *
 * `works.html` → `works.index` / `news.html` → `news.index` は、
 * **その投稿タイプが在るプロジェクトでしか成り立たない**。
 * 利用者のプロジェクトには module も route も無い（`distribution.ts` が配らない）。
 */
const MODULE_BACKED_PAGES: Record<string, string> = {
  "works.html": "works",
  "news.html": "news",
};

/**
 * 「まだ動かない」ことを言っている送信ボタンの文言（R-16 ③）。
 *
 * 静的テンプレートの送信ボタンは、モックなので「準備中」と書いてあることがある。
 * **原文はそのまま残す**（Preserve First。作り話をしない）が、取り込んだ後は
 * フォームが実際に動くので、**その文言は利用者に嘘をつく**。
 *
 * ▷ 書き換えず、**人に伝える**。当たった語も出典として note に出す。
 *
 * 部分一致・大小無視で見るので、「準備中です」「準備中につき」は `準備中` で当たる。
 * 語を増やすときはここだけを触る。
 */
const NOT_READY_WORDS = [
  "準備中",
  "工事中",
  "近日公開",
  "coming soon",
  "comingsoon",
  "under construction",
] as const;

/** 送信ボタンの文言が「まだ動かない」を名乗っているか。当たった語を返す */
function notReadyWord(label: string | null): string | null {
  if (label === null) return null;

  const haystack = label.toLowerCase();

  return NOT_READY_WORDS.find((w) => haystack.includes(w)) ?? null;
}

function moduleBackedPageAvailable(
  path: string,
  schemas: Record<string, ContentSchema | undefined>,
): boolean {
  const id = MODULE_BACKED_PAGES[path];

  return id === undefined || Boolean(schemas[id]);
}

export function buildPlan(
  manifest: Manifest,
  schemas: Record<string, ContentSchema | undefined>,
  catalog: TaxonomyCatalog = emptyCatalog(),
): TemplatePlan {
  const uncertainties: Uncertainty[] = [];
  const integrations: CmsIntegration[] = [];
  const schema_changes: SchemaChange[] = [];
  const taxonomy_changes: TaxonomyChange[] = [];
  /*
   * 内部では**行だけ**を貯める。どの型へ入れるかは最後に決める（R-13 Stage 2）。
   * plan の形に型名を焼き付けない — 焼き付いていると別名の型を持ち込めない。
   */
  const seeds = {
    works: [] as CardSeed[],
    news: [] as DatedSeed[],
    site: {} as Record<string, string>,
  };

  /** 新しく作る投稿タイプ（受け皿が在れば空のまま） */
  const content_types: ContentTypePlan[] = [];

  const cmsManagedNews = (): boolean =>
    isCmsManaged("news", schemas, content_types);

  // Site Settings 初期値: entry ページの og:site_name / description から導出（無ければ投入しない）
  const entry = manifest.entry;
  const entryPage = manifest.pages.find(
    (p) => p.path === (entry ?? "index.html"),
  );
  const siteName = entryPage?.head.og["og:site_name"];
  if (siteName) seeds.site["site_name"] = siteName;
  if (entryPage?.head.description)
    seeds.site["seo_description"] = entryPage.head.description;

  // ---- Works: card_grid 構造 × "category / year" meta パターン ----
  const cardGrids = manifest.common_structures.filter(
    (c) => c.kind === "card_grid",
  );
  const workRepeats: RepeatEntry[] = [];
  for (const grid of cardGrids) {
    const itemSig = grid.selector.split(" > ")[1] ?? "";
    for (const r of manifest.repeats) {
      if (r.item === itemSig) workRepeats.push(r);
    }
  }
  const workParseOk =
    workRepeats.length > 0 &&
    workRepeats.every(
      (r) =>
        r.items.filter((i) => i.heading && i.meta && META_RE.test(i.meta))
          .length >=
        r.count * 0.7,
    );

  if (
    workParseOk &&
    schemaAvailable(schemas, "works", "実績カードの繰り返し", {
      uncertainties,
      content_types,
    })
  ) {
    const largest = [...workRepeats].sort((a, b) => b.count - a.count)[0]!;
    largest.items.forEach((item, idx) => {
      const meta = META_RE.exec(item.meta ?? "");
      if (!item.heading || !meta) return;
      seeds.works.push({
        title: item.heading,
        slug: `imported-work-${idx + 1}`,
        category: (meta[1] ?? "").trim(),
        completed_year: Number(meta[2]),
        image: item.image,
        order: idx,
      });
    });

    integrations.push({
      id: "works-cards",
      schema: "works",
      // CMS が持つ型は名前付き route を持たない（fallback が受ける）
      detail_route: isCmsManaged("works", schemas, content_types)
        ? null
        : "works.show",
      regions: workRepeats
        .map((r) => ({ page: r.page, container: r.container, item: r.item }))
        .sort((a, b) => a.page.localeCompare(b.page)),
      field_mapping: {
        heading: "title",
        image: "main_image_id (Media 経由)",
        "meta (前半)": "category",
        "meta (後半)": "completed_at (年)",
      },
      server_constants: [],
      unmapped_html_fields: [],
      missing_schema_fields: [
        {
          key: "body",
          note: "カードに本文が無いため seed はプレースホルダー本文を生成（詳細ページは元デザインに存在せず、導線も生成しない）",
        },
      ],
      confidence: "CONFIDENT",
      reason: `同一 DOM 構造のカードが ${workRepeats
        .map((r) => `${r.page}: ${r.count}件`)
        .join(
          " / ",
        )} 検出され、heading / image / "category / year" meta の繰り返しデータとして解析できた。既存 works schema と互換。元デザインのカードは詳細リンクを持たないため、リンクは生成しない（Preserve First）`,
    });

    // 取り込んだ値の行き先を **schema contract に聞いて**決める。
    // 「works だから」「category という名前だから」で決めない（IM-1〜IM-6）
    routeImportedValues(
      "works",
      "category",
      seeds.works.map((w) => w.category),
      schemas,
      catalog,
      { schema_changes, taxonomy_changes, uncertainties },
    );
  }

  // ---- Works（実案件パターン）: 詳細ページへのリンクを持つカード ----
  // "category / year" meta が無くても、全 item が heading + image + 実在ページへの link を
  // 持つ card_grid は Works 候補（確定はせず REVIEW — 見た目の繰り返しだけで CMS 化しない）
  let worksLinkRepeat: RepeatEntry | null = null;
  if (!workParseOk) {
    const pagePaths = new Set(manifest.pages.map((p) => p.path));
    worksLinkRepeat =
      workRepeats.find(
        (r) =>
          r.count >= 2 &&
          r.items.every(
            (i) => i.heading && i.image && i.link && pagePaths.has(i.link),
          ),
      ) ?? null;
  }
  /*
   * **ここにも同じ門が要る**（R-13 Stage 1 の残り）。
   *
   * 上の `workParseOk` の枝だけを塞いだが、**category を持たないカード**は
   * この枝へ来て、`schema_changes`（`make_optional`）を書いていた。
   * **止血したのは受け皿の指定側だけで、override を書く側が別経路として残っていた。**
   *
   * ▷ 最初の 4 型試験がここを踏まなかったのは、fixture のカードが category を持っていたからである
   *   — **「その変異が通る道に fixture が居たか」の再演**だった。
   */
  if (
    worksLinkRepeat &&
    schemaAvailable(schemas, "works", "詳細リンク付きの実績カード", {
      uncertainties,
      content_types,
    })
  ) {
    worksLinkRepeat.items.forEach((item, idx) => {
      seeds.works.push({
        title: item.heading ?? "",
        slug: `imported-work-${idx + 1}`,
        category: "", // 原デザインに category 概念が無い — 架空値を補完しない
        completed_year: null,
        image: item.image,
        order: idx,
        excerpt: item.meta,
        source_page: item.link,
      });
    });
    const detailPages = worksLinkRepeat.items
      .map((i) => i.link)
      .filter((v): v is string => v !== null);
    integrations.push({
      id: "works-cards",
      schema: "works",
      // CMS が持つ型は名前付き route を持たない（fallback が受ける）
      detail_route: isCmsManaged("works", schemas, content_types)
        ? null
        : "works.show",
      regions: [
        {
          page: worksLinkRepeat.page,
          container: worksLinkRepeat.container,
          item: worksLinkRepeat.item,
        },
      ],
      field_mapping: {
        heading: "title",
        image: "main_image_id (Media 経由)",
        meta: "excerpt",
        link: "works.show への実リンク（元は静的詳細ページ）",
      },
      server_constants: [],
      unmapped_html_fields: [],
      missing_schema_fields: [
        {
          key: "category",
          note: "原デザインに category の入力・表示概念が無い。架空値を補完せず project schema override（optional 化）で解決する",
        },
        {
          key: "completed_at",
          note: "原デザインに完了日が無いため未設定のまま",
        },
      ],
      confidence: "REVIEW",
      reason: `${worksLinkRepeat.page} の ${worksLinkRepeat.container} に heading + image + 詳細ページリンクのカード ${worksLinkRepeat.count} 件（詳細: ${detailPages.join(", ")}）。既存 works schema（title / main_image / excerpt）へ最小限でマッピングする候補。詳細ページ本文の取り込み方針は人間確認が必要`,
    });
    /*
     * **これから作る型には override を書かない**（R-13 Stage 2）。
     *
     * override は「既に在る定義の一部を上書きする」もので、
     * 定義そのものが無いうちは検証できない（apply の contract も通らない）。
     * 作る側の話なので、**作ったあとに人が決める**ことにして、そう言う。
     */
    if (schemas["works"]) {
      schema_changes.push({
        schema: "works",
        field: "category",
        change: "make_optional",
        scope: "project",
        values: [],
        confidence: "CONFIDENT",
        reason:
          "原デザインに category 概念が無いため project scope override（content/schemas/overrides/works.json）で optional 化する。Core schema は変更しない — 架空の category を入力必須にすると管理画面で編集できなくなる",
      });
    } else {
      uncertainties.push({
        subject: "works.category",
        level: "REVIEW",
        note: "原デザインに category 概念が無い。投稿タイプ [works] はこれから作るので、作ったあとに「カテゴリ」を任意へ変えるかを決めてください（管理画面の「投稿タイプ」）。ここでは何も変更しません",
      });
    }
    /*
     * 案内の文言も**世界で変える**（R-13 9 件目）。
     *
     * 「Works module の show route」は kit の module が在る世界の話で、
     * CMS が持つ型の世界には module も名前付き route も無い。
     * 旧文言のままだと、**存在しない仕組みを前提に人へ判断を求める**ことになる。
     */
    uncertainties.push({
      subject: `works detail pages (${detailPages.join(", ")})`,
      level: "REVIEW",
      note: isCmsManaged("works", schemas, content_types)
        ? "詳細ページを、投稿タイプ [works] の詳細（/works/{slug}）へまとめる候補。静的ページのまま保持する選択肢もある。apply 方針の人間確認が必要"
        : "Works module の show route で代替可能だが、静的ページのまま保持する選択肢もある。apply 方針の人間確認が必要",
    });
  }

  // ---- News: time + link_text を持つ繰り返し ----
  const newsRepeat = manifest.repeats.find(
    (r) =>
      r.count >= 2 &&
      r.items.every((i) => i.time && i.link_text) &&
      !workRepeats.includes(r),
  );
  if (
    newsRepeat &&
    schemaAvailable(schemas, "news", "お知らせの繰り返し", {
      uncertainties,
      content_types,
    })
  ) {
    newsRepeat.items.forEach((item, idx) => {
      seeds.news.push({
        title: item.link_text ?? "",
        slug: `imported-news-${idx + 1}`,
        date: item.time ?? "",
        order: idx,
      });
    });
    integrations.push({
      id: "news-list",
      schema: "news",
      // CMS が持つ型は名前付き route を持たない（fallback が受ける）
      detail_route: cmsManagedNews() ? null : "news.show",
      regions: [
        {
          page: newsRepeat.page,
          container: newsRepeat.container,
          item: newsRepeat.item,
        },
      ],
      field_mapping: {
        link_text: "title",
        time: "published_at",
        link: 'news.show への実リンク（元は href="#" プレースホルダー）',
      },
      server_constants: [],
      unmapped_html_fields: [],
      missing_schema_fields: [
        { key: "body", note: "一覧のみのため seed はプレースホルダー本文" },
      ],
      confidence: "CONFIDENT",
      reason: `${newsRepeat.page} の ${newsRepeat.container} に time + リンクテキストの繰り返し ${newsRepeat.count} 件。既存 news schema（title / published_at）と互換`,
    });
  }

  // ---- News（実案件パターン）: time 要素の無い「日付 + テキスト」繰り返し ----
  // 条件: container / page 名に news の手掛かりがあり、全 item が日付先頭テキスト
  // （heading が日付のカード形式も含む）。title/body の分割は原デザインから確定できないため REVIEW
  const newsDateRepeats: RepeatEntry[] = [];
  if (!newsRepeat) {
    for (const r of manifest.repeats) {
      if (workRepeats.includes(r) || r === worksLinkRepeat) continue;
      if (r.count < 2) continue;
      if (!/news/i.test(r.container) && !/news/i.test(r.page)) continue;
      const textStyle = r.items.every((i) => {
        const m = DATE_PREFIX_RE.exec(i.text ?? "");
        return m !== null && (m[4] ?? "").length > 0;
      });
      const cardStyle = r.items.every(
        (i) => DATE_PREFIX_RE.test(i.heading ?? "") && i.meta,
      );
      if (textStyle || cardStyle) newsDateRepeats.push(r);
    }
  }
  /*
   * **4 つ目の枝。** 名指しの全箇所を表にして、初めて見つかった（R-13 の監査）。
   * ここは「**実案件パターン**」— 実在のサイトで一番当たりやすい枝である。
   * 枝を 1 つずつ塞いでいると、**塞いだ数だけ安心して、残りを見なくなる**。
   */
  if (
    newsDateRepeats.length > 0 &&
    schemaAvailable(schemas, "news", "日付つきの一覧の繰り返し", {
      uncertainties,
      content_types,
    })
  ) {
    for (const r of newsDateRepeats) {
      for (const item of r.items) {
        const m =
          DATE_PREFIX_RE.exec(item.heading ?? "") ??
          DATE_PREFIX_RE.exec(item.text ?? "");
        if (!m) continue;
        const date = `${m[1]}-${(m[2] ?? "").padStart(2, "0")}-${(m[3] ?? "").padStart(2, "0")}`;
        const title = ((m[4] ?? "") || (item.meta ?? "")).trim();
        if (!title) continue;
        // 一覧とサブページで同一記事のテキスト長が異なる（サブページ側が延長される）実案件が
        // あるため、同一日付 + 前方一致を同一記事とみなす（最初の出現 = 一覧側を採用）
        const isDup = seeds.news.some(
          (n) =>
            n.date === date &&
            (n.title.startsWith(title) || title.startsWith(n.title)),
        );
        if (isDup) continue;
        seeds.news.push({
          title,
          slug: `imported-news-${seeds.news.length + 1}`,
          date,
          order: seeds.news.length,
          body: "", // 一覧テキストのみ — 存在しない本文を生成しない
        });
      }
    }
    integrations.push({
      id: "news-list",
      schema: "news",
      // CMS が持つ型は名前付き route を持たない（fallback が受ける）
      detail_route: cmsManagedNews() ? null : "news.show",
      regions: newsDateRepeats
        .map((r) => ({ page: r.page, container: r.container, item: r.item }))
        .sort((a, b) => a.page.localeCompare(b.page)),
      field_mapping: {
        テキスト先頭の日付: "published_at",
        日付以降のテキスト: "title",
      },
      server_constants: [],
      unmapped_html_fields: [],
      missing_schema_fields: [
        {
          key: "body",
          note: "一覧テキストのみで本文が確定できない — title/body の分割は人間確認が必要（勝手に作り込まない）",
        },
      ],
      confidence: "REVIEW",
      reason: `news の手掛かりを持つ繰り返し（${newsDateRepeats
        .map((r) => `${r.page}: ${r.count}件`)
        .join(
          " / ",
        )}）が「日付 + テキスト」形式。既存 news schema へ最小限（published_at / title）でマッピングする候補`,
    });
    uncertainties.push({
      subject: "news title/body 分割",
      level: "REVIEW",
      note: "原デザインは日付 + 1 行テキストのみ。title と body の構造は確定できないため、全文を title として最小限マッピングする（要確認）",
    });
  }

  // ---- Contact form ----
  // POST フォームを優先。実案件では action 未設定の静的モックフォーム（method=get）も多いため、
  // contact 系フィールド（email + name/message）を持つフォームは接続候補にする（REVIEW）
  const isContactCandidate = (f: (typeof manifest.forms)[number]): boolean => {
    const names = new Set(f.fields.map((x) => x.name));
    return names.has("email") && (names.has("name") || names.has("message"));
  };
  const form =
    manifest.forms.find((f) => f.method === "post") ??
    manifest.forms.find(isContactCandidate);
  const mockForm = form !== undefined && form.method !== "post";
  if (form) {
    const contactSchema = schemas["contact"];
    const schemaFields = contactSchema?.fields ?? [];
    const mapping: Record<string, string> = {};
    const mappedHtml = new Set<string>();
    const mappedSchema = new Set<string>();

    for (const field of form.fields) {
      const exact = schemaFields.find((s) => s.key === field.name);
      if (exact) {
        mapping[field.name] = exact.key;
        mappedHtml.add(field.name);
        mappedSchema.add(exact.key);
      }
    }
    // select 同士で choices が部分集合なら対応付ける（fixture: type → subject）
    for (const field of form.fields) {
      if (mappedHtml.has(field.name) || field.type !== "select") continue;
      const candidate = schemaFields.find(
        (s) =>
          s.type === "select" &&
          !mappedSchema.has(s.key) &&
          (field.options ?? []).every((o) => (s.choices ?? []).includes(o)),
      );
      if (candidate) {
        mapping[field.name] = candidate.key;
        mappedHtml.add(field.name);
        mappedSchema.add(candidate.key);
      }
    }

    const unmapped = form.fields
      .filter((f) => !mappedHtml.has(f.name))
      .map((f) => ({
        name: f.name,
        handling:
          f.type === "checkbox"
            ? "マークアップを保持（client-side required のまま。DB へは保存しない）"
            : "マークアップを保持（DB へは保存しない）",
        reason: `既存 contact schema に対応フィールドが無い（勝手にフィールドを捨てない — spec §26）`,
      }));
    // HTML に存在しない schema フィールド:
    // - 任意 → 未入力のまま（UI へ追加しない — Preserve First）
    // - 必須 select → SERVER-SIDE constant（hidden input は使わない）+ project override で choice 追加
    // - 必須その他 → 架空値を補完せず REVIEW（server constant か project override で人間が解決）
    const serverConstants: ServerConstant[] = [];
    const missingSchema: { key: string; note: string }[] = [];
    for (const s of schemaFields.filter((x) => !mappedSchema.has(x.key))) {
      if (!s.required) {
        missingSchema.push({
          key: s.key,
          note: "任意フィールドのため HTML には追加しない（Preserve First — 未入力のまま）",
        });
        continue;
      }
      if (s.type === "select") {
        // schema は変更しない（add_choices 不要 — 承認済み判断）。
        // apply がバリデーションから該当 field を外し、サーバ側で固定値を保存する
        const value = "Webサイトからのお問い合わせ";
        serverConstants.push({
          key: s.key,
          value,
          note: "HTML に存在しない必須 select。UI へ追加せずサーバ側で固定値を保存する（hidden input 不使用・schema 変更なし）",
        });
        missingSchema.push({
          key: s.key,
          note: `server-side constant "${value}" で保存（schema は変更しない）`,
        });
        continue;
      }
      missingSchema.push({
        key: s.key,
        note: "必須フィールドが HTML に無い。架空値は補完しない — server constant か project override（optional 化）で解決要決定",
      });
      uncertainties.push({
        subject: `contact schema field "${s.key}"`,
        level: "REVIEW",
        note: "必須フィールドが HTML に無い。解決方法（server constant / project override）の人間判断が必要",
      });
    }

    for (const u of unmapped) {
      uncertainties.push({
        subject: `contact form field "${u.name}"`,
        level: "REVIEW",
        note: `${u.reason} → ${u.handling}`,
      });
    }
    const notReady = notReadyWord(form.submit_label ?? null);

    if (notReady !== null) {
      uncertainties.push({
        subject: `contact form submit (${form.page})`,
        level: "REVIEW",
        note: `送信ボタンの文言が「まだ動かない」ことを言ったままです（原文: 「${form.submit_label ?? ""}」／検出語: ${notReady}）。取り込んだフォームは動作します。文言の見直しをご検討ください。書き換えは行っていません`,
      });
    }

    if (mockForm) {
      uncertainties.push({
        subject: `contact form (${form.page})`,
        level: "REVIEW",
        note: `静的モックフォーム（method=${form.method} / action=${form.action ?? "なし"}）を POST + route('contact.store') へ接続する（見た目は保持）`,
      });
    }

    integrations.push({
      id: "contact-form",
      schema: "contact",
      regions: [{ page: form.page, container: form.selector, item: null }],
      field_mapping: mapping,
      server_constants: serverConstants,
      unmapped_html_fields: unmapped,
      missing_schema_fields: missingSchema,
      confidence:
        mockForm || serverConstants.length > 0 ? "REVIEW" : "CONFIDENT",
      reason: mockForm
        ? `静的モックフォーム（method=${form.method}、${form.fields.length} フィールド）を既存 Contact Pipeline（validate → Turnstile → DB → mail → Push）へ接続。見た目は保持し、POST 化 / @csrf / Turnstile widget / エラー表示 / old() のみ注入`
        : `POST フォーム（${form.fields.length} フィールド）を既存 Contact Pipeline（validate → Turnstile → DB → mail → Push）へ接続。見た目は保持し、@csrf / Turnstile widget / エラー表示 / old() のみ注入。外部 endpoint (${form.action}) への送信は route('contact.store') へ置換`,
    });
  }

  // ---- Site Settings: 会社情報の繰り返し（会社名 / 所在地 / TEL）から seed を抽出 ----
  // 表示は static のまま（keep_static）。値だけを既存 Site Settings へ投入する。
  // 未確定値（準備中 等）と対応 field の無いラベル（代表者 等）は投入しない
  const SITE_LABELS: [string, string][] = [
    ["会社名", "site_name"],
    ["所在地", "address"],
    ["TEL", "tel"],
  ];
  for (const r of manifest.repeats) {
    for (const item of r.items) {
      const text = item.text ?? "";
      for (const [label, key] of SITE_LABELS) {
        if (!text.startsWith(label) || seeds.site[key]) continue;
        const value = text
          .slice(label.length)
          .trim()
          .replace(/^[/／:：]\s*/, "")
          .trim();
        if (!value || /準備中|未定|TBD/i.test(value)) continue;
        seeds.site[key] = value;
      }
    }
  }

  // ---- keep static: 統合対象にならなかった繰り返し ----
  // 実際に CMS 統合された繰り返しだけを消費済みにする。統合されなかった card_grid も
  // keep_static（REVIEW）として明示的に disposition を持たせる（判断を宙に浮かせない）
  const consumed = new Set<string>(
    [
      ...(workParseOk ? workRepeats : []),
      ...(worksLinkRepeat ? [worksLinkRepeat] : []),
      ...(newsRepeat ? [newsRepeat] : []),
      ...newsDateRepeats,
    ].map((r) => `${r.page}|${r.container}|${r.item}`),
  );
  const keep_static: KeepStatic[] = [];
  for (const r of manifest.repeats) {
    if (consumed.has(`${r.page}|${r.container}|${r.item}`)) continue;
    if (r.container.startsWith("form.")) continue; // フォーム内フィールドは contact-form が扱う
    keep_static.push({
      region: `${r.container} > ${r.item}`,
      page: r.page,
      confidence: "REVIEW",
      reason:
        "繰り返し構造だが、クライアントが CMS で更新する対象か判断できないため static Blade として保持（必要になれば後続 Phase でスキーマ化）",
    });
    uncertainties.push({
      subject: `${r.page} ${r.container} > ${r.item}`,
      level: "REVIEW",
      note: "繰り返し構造だが CMS 化せず static 保持（判断保留）",
    });
  }
  keep_static.sort(
    (a, b) => a.page.localeCompare(b.page) || a.region.localeCompare(b.region),
  );

  // ---- pages ----
  // entry ページ（--entry / 単一ファイル / index.html）が home。entry が index.html 以外の
  // 場合、index.html を home 規約でマッチさせない（entry の明示が常に優先）。
  // works 詳細ページは works.show へ統合。標準規約に無いページは /<name> の汎用 route を生成
  const worksDetailPages = new Set(
    seeds.works
      .map((w) => w.source_page)
      .filter((v): v is string => typeof v === "string"),
  );
  /**
   * その型を **CMS が持っている**か（既に在る / これから作る）。
   *
   * CMS が持つ型の公開面は `Route::fallback(PublicContentController)` が受ける。
   * **名前付き route は無い**（型ごとに route を足すと boot が DB を読むことになる）。
   * view は `public.<type>.index` / `public.<type>.show` を置けば使われるので、
   * 取り込んだデザインをそのまま**CMS 駆動の一覧**にできる。
   */
  const cmsManaged = (id: string): boolean =>
    content_types.some((t) => t.id === id) || schemas[id]?.managedBy === "cms";

  const pageTargetFor = (
    path: string,
  ): {
    route: string | null;
    url: string;
    target: string;
    reason: string;
  } | null => {
    if (entry && path === entry) {
      const home = PAGE_TARGETS["index.html"]!;
      return {
        ...home,
        reason:
          entry === "index.html"
            ? `kit 標準ページ規約（/ → home）に一致`
            : `entry 指定により home (/) へ割当（--entry ${entry}）`,
      };
    }
    if (path === "index.html" && entry !== null && entry !== "index.html")
      return null;
    if (worksDetailPages.has(path)) {
      if (cmsManaged("works")) {
        return {
          route: null,
          url: "/works/{slug}",
          target: "resources/views/public/works/show.blade.php",
          reason: `works 詳細ページ ${worksDetailPages.size} 件を、CMS が持つ投稿タイプ [works] の詳細（/works/{slug}）へ統合。公開面は fallback が受けるので名前付き route は作らない`,
        };
      }

      return {
        route: "works.show",
        url: "/works/{slug}",
        target: "modules/Works/views/show.blade.php",
        reason: `works 詳細ページ ${worksDetailPages.size} 件を既存 Works module の show（/works/{slug}）へ統合（構造差分は title / 連番 / 画像のみ）`,
      };
    }
    /*
     * **受け皿の module / 型が無いなら、標準規約を当てない**（R-13 の 5 か所目）。
     *
     * `works.html` / `news.html` は kit では module の view と名前付き route
     * （`works.index` / `news.index`）へ割り当てる。**利用者のプロジェクトには
     * その module も route も無い**ので、そのまま当てると
     * **生成した Blade が存在しない route を名指しし、公開ページが全部 500 になる**
     * （実測: `Route [news.index] not defined`）。
     *
     * ▷ 当てなければ**汎用の静的ページ**（`/news` + `routes/imported.php`）へ落ちる。
     *   内部リンクもそちらを向くので、**無いものへのリンクを作らない**。
     */
    const known = PAGE_TARGETS[path];
    const backing = MODULE_BACKED_PAGES[path];

    if (known && backing !== undefined && cmsManaged(backing)) {
      return {
        route: null,
        url: `/${backing}`,
        target: `resources/views/public/${backing}/index.blade.php`,
        reason: `CMS が持つ投稿タイプ [${backing}] の一覧（/${backing}）として取り込む。公開面は fallback が受けるので名前付き route は作らない`,
      };
    }

    if (known && !moduleBackedPageAvailable(path, schemas)) {
      const name = path.replace(/\.html?$/, "");

      return {
        route: name,
        url: `/${name}`,
        target: `resources/views/pages/${name}.blade.php`,
        reason: `受け皿の投稿タイプ [${MODULE_BACKED_PAGES[path]}] がこのプロジェクトに無いので、汎用の静的ページ（/${name}）として取り込む。内部リンクもここを指す（無いものへのリンクを作らない）`,
      };
    }
    if (known) {
      return {
        ...known,
        reason: `kit 標準ページ規約（${known.url} → ${known.route}）に一致`,
      };
    }
    // 汎用静的ページ: ファイル名から /<name> route を導出（routes/imported.php で追加）
    const name = path.replace(/\.html?$/, "");
    if (/^[a-z0-9][a-z0-9-]*$/.test(name)) {
      return {
        route: name,
        url: `/${name}`,
        target: `resources/views/pages/${name}.blade.php`,
        reason: `汎用静的ページとして /${name} route を生成（routes/imported.php — 標準 5 ページ規約外の実案件対応）`,
      };
    }
    return null;
  };
  const pages: PagePlan[] = manifest.pages
    .map((p): PagePlan => {
      const known = pageTargetFor(p.path);
      const pageIntegrations = integrations
        .filter((i) => i.regions.some((r) => r.page === p.path))
        .map((i) => i.id)
        .sort();
      if (known) {
        return {
          source: p.path,
          title: p.title,
          target: known.target,
          route: known.route,
          url: known.url,
          confidence: "CONFIDENT",
          reason: known.reason,
          integrations: pageIntegrations,
        };
      }
      return {
        source: p.path,
        title: p.title,
        target: `resources/views/pages/${p.path.replace(/\.html?$/, "")}.blade.php`,
        route: null,
        url: null,
        confidence: "REVIEW",
        reason:
          "既存 route 規約に対応が無い。route 追加が必要（Phase 5 apply は標準 5 ページのみ対応）",
        integrations: pageIntegrations,
      };
    })
    .sort((a, b) => a.source.localeCompare(b.source));
  if (entry === null) {
    uncertainties.push({
      subject: "entry page (home)",
      level: "REVIEW",
      note: "home (/) にするページが決定できない（index.html が無い/空）。`template inspect --entry <file>` で明示指定が必要",
    });
  }
  for (const p of pages) {
    if (p.confidence === "REVIEW")
      uncertainties.push({
        subject: `page ${p.source}`,
        level: "REVIEW",
        note: p.reason,
      });
  }

  // ---- layout / components ----
  const header = manifest.common_structures.find((c) => c.kind === "header");
  const footer = manifest.common_structures.find((c) => c.kind === "footer");
  const components = manifest.shared_candidates
    .filter((s) => s.kind === "section")
    .map((s, idx) => ({
      component_id: `imported-${s.selector.replace(/[^a-z0-9]+/gi, "-")}-${idx}`,
      kind: "blade",
      source_pattern: s.selector,
      source_pages: s.pages,
      target_blade: `resources/views/components/imported/${s.selector.replace(/^section\./, "").replace(/[^a-z0-9]+/gi, "-")}.blade.php`,
      props: [],
      confidence: "CONFIDENT" as const,
      reason: `${s.pages.length} ページで完全一致する共通セクション（hash ${s.hash}）`,
    }));

  // ---- JS ----
  const javascript: JsPlan[] = [
    ...manifest.js_analysis.files.map((f): JsPlan => {
      if (f.uses_modules) {
        return {
          file: f.path,
          action: "keep",
          confidence: "REVIEW",
          reason:
            "ES modules を使用。bundler 依存の可能性があるため要確認（そのまま保持）",
        };
      }
      return {
        file: f.path,
        action: "keep",
        confidence: "CONFIDENT",
        reason: `vanilla JS（セレクタ: ${f.dom_selectors.join(", ")} / イベント: ${f.event_listeners.join(", ")}）。既存のまま保持し Vue 化しない`,
      };
    }),
    ...manifest.external
      .filter((e) => e.kind === "script")
      .map((e): JsPlan => ({
        file: e.url,
        action: "keep-external",
        confidence: "CONFIDENT",
        reason: `外部ライブラリ${e.library ? `（${e.library}）` : ""}。削除・更新・置換しない（spec §13）。CSP 追加オリジンが必要`,
      })),
  ].sort((a, b) => a.file.localeCompare(b.file));

  // ---- assets（Reference-Based Asset Allowlist — security rule） ----
  // 1) Media Library へ登録するのは「CMS 統合された繰り返し」のコンテンツ画像のみ
  //    （画像だから Media にはしない — Static Design Asset と Content Media を分離）
  const integratedItems = new Set<string>();
  for (const i of integrations) {
    for (const r of i.regions) if (r.item) integratedItems.add(r.item);
  }
  const mediaSelectedAll =
    manifest.image_classification.media_candidates.filter(
      (m) => m.repeat !== null && integratedItems.has(m.repeat),
    );
  // svg は uploads allow-list（jpg/png/webp/avif のみ）に従い Media Library へ登録しない
  // → static copy として保持する（security rule）
  const mediaSelected = mediaSelectedAll.filter(
    (m) => extOf(m.path) !== ".svg",
  );
  for (const m of mediaSelectedAll) {
    if (extOf(m.path) === ".svg") {
      uncertainties.push({
        subject: `asset ${m.path}`,
        level: "REVIEW",
        note: "コンテンツ繰り返し内の svg。uploads allow-list（svg 禁止）に従い Media Library へは登録せず static copy する",
      });
    }
  }
  const mediaPaths = new Set(mediaSelected.map((m) => m.path));

  const headingByImage = new Map<string, string>();
  for (const r of manifest.repeats) {
    for (const item of r.items) {
      if (item.image && item.heading && !headingByImage.has(item.image))
        headingByImage.set(item.image, item.heading);
    }
  }

  // 2) copy 候補 = HTML/CSS から参照されている Web 配信用ファイルのみ。
  //    未参照・制作/文書ファイルは copy しない（削除もしない — 元ディレクトリはそのまま）
  const referenced = new Set(manifest.referenced_assets);
  const excluded: ExcludedAsset[] = [];
  const staticFiles: string[] = [];
  // Media 化される画像でも CSS（inline 含む）の url() から参照されるものは
  // background 用に public へも copy する（dual-use — 参照を 404 にしない）
  const cssRef = new Set(manifest.css_referenced ?? []);
  const copyCandidates = [
    ...manifest.assets.css,
    ...manifest.assets.js,
    ...manifest.assets.fonts,
    ...manifest.assets.other,
    ...manifest.assets.images.filter(
      (i) => !mediaPaths.has(i) || cssRef.has(i),
    ),
  ].sort();
  for (const from of copyCandidates) {
    const ext = extOf(from);
    if (BLOCKED_EXT.has(ext) || isBlockedName(from)) {
      excluded.push({
        path: from,
        kind: "blocked-extension",
        reason:
          "制作・文書・スクリプト系ファイルは public へ copy 禁止（情報漏えい防止 — security rule）",
      });
      if (referenced.has(from)) {
        uncertainties.push({
          subject: `asset ${from}`,
          level: "REVIEW",
          note: "HTML/CSS から参照されているが公開禁止形式。参照の意図を人間確認",
        });
      }
      continue;
    }
    if (!referenced.has(from)) {
      excluded.push({
        path: from,
        kind: "unreferenced",
        reason:
          "HTML/CSS から参照されていないため copy しない（未参照 asset の自動公開はしない。削除もしない）",
      });
      continue;
    }
    if (NEEDS_APPROVAL_EXT.has(ext)) {
      excluded.push({
        path: from,
        kind: "needs-approval",
        reason: "pdf 等は明示的な公開指定がある場合のみ copy する",
      });
      uncertainties.push({
        subject: `asset ${from}`,
        level: "REVIEW",
        note: "参照されているが要承認形式（pdf 等）。公開してよいか人間確認",
      });
      continue;
    }
    if (!WEB_SAFE_EXT.has(ext)) {
      excluded.push({
        path: from,
        kind: "not-allowlisted",
        reason: `拡張子 ${ext || "(なし)"} は Web 配信 allowlist に含まれない`,
      });
      uncertainties.push({
        subject: `asset ${from}`,
        level: "REVIEW",
        note: "参照されているが allowlist 外の形式。公開してよいか人間確認",
      });
      continue;
    }
    staticFiles.push(from);
  }
  const assets = {
    target_prefix: "imported",
    static: staticFiles.map((from) => ({
      from,
      to: `public/imported/${from}`,
    })),
    media: mediaSelected.map((m) => ({
      from: m.path,
      name: headingByImage.get(m.path) ?? m.path,
      alt: headingByImage.get(m.path) ?? "",
    })),
    excluded: excluded.sort(
      (a, b) => a.kind.localeCompare(b.kind) || a.path.localeCompare(b.path),
    ),
  };

  // ---- link map ----
  const link_map: LinkMapEntry[] = [];
  const seenLinks = new Set<string>();
  const addLink = (href: string): void => {
    if (seenLinks.has(href)) return;
    seenLinks.add(href);
    const [path, fragment] = href.split("#");
    if (path === "" && fragment) {
      link_map.push({ href, kind: "anchor", route: null, url: null, fragment });
      return;
    }
    const known = pageTargetFor(path ?? "");
    link_map.push({
      href,
      kind: known ? (fragment ? "anchor" : "route") : "unmapped",
      route: known?.route ?? null,
      url: known?.url ?? null,
      fragment: fragment ?? null,
    });
  };
  for (const p of manifest.pages) {
    for (const href of [...p.links_internal, ...p.links_anchor]) addLink(href);
  }
  link_map.sort((a, b) => a.href.localeCompare(b.href));
  for (const l of link_map) {
    if (l.kind === "unmapped")
      uncertainties.push({
        subject: `link ${l.href}`,
        level: "UNKNOWN",
        note: "対応する route が無い内部リンク。変換されず元のまま残る（broken link の可能性）",
      });
  }

  // ---- env additions (CSP) ----
  const env_additions = cspEnvAdditions(manifest);

  uncertainties.sort(
    (a, b) =>
      a.subject.localeCompare(b.subject) || a.note.localeCompare(b.note),
  );

  return {
    version: 3,
    generated_by: "agentcms template plan",
    source: manifest.source,
    manifest_fingerprint: manifest.fingerprint.total,
    entry,
    pages,
    layout: {
      target: "resources/views/layouts/imported.blade.php",
      header_selector: header?.selector ?? null,
      footer_selector: footer?.selector ?? null,
      reason: header
        ? `header / footer / nav が全ページで完全一致するため共通レイアウトへ抽出（${header.selector} / ${footer?.selector ?? "footer なし"}）`
        : "共通 header が検出されなかった（各ページに残置）",
    },
    components,
    cms_integrations: integrations.sort((a, b) => a.id.localeCompare(b.id)),
    keep_static,
    javascript,
    assets,
    schema_changes,
    taxonomy_changes: taxonomy_changes.sort(
      (a, b) =>
        a.taxonomy.localeCompare(b.taxonomy) ||
        a.source_value.localeCompare(b.source_value),
    ),
    link_map,
    env_additions,
    seeds: {
      site: seeds.site,
      collections: seedCollections(seeds, schemas, content_types),
    },
    content_types,
    uncertainties,
    warnings: manifest.warnings,
  };
}

/** 外部依存から CSP 追加オリジンの .env 追記項目を決定論的に導出する */
function cspEnvAdditions(manifest: Manifest): Record<string, string> {
  const sets: Record<string, Set<string>> = {
    AGENTCMS_CSP_SCRIPT_SRC: new Set(),
    AGENTCMS_CSP_STYLE_SRC: new Set(),
    AGENTCMS_CSP_FONT_SRC: new Set(),
    AGENTCMS_CSP_IMG_SRC: new Set(),
    AGENTCMS_CSP_CONNECT_SRC: new Set(),
    AGENTCMS_CSP_FRAME_SRC: new Set(),
  };
  for (const dep of manifest.external) {
    if (dep.category === "turnstile") continue; // Core CSP が既に許可
    const origin = originOf(dep.url);
    if (!origin) continue;
    if (dep.category === "google-fonts") {
      sets["AGENTCMS_CSP_STYLE_SRC"]!.add("https://fonts.googleapis.com");
      sets["AGENTCMS_CSP_FONT_SRC"]!.add("https://fonts.gstatic.com");
      continue;
    }
    if (dep.category === "adobe-fonts") {
      // Typekit loader は script / css / font を use.typekit.net から取得し、
      // フォントは fetch 経由（connect-src）、beacon は p.typekit.net へ送る
      sets["AGENTCMS_CSP_SCRIPT_SRC"]!.add("https://use.typekit.net");
      sets["AGENTCMS_CSP_STYLE_SRC"]!.add("https://use.typekit.net");
      sets["AGENTCMS_CSP_FONT_SRC"]!.add("https://use.typekit.net");
      sets["AGENTCMS_CSP_CONNECT_SRC"]!.add("https://use.typekit.net");
      sets["AGENTCMS_CSP_CONNECT_SRC"]!.add("https://p.typekit.net");
      sets["AGENTCMS_CSP_IMG_SRC"]!.add("https://p.typekit.net");
      continue;
    }
    if (dep.kind === "script") sets["AGENTCMS_CSP_SCRIPT_SRC"]!.add(origin);
    else if (dep.kind === "style") sets["AGENTCMS_CSP_STYLE_SRC"]!.add(origin);
    else if (dep.kind === "font") sets["AGENTCMS_CSP_FONT_SRC"]!.add(origin);
    else if (dep.kind === "img") sets["AGENTCMS_CSP_IMG_SRC"]!.add(origin);
    else if (dep.kind === "iframe") sets["AGENTCMS_CSP_FRAME_SRC"]!.add(origin);
    if (dep.category === "analytics")
      sets["AGENTCMS_CSP_CONNECT_SRC"]!.add(origin);
  }
  const out: Record<string, string> = {};
  for (const [key, values] of Object.entries(sets)) {
    if (values.size > 0) out[key] = [...values].sort().join(",");
  }
  return out;
}

function originOf(url: string): string | null {
  try {
    return new URL(url.startsWith("//") ? `https:${url}` : url).origin;
  } catch {
    return null;
  }
}
