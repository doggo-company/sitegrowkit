import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { analyzeTemplateDir } from "../src/inspect/analyze.js";
import { validatePlanChanges } from "../src/importer/plan-contract.js";
import { generateFromPlan } from "../src/importer/generate.js";
import { buildPlan } from "../src/importer/plan.js";
import type { ContentSchema } from "../src/importer/plan-types.js";
import { distributesContentType } from "../src/distribution.js";
import {
  declaredRouteNames,
  emittedRouteNames,
  undistributedModuleMarkers,
  unresolvedRoutes,
} from "./support/output-guard.js";

/**
 * **利用者のプロジェクトで plan → apply が通ること**（R-13）。
 *
 * ## なぜこの試験が無かったか
 *
 * kit には `news` / `works` が在り、**生成プロジェクトには無い**
 * （`distribution.ts` が配らないと決めている）。
 * それなのに `plan.ts` は取り込み先を `works` / `news` で決め打ちしていた。
 *
 * **kit の中だけで試すと、この差を一度も通らない。** 実際そうなっていて、
 * 利用者の経路で `apply --dry-run` が
 * 「schema [works] はこのプロジェクトに登録されていません」で止まった。
 *
 * ▷ 同じ家族が 3 件目である（隔離レーンの spec が kit 同梱型に寄りかかっていた 2 件と同じ）。
 *   **「kit で緑」は「利用者で緑」を意味しない。**
 *
 * ## 型の一覧は、配布境界に聞く
 *
 * ここで 4 つを手で書くと、**配布境界が変わったときに置いていかれる**。
 * `distributesContentType()` に聞いて、**配るものだけ**を読み込む。
 */
const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

const KIT_SCHEMA_IDS = [
  "contact",
  "media",
  "news",
  "post",
  "product",
  "site",
  "works",
];

/**
 * 配られる `routes/public.php` から、**利用者の世界で実際に登録される** route 名を読む。
 *
 * `if (class_exists($worksController))` のように**囲われた中**は、
 * module ごと配られない利用者の環境では登録されない（`public.php` 側にその判断が書いてある）。
 * だから**囲いの中は数えない** — 名前の一覧を試験側で二重に持たないための読み方である。
 */
function buyerBuiltinRoutes(): Set<string> {
  const src = readFileSync(
    resolve(__dirname, "../../../template/routes/public.php"),
    "utf8",
  );

  const names = new Set<string>();
  let guardDepth: number | null = null;
  let depth = 0;

  for (const line of src.split("\n")) {
    if (guardDepth === null && /if\s*\(class_exists\(/.test(line)) {
      guardDepth = depth;
    }

    for (const ch of line) {
      if (ch === "{") depth += 1;
      else if (ch === "}") depth -= 1;
    }

    if (guardDepth !== null && depth <= guardDepth) guardDepth = null;
    else if (guardDepth !== null) continue;

    for (const m of line.matchAll(/->name\('([^']+)'\)/g)) names.add(m[1]!);
  }

  return names;
}

function loadSchema(id: string): ContentSchema {
  return JSON.parse(
    readFileSync(resolve(SCHEMAS, `${id}.json`), "utf8"),
  ) as ContentSchema;
}

/** 生成プロジェクトが持つ型だけ（配布境界が決める） */
function generatedProjectSchemas(): Record<string, ContentSchema | undefined> {
  const out: Record<string, ContentSchema | undefined> = {};

  for (const id of KIT_SCHEMA_IDS) {
    if (distributesContentType(id)) out[id] = loadSchema(id);
  }

  return out;
}

const manifest = analyzeTemplateDir(FIXTURE, "fixture");
const schemas = generatedProjectSchemas();

describe("生成プロジェクトの型だけで plan を書く", () => {
  const plan = buildPlan(manifest, schemas);

  it("配布境界が works / news を配っていない", () => {
    // この前提が崩れたら、この試験の意味も変わる
    expect(Object.keys(schemas).sort()).toStrictEqual([
      "contact",
      "media",
      "post",
      "site",
    ]);
  });

  /** これが、利用者の経路で止まっていたもの */
  it("apply の contract を通る（止まらない）", () => {
    expect(validatePlanChanges(plan, { schemas })).toStrictEqual([]);
  });

  it("無い型への変更を書かない", () => {
    expect(plan.schema_changes.map((c) => c.schema)).not.toContain("works");
    expect(plan.schema_changes.map((c) => c.schema)).not.toContain("news");
  });

  /**
   * **Stage 2 で意味が変わった。**
   *
   * Stage 1 は「受け皿が無いので取り込まない」だった。取り込めないと、
   * 利用者は**取り込んだ実績もお知らせも CMS で編集できない**。
   * Stage 2 は**型ごと持ち込む** — ただし勝手に作るのではなく、
   * **plan に書いて、既にある plan 承認の門を通す**。
   */
  it("受け皿が無い型は、作る計画として書く", () => {
    expect(plan.content_types.map((t) => t.id).sort()).toStrictEqual([
      "news",
      "works",
    ]);

    const works = plan.content_types.find((t) => t.id === "works")!;

    expect(works.label).toBe("実績");
    expect(works.preset).toBe("works"); // 項目定義は持たない（PHP が展開する）
    expect(works.confidence).toBe("REVIEW");
  });

  /** 承認する人は「ID を変えられない」ことを知ったうえで承認する必要がある */
  it("ID をあとから変えられないことを、理由に書く", () => {
    for (const type of plan.content_types) {
      expect(type.reason, type.id).toContain("変更できません");
      expect(type.reason, type.id).toContain(type.id);
    }
  });

  it("作る計画を立てた型には、取り込み先として載る", () => {
    const targets = plan.cms_integrations.map((i) => i.schema);

    expect(targets).toContain("works");
    expect(targets).toContain("news");
  });

  /** 投入先は plan が持つ。CMS が作る型はクラス名の規則が kit の例と違う */
  it("seed の投入先を、型名ではなくモデルで書く", () => {
    const works = plan.seeds.collections.find((c) => c.type === "works")!;

    expect(works.shape).toBe("cards");
    expect(works.model).toBe("Modules\\Works\\Models\\Works");
    expect(works.rows.length).toBeGreaterThan(0);
  });
});

describe("kit の型が揃っていれば、これまでどおり取り込む", () => {
  /** 止血が**やりすぎていない**ことを見る（在るなら取り込む） */
  it("works / news が在れば、取り込み先に出る", () => {
    const all: Record<string, ContentSchema | undefined> = {};

    for (const id of KIT_SCHEMA_IDS) all[id] = loadSchema(id);

    const targets = buildPlan(manifest, all).cms_integrations.map(
      (i) => i.schema,
    );

    expect(targets).toContain("works");
    expect(targets).toContain("news");
  });
});

/**
 * **category を持たないカード**の manifest（R-13 Stage 1 の残り）。
 *
 * 最初の 4 型試験はこの枝を踏まなかった — fixture のカードが category（meta）を
 * 持っていたからである。**その変異が通る道に fixture が居なかった。**
 *
 * 実物の manifest から `meta` を落とし、詳細ページへの `link` を足して、
 * 「**works らしいが category が無いカード**」の形にする。
 * **合成 fixture の中だけで作る** — 実案件のデータは使わない。
 */
function manifestWithoutCategories(): typeof manifest {
  const clone = structuredClone(manifest);

  for (const repeat of clone.repeats) {
    if (!repeat.container.includes("card")) continue;

    for (const item of repeat.items) {
      item.meta = null;
      item.link = "works.html";
    }
  }

  return clone;
}

describe("category を持たないカードでも、無い型に override を書かない", () => {
  const plan = buildPlan(manifestWithoutCategories(), schemas);

  /** 実案件の再実行で最後まで残っていたのは、この 1 行だった */
  it("apply の contract を通る", () => {
    expect(validatePlanChanges(plan, { schemas })).toStrictEqual([]);
  });

  it("works への override（make_optional）を書かない", () => {
    expect(
      plan.schema_changes.filter((c) => c.schema === "works"),
    ).toStrictEqual([]);
  });

  /**
   * **これから作る型には override を書けない**（定義がまだ無いので検証できない）。
   * 黙って落とさず、作ったあとに人が決めることとして言う。
   */
  it("作ったあとに決めることとして言う", () => {
    const note = plan.uncertainties.find(
      (u) => u.subject === "works.category",
    )?.note;

    expect(note).toBeDefined();
    expect(note).toContain("これから作る");
    expect(note).toContain("ここでは何も変更しません");
  });

  /** 止血がやりすぎていない: 型が在れば、これまでどおり override を提案する */
  it("works が在れば override を提案する", () => {
    const all: Record<string, ContentSchema | undefined> = {};

    for (const id of KIT_SCHEMA_IDS) all[id] = loadSchema(id);

    const changes = buildPlan(
      manifestWithoutCategories(),
      all,
    ).schema_changes.filter(
      (c) => c.schema === "works" && c.change === "make_optional",
    );

    expect(changes).toHaveLength(1);
  });
});

/**
 * **4 つ目の枝**（R-13 の監査で初めて見つかった）。
 *
 * 「time 要素の無い日付 + テキスト」の繰り返し = **実案件パターン**で、
 * 実在のサイトで一番当たりやすい。**枝を 1 つずつ塞いでいると、
 * 塞いだ数だけ安心して残りを見なくなる** — 表を作って初めて見えた。
 */
function manifestWithDatedList(): typeof manifest {
  const clone = structuredClone(manifest);

  clone.repeats = [
    {
      page: "news.html",
      container: "ul.news-list",
      item: "LI.news-item",
      count: 3,
      items: [1, 2, 3].map((n) => ({
        heading: null,
        image: null,
        link: null,
        link_text: null,
        time: null,
        meta: null,
        text: `2026.08.0${n} 冬季休業のお知らせ ${n}`,
      })),
    },
  ];

  return clone;
}

describe("日付つき一覧（実案件パターン）でも、無い型に書かない", () => {
  const plan = buildPlan(manifestWithDatedList(), schemas);

  it("apply の contract を通る", () => {
    expect(validatePlanChanges(plan, { schemas })).toStrictEqual([]);
  });

  it("型ごと持ち込んで取り込む", () => {
    expect(plan.content_types.map((t) => t.id)).toContain("news");
    expect(plan.cms_integrations.map((i) => i.schema)).toContain("news");
  });

  /** CMS が持つ型の公開面は fallback が受ける（名前付き route を作らない） */
  it("詳細リンクに名前付き route を使わない", () => {
    const news = plan.cms_integrations.find((i) => i.id === "news-list")!;

    expect(news.detail_route).toBeNull();
  });

  /** 止血がやりすぎていない */
  it("news が在れば取り込み先に出る", () => {
    const all: Record<string, ContentSchema | undefined> = {};

    for (const id of KIT_SCHEMA_IDS) all[id] = loadSchema(id);

    expect(
      buildPlan(manifestWithDatedList(), all).cms_integrations.map(
        (i) => i.schema,
      ),
    ).toContain("news");
  });
});

describe("案内は、そのとおりにやれば通るように書く", () => {
  /**
   * **ID が一致条件であることを言わない案内は、書いてあるとおりにやると止まる**
   * （監査 指摘 32）。表示名だけ合わせても取り込まれない。
   */
  it("作る型の ID と、変えられないことを言う", () => {
    const plan = buildPlan(manifestWithDatedList(), schemas);
    const news = plan.content_types.find((t) => t.id === "news")!;

    expect(news.reason).toContain("[news]");
    expect(news.reason).toContain("変更できません");
    expect(news.reason).toContain("表示名"); // 変えられる方も言う
  });
});

describe("持ち込んだ型のページは、CMS 駆動の公開面になる", () => {
  const plan = buildPlan(manifest, schemas);

  /**
   * **無いものへのリンクを作らない**（R-13 の 5 か所目）。
   *
   * `works.html` / `news.html` を kit の module view と名前付き route へ当てると、
   * 利用者のプロジェクトでは **生成した Blade が存在しない route を名指しし、
   * 公開ページが全部 500 になる**（実測: `Route [news.index] not defined`）。
   */
  it("module の view へは書かない", () => {
    const targets = plan.pages.map((p) => p.target);

    expect(targets.some((t) => t?.startsWith("modules/Works"))).toBe(false);
    expect(targets.some((t) => t?.startsWith("modules/News"))).toBe(false);
  });

  it("名前付き route を名指ししない", () => {
    const routes = plan.pages.map((p) => p.route);

    expect(routes).not.toContain("works.index");
    expect(routes).not.toContain("news.index");
    expect(routes).not.toContain("works.show");
  });

  /**
   * **静的な写しで終わらせない**（R-13 Stage 2）。
   *
   * Stage 1 は「受け皿が無いので汎用の静的ページ」だった。見た目は同じでも、
   * **利用者は 1 件も編集できない**。型ごと持ち込むなら、公開面も CMS 駆動にする。
   * 名前付き route は作らない（`Route::fallback` が `/works` を受ける）。
   */
  it("型ごとの公開 view として取り込む", () => {
    const works = plan.pages.find((p) => p.source === "works.html")!;

    expect(works.route).toBeNull();
    expect(works.url).toBe("/works");
    expect(works.target).toBe("resources/views/public/works/index.blade.php");
    expect(works.reason).toContain("fallback");
  });

  /** 止血がやりすぎていない: 型が在れば、これまでどおり module へ載る */
  it("型が在れば module の view へ載る", () => {
    const all: Record<string, ContentSchema | undefined> = {};

    for (const id of KIT_SCHEMA_IDS) all[id] = loadSchema(id);

    const works = buildPlan(manifest, all).pages.find(
      (p) => p.source === "works.html",
    )!;

    expect(works.route).toBe("works.index");
    expect(works.target).toBe("modules/Works/views/index.blade.php");
  });
});

/**
 * **生成した物の中に、無い型の名前が 1 つも出ない**（R-13 の総仕上げ）。
 *
 * ここまでは「plan に出さない」を枝ごとに見てきたが、**枝を数えて塞ぐやり方は 3 回外した**
 * （2 か所目・4 か所目・5 か所目）。枝ではなく**出口**を見る。
 *
 * 生成層（`generate.ts` / `standalone.ts` / `seeder.ts` / `blade-html.ts`）が
 * どんな経路を通っても、**出てきたファイルに無い型の route も module も混ざっていない**なら、
 * 経路の数を数え間違えても捕まる。
 */
describe("生成した物に、無い型の名前が出ない", () => {
  const plan = buildPlan(manifest, schemas);
  const result = generateFromPlan(FIXTURE, manifest, plan);
  const all = result.files.map((f) => `${f.target}\n${f.content}`).join("\n");

  /**
   * **文字列の黒名簿ではなく、呼んだ route が本当に在るかを見る**（監査 指摘 33）。
   *
   * 最初は `route('works.` のような marker を並べたが、**ドット 1 つ分だけ網目が粗く**、
   * **`route('works')`（ドット無し）を素通り**していた。
   * marker を足していく限り、**足し忘れた形だけが通る**。
   *
   * ▷ だから**出た route 名を全部集めて、解決できるかを見る**。
   *   解決できる = 利用者にも在る組み込み route か、生成した `routes/imported.php` に在るか。
   */
  /**
   * 利用者のプロジェクトに**実在する** route。
   *
   * ここで名前を手で並べると、`route` が増えた日に置いていかれる。
   * **配られる `routes/public.php` そのものに聞く** —
   * ただし `if (class_exists(...))` で囲われた中は
   * **module ごと配られない世界では登録されない**ので、除く。
   */
  const BUYER_BUILTIN = buyerBuiltinRoutes();

  /**
   * 名指ししてはいけない module（R-13 Stage 2 で意味が 1 段変わった）。
   *
   * 配らない型でも、**plan が作る計画を立てたなら apply のあとには在る**。
   * だから「配布境界の外」だけでなく「**この plan でも作らない**」型だけを見る。
   */
  const planned = new Set(plan.content_types.map((t) => t.id));
  const undistributed = KIT_SCHEMA_IDS.filter(
    (id) => !distributesContentType(id) && !planned.has(id),
  );

  const declared = declaredRouteNames(
    String(
      result.files.find((f) => f.target === "routes/imported.php")?.content ??
        "",
    ),
  );

  /**
   * **針が 3 本あるのに、canary は 1 本ぶんしか無かった**（監査 指摘 36）。
   *
   * 下の「型が在る世界では〜」は module の名指しだけを確かめていて、
   * **指摘 33 の再発を守るはずの route 側の針が空振りでも緑**だった。
   * 同じ導出で 2 行足す — 集められているか、壊した入力で鳴るか。
   */
  it("route の針が鳴ることを、同じ導出で確かめる", () => {
    // ① そもそも route 名を集められているか（0 件なら以下の「解決できた」は無意味）
    expect(emittedRouteNames(all).length).toBeGreaterThan(0);

    // ② 解決先を空にしたら、ちゃんと未解決として挙がるか
    expect(unresolvedRoutes(all, [], new Set()).length).toBeGreaterThan(0);
  });

  it("在る route の一覧が、module 任せの route まで数えていない", () => {
    expect(BUYER_BUILTIN.has("contact.store")).toBe(true); // 囲いの外は数える
    expect(BUYER_BUILTIN.has("works.index")).toBe(false); // 囲いの中は数えない
    expect(BUYER_BUILTIN.has("news.index")).toBe(false);
    expect(undistributed).toContain("product"); // 名指しを引く元が空でない
  });

  it("呼んだ route が 1 つ残らず解決できる", () => {
    const unresolved = unresolvedRoutes(all, BUYER_BUILTIN, declared);

    expect(
      unresolved,
      `生成物が、どこにも無い route を呼んでいます: ${unresolved.join(", ")}`,
    ).toStrictEqual([]);
  });

  it("名前付き route を持つページは、1 つ残らず登録されている", () => {
    // 落とし先に route が無ければ、**落としただけで壊れる**
    const generic = plan.pages
      .map((p) => p.route)
      .filter((r): r is string => r !== null && !BUYER_BUILTIN.has(r));

    expect(generic.filter((r) => !declared.has(r))).toStrictEqual([]);
  });

  /**
   * 配られない型の module を、名指しで**手で並べない**（監査 指摘 33 ③）。
   *
   * 手で並べた marker は、**当たり方を説明できないものが混ざる**。
   * 実測すると `Modules\\\\Works`（円記号 2 つ）はどの経路でも出ず、
   * 「守りが 4 枚ある」の数だけを増やしていた。
   *
   * ▷ 配布境界から**引く**。当たる形（円記号 1 つ／ディレクトリ）だけを見る。
   *   当たる証拠は下の「型が在れば module の view を生成する」— 同じ 2 形が **true** になる。
   */

  it.each(undistributedModuleMarkers(undistributed).map((m) => [m]))(
    "%s が出ない（配らない module を名指ししている）",
    (needle) => {
      expect(all.includes(needle!)).toBe(false);
    },
  );

  /**
   * 止血がやりすぎていない（型が在れば、これまでどおり生成する）ことと、
   * **上の 6 本の針が的外れでない**ことを、同時に見る。
   *
   * 針を手書きの文字列で確かめると、導出がずれても canary は緑のままになる
   * （figma 側で実際にそうなった）。だから**同じ導出で刺す**。
   */
  it("型が在る世界では、同じ針が刺さる（見張っている証拠）", () => {
    const all_: Record<string, ContentSchema | undefined> = {};

    for (const id of KIT_SCHEMA_IDS) all_[id] = loadSchema(id);

    const withKit = generateFromPlan(
      FIXTURE,
      manifest,
      buildPlan(manifest, all_),
    );
    const body = withKit.files
      .map((f) => `${f.target}\n${String(f.content)}`)
      .join("\n");

    const hits = undistributedModuleMarkers(["works"]).filter((m) =>
      body.includes(m),
    );

    expect(
      hits,
      "kit の形でも 1 つも刺さらない = 針が的外れ（上の 0 件は無意味）",
    ).not.toStrictEqual([]);
  });
});
