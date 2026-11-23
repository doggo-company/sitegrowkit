/**
 * Phase 6.1 Real World Acceptance で実測した pipeline gap の synthetic 回帰テスト。
 * client 固有の名称・node id・寸法は使わない（合成データのみ）。
 * - Gap 1: 名前で pairing できない Desktop/Mobile の generic-signal 候補提示
 * - Gap 2: vector asset identity（同名 vector が 1 placeholder へ潰れない）
 * - Gap 4: fake "#" link を生成しない / Figma 実在 hyperlink のみ <a> 化
 * - Gap 5: image fill の node identity と scaleMode / crop transform の保持
 * - Gap 6: CMS 非マッピング時に未使用 use を出力しない
 */
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import type { FigmaNode } from "../src/figma/api.js";
import { FixtureFigmaApi } from "../src/figma/api-fixture.js";
import { generateFromFigmaPlan } from "../src/figma/generate.js";
import type { DesignManifest } from "../src/figma/manifest.js";
import { buildDesignManifest } from "../src/figma/normalize.js";
import { buildFigmaPlan } from "../src/figma/plan.js";

const FIXTURE = resolve(__dirname, "fixtures/figma");

function frame(
  id: string,
  name: string,
  width: number,
  height: number,
  children: FigmaNode[] = [],
): FigmaNode {
  return {
    id,
    name,
    type: "FRAME",
    absoluteBoundingBox: { x: 0, y: 0, width, height },
    children,
  };
}

function text(
  id: string,
  characters: string,
  extra?: Partial<FigmaNode>,
): FigmaNode {
  return {
    id,
    name: characters.slice(0, 20),
    type: "TEXT",
    characters,
    ...extra,
  };
}

function imageRect(
  id: string,
  name: string,
  ref: string,
  width: number,
  height: number,
  paint?: Record<string, unknown>,
): FigmaNode {
  return {
    id,
    name,
    type: "RECTANGLE",
    absoluteBoundingBox: { x: 0, y: 0, width, height },
    fills: [{ type: "IMAGE", imageRef: ref, ...paint }],
  };
}

function manifestFromRoots(roots: FigmaNode[]): DesignManifest {
  return buildDesignManifest({
    fileKey: "SYNTHKEY0000001",
    meta: {
      name: "Synthetic Hardening File",
      version: "1",
      editorType: "figma",
    },
    roots,
    componentsMeta: {},
    componentSetsMeta: {},
    stylesMeta: {},
    selectedNodes: [],
    variablesRequested: false,
    variables: null,
    variablesUnavailableReason: null,
    rawBytes: 1000,
  });
}

async function fixtureManifest(): Promise<DesignManifest> {
  const api = new FixtureFigmaApi(FIXTURE);
  const metaRes = await api.getFileMeta("FKEYFIXTURE0001");
  const fileRes = await api.getFile("FKEYFIXTURE0001");
  if (!metaRes.ok || !fileRes.ok) throw new Error("fixture load failed");
  return buildDesignManifest({
    fileKey: "FKEYFIXTURE0001",
    meta: metaRes.data,
    roots: [fileRes.data.document],
    componentsMeta: fileRes.data.components,
    componentSetsMeta: fileRes.data.componentSets,
    stylesMeta: fileRes.data.styles,
    selectedNodes: [],
    variablesRequested: false,
    variables: null,
    variablesUnavailableReason: null,
    rawBytes: 1000,
  });
}

describe("Gap 1 — Desktop/Mobile pairing candidates（generic signal）", () => {
  const sharedTexts = [
    "最高の休息をあなたの毎日へ届けるページ",
    "サービスの特徴をまとめたセクション",
    "お問い合わせはこちらからどうぞ",
    "アクセスと営業時間のご案内",
    "私たちのコンセプト紹介",
  ];
  const canvas: FigmaNode = {
    id: "0:1",
    name: "Page 1",
    type: "CANVAS",
    children: [
      frame("1:1", "Landing Alpha", 1440, 2400, [
        ...sharedTexts.map((t, i) => text(`1:t${i}`, t)),
        imageRect("1:img", "Hero", "refShared", 1440, 800),
      ]),
      frame("2:1", "Device Frame 01", 390, 4200, [
        ...sharedTexts.map((t, i) => text(`2:t${i}`, t)),
        imageRect("2:img", "Hero", "refShared", 390, 300),
      ]),
      frame("3:1", "Other Page", 390, 3000, [
        text("3:t0", "全く別の内容のページです"),
        text("3:t1", "共有テキストはありません"),
      ]),
    ],
  };

  it("名前が無関係でも viewport class + テキスト共有で候補を提示し、自動確定はしない", () => {
    const m = manifestFromRoots([canvas]);
    const landing = m.screens.find((s) => s.screen_id === "landing-alpha");
    expect(landing).toBeDefined();
    expect(landing?.pairing).toBe("REVIEW"); // 自動確定しない
    expect(landing?.missing).toContain("mobile");
    const candidates = landing?.pairing_candidates ?? [];
    expect(candidates.map((c) => c.screen_id)).toContain("device-frame-01");
    const cand = candidates.find((c) => c.screen_id === "device-frame-01");
    expect(cand?.signals).toContain("viewport-class");
    expect(cand?.signals).toContain("shared-text");
    expect(cand?.signals).toContain("shared-assets");
    expect(cand?.text_overlap).toBeGreaterThanOrEqual(0.9);
    // 内容が一致しない mobile frame は候補にならない
    expect(candidates.map((c) => c.screen_id)).not.toContain("other-page");
    expect(m.warnings.some((w) => w.code === "PAIRING_CANDIDATE")).toBe(true);
  });

  it("mobile 側にも対称に候補が付く", () => {
    const m = manifestFromRoots([canvas]);
    const device = m.screens.find((s) => s.screen_id === "device-frame-01");
    expect(
      (device?.pairing_candidates ?? []).map((c) => c.screen_id),
    ).toContain("landing-alpha");
  });
});

describe("Gap 2 — vector asset identity", () => {
  const canvas: FigmaNode = {
    id: "0:1",
    name: "Page 1",
    type: "CANVAS",
    children: [
      frame("1:1", "Screen One", 1200, 900, [
        {
          id: "1:v1",
          name: "Dot",
          type: "ELLIPSE",
          absoluteBoundingBox: { x: 0, y: 0, width: 12, height: 12 },
        },
        {
          id: "1:v2",
          name: "Dot",
          type: "ELLIPSE",
          absoluteBoundingBox: { x: 0, y: 0, width: 24, height: 24 },
        },
        text("1:t1", "見出しテキストのサンプル"),
      ]),
    ],
  };

  it("同名 vector が別 asset として識別され、placeholder / CSS / export target を区別できる", () => {
    const m = manifestFromRoots([canvas]);
    const vecIds = m.assets
      .filter((a) => a.kind === "vector")
      .map((a) => a.asset_id);
    expect(vecIds).toContain("vec-dot-12x12");
    expect(vecIds).toContain("vec-dot-24x24");

    const plan = buildFigmaPlan(m, "/nonexistent-project");
    // export target が asset identity で追跡される（referenced のみ export する方針は維持）
    const vecPlans = plan.assets.filter((a) =>
      a.asset_id.startsWith("vec-dot"),
    );
    expect(vecPlans.map((a) => a.target)).toEqual([
      "public/figma/svg/vec-dot-12x12.svg",
      "public/figma/svg/vec-dot-24x24.svg",
    ]);
    // vector は image_ref を持たないため download 対象にはならない
    const result = generateFromFigmaPlan(m, plan);
    expect(result.downloads.map((d) => d.image_ref)).not.toContain(null);

    const blade = String(
      result.files.find((f) => f.target.includes("screen-one"))?.content,
    );
    expect(blade).toContain("fig-vec-vec-dot-12x12");
    expect(blade).toContain("fig-vec-vec-dot-24x24");
    const css = String(
      result.files.find((f) => f.target.endsWith("figma.css"))?.content,
    );
    expect(css).toContain(
      ".figma-page .fig-vec-vec-dot-12x12 { width: 12px; height: 12px; }",
    );
    expect(css).toContain(
      ".figma-page .fig-vec-vec-dot-24x24 { width: 24px; height: 24px; }",
    );
  });
});

describe("Gap 4 — semantic link/CTA（destination を発明しない）", () => {
  it("Figma に実在する hyperlink のみ <a> になる", () => {
    const canvas: FigmaNode = {
      id: "0:1",
      name: "Page 1",
      type: "CANVAS",
      children: [
        frame("1:1", "Screen One", 1200, 900, [
          text("1:t1", "外部リンク付きテキスト", {
            style: {
              hyperlink: { type: "URL", url: "https://example.com/detail" },
            },
          }),
          text("1:t2", "リンクの無いテキスト"),
        ]),
      ],
    };
    const m = manifestFromRoots([canvas]);
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    const result = generateFromFigmaPlan(m, plan);
    const blade = String(
      result.files.find((f) => f.target.includes("screen-one"))?.content,
    );
    expect(blade).toContain('<a href="https://example.com/detail">');
    // リンクの無いテキストへ href を発明しない
    expect(blade).not.toContain('href="#"');
  });

  it('生成物全体に fake "#" link が存在しない（synthetic fixture 全ファイル）', async () => {
    const m = await fixtureManifest();
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    const result = generateFromFigmaPlan(m, plan);
    for (const f of result.files) {
      if (typeof f.content !== "string") continue;
      expect(f.content, f.target).not.toContain('href="#"');
    }
    // nav container の semantic 構造は維持される
    const layout = String(
      result.files.find((f) => f.target.endsWith("layout.blade.php"))?.content,
    );
    expect(layout).toContain("<nav");
    expect(layout).toContain("<ul>");
  });
});

describe("Gap 5 — image fill identity / crop transform", () => {
  const canvas: FigmaNode = {
    id: "0:1",
    name: "Page 1",
    type: "CANVAS",
    children: [
      frame("1:1", "Screen One", 1200, 900, [
        imageRect("1:i1", "Layer Under", "refUnder", 1200, 800, {
          scaleMode: "FILL",
        }),
        imageRect("1:i2", "Layer Over", "refOver", 1200, 800, {
          scaleMode: "CROP",
          imageTransform: [
            [1, 0, 0.12345],
            [0, 0.9, 0],
          ],
        }),
        text("1:t1", "本文テキストのサンプル"),
      ]),
    ],
  };

  it("同寸の image node がそれぞれ自分の fill reference と対応する（node identity）", () => {
    const m = manifestFromRoots([canvas]);
    const tree = m.screens[0]?.variants[0]?.tree;
    const images: { id: string; asset?: string }[] = [];
    const visit = (el: {
      id: string;
      role: string;
      asset?: string;
      children?: unknown[];
    }): void => {
      if (el.role === "image") images.push({ id: el.id, asset: el.asset });
      for (const c of (el.children ?? []) as never[]) visit(c);
    };
    if (tree) visit(tree);
    expect(images).toHaveLength(2);
    // 同寸でも asset が区別され、document 順（= z 順）が保存される
    expect(images[0]?.asset).toBeDefined();
    expect(images[1]?.asset).toBeDefined();
    expect(images[0]?.asset).not.toBe(images[1]?.asset);
  });

  it("scaleMode / crop transform が Design Intent として Manifest に残る", () => {
    const m = manifestFromRoots([canvas]);
    const tree = m.screens[0]?.variants[0]?.tree;
    const flat: {
      role: string;
      fill_mode?: string;
      fill_transform?: number[];
    }[] = [];
    const visit = (el: {
      role: string;
      fill_mode?: string;
      fill_transform?: number[];
      children?: unknown[];
    }): void => {
      flat.push(el);
      for (const c of (el.children ?? []) as never[]) visit(c);
    };
    if (tree) visit(tree);
    const cover = flat.find((e) => e.fill_mode === "cover");
    const crop = flat.find((e) => e.fill_mode === "crop");
    expect(cover).toBeDefined();
    expect(crop).toBeDefined();
    expect(crop?.fill_transform).toHaveLength(6);
    expect(crop?.fill_transform?.[2]).toBeCloseTo(0.1235, 4);
  });

  it("fill intent が生成物へ object-fit として反映される", () => {
    const m = manifestFromRoots([canvas]);
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    const result = generateFromFigmaPlan(m, plan);
    const blade = String(
      result.files.find((f) => f.target.includes("screen-one"))?.content,
    );
    expect(blade).toContain('class="fig-img-cover"');
    const css = String(
      result.files.find((f) => f.target.endsWith("figma.css"))?.content,
    );
    expect(css).toContain(
      ".figma-page img.fig-img-cover { object-fit: cover; }",
    );
  });
});

describe("Gap 6 — routes の未使用 import を出力しない", () => {
  it("CMS 非マッピング時は use Modules\\Works が出力されない", () => {
    const canvas: FigmaNode = {
      id: "0:1",
      name: "Page 1",
      type: "CANVAS",
      children: [
        frame("1:1", "Screen One", 1200, 900, [
          text("1:t1", "静的コンテンツのみの画面"),
        ]),
      ],
    };
    const m = manifestFromRoots([canvas]);
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    expect(plan.cms.filter((c) => c.action === "existing-module")).toHaveLength(
      0,
    );
    const result = generateFromFigmaPlan(m, plan);
    const routes = String(
      result.files.find((f) => f.target === "routes/figma.php")?.content,
    );
    expect(routes).not.toContain("Modules\\Works");
    expect(routes).toContain("use Illuminate\\Support\\Facades\\Route;");
  });

  it("CMS マッピング時（synthetic fixture）は use Modules\\Works が出力される", async () => {
    const m = await fixtureManifest();
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    const result = generateFromFigmaPlan(m, plan);
    const routes = String(
      result.files.find((f) => f.target === "routes/figma.php")?.content,
    );
    if (plan.cms.some((c) => c.action === "existing-module")) {
      expect(routes).toContain("use Modules\\Works\\Models\\Work;");
    }
  });
});

describe("Gap 8 — 数字始まり screen_id の route 名（Wayfinder TS 互換）", () => {
  it("route name のみ sanitize され、URL path は変わらない", () => {
    const canvas: FigmaNode = {
      id: "0:1",
      name: "Page 1",
      type: "CANVAS",
      children: [
        frame("1:1", "24h Support", 1200, 900, [text("1:t1", "数字始まりの画面名")]),
      ],
    };
    const m = manifestFromRoots([canvas]);
    const plan = buildFigmaPlan(m, "/nonexistent-project");
    const result = generateFromFigmaPlan(m, plan);
    const routes = String(
      result.files.find((f) => f.target === "routes/figma.php")?.content,
    );
    // URL は screen_id のまま（acceptance route を変えない）
    expect(routes).toContain("Route::view('/24h-support', 'figma.24h-support')");
    // route 名は identifier-safe（Wayfinder が TS helper を生成できる）
    expect(routes).toContain("->name('s-24h-support')");
    expect(routes).not.toContain("->name('24h-support')");
  });
});
