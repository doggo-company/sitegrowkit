/**
 * Figma inspect（Normalizer）のテスト。
 * Synthetic Fixture（Northwind Studio）→ Design Manifest の決定論・検出内容を検証する。
 * SPEC §100: 7 (Manifest generation) / 8 (deterministic) / 10 (pairing) / 11 (Auto Layout)
 * 12–13 (component/variant) / 16 (token) / 17 (Variables fallback) / 18–20 (asset/CMS) / 37 (redaction)
 */
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import type {
  FigmaFileMeta,
  FigmaFileResponse,
  FigmaVariablesResponse,
} from "../src/figma/api.js";
import { FixtureFigmaApi } from "../src/figma/api-fixture.js";
import {
  buildDesignManifest,
  manifestFingerprint,
  type NormalizeInput,
} from "../src/figma/normalize.js";

const FIXTURE = resolve(__dirname, "fixtures/figma");

async function loadInput(
  overrides: Partial<NormalizeInput> = {},
): Promise<NormalizeInput> {
  const api = new FixtureFigmaApi(FIXTURE);
  const metaRes = await api.getFileMeta("FKEYFIXTURE0001");
  const fileRes = await api.getFile("FKEYFIXTURE0001");
  if (!metaRes.ok || !fileRes.ok) throw new Error("fixture load failed");
  const meta: FigmaFileMeta = metaRes.data;
  const file: FigmaFileResponse = fileRes.data;
  return {
    fileKey: "FKEYFIXTURE0001",
    meta,
    roots: [file.document],
    componentsMeta: file.components,
    componentSetsMeta: file.componentSets,
    stylesMeta: file.styles,
    selectedNodes: [],
    variablesRequested: true,
    variables: null,
    variablesUnavailableReason: "plan 制限（Enterprise 限定）: fixture",
    rawBytes: 242025,
    ...overrides,
  };
}

describe("figma inspect — Design Manifest", () => {
  it("決定論: 同一入力から同一 Manifest（fingerprint 含めバイト同一）", async () => {
    const a = buildDesignManifest(await loadInput());
    const b = buildDesignManifest(await loadInput());
    expect(JSON.stringify(a)).toBe(JSON.stringify(b));
    expect(a.source.fingerprint).toHaveLength(64);
  });

  it("screen 検出と Desktop/Mobile pairing（CONFIDENT）", async () => {
    const m = buildDesignManifest(await loadInput());
    expect(m.screens.map((s) => s.screen_id).sort()).toEqual(["home", "works"]);
    const home = m.screens.find((s) => s.screen_id === "home");
    expect(home?.pairing).toBe("CONFIDENT");
    expect(home?.variants.map((v) => v.breakpoint)).toEqual([
      "desktop",
      "mobile",
    ]);
    expect(home?.variants[0]?.width).toBe(1440);
    expect(home?.variants[1]?.width).toBe(375);
    expect(home?.missing).toEqual([]);
  });

  it("Auto Layout の正規化（flex-column / flex-row / grid-candidate）", async () => {
    const m = buildDesignManifest(await loadInput());
    const desktop = m.screens.find((s) => s.screen_id === "home")?.variants[0];
    expect(desktop?.tree.layout).toBe("flex-column");
    const hero = desktop?.tree.children?.find((c) => c.name === "Hero");
    expect(hero?.layout).toBe("flex-row");
    const works = desktop?.tree.children?.find((c) => c.name === "Works");
    const grid = works?.children?.find((c) => c.name === "Works Grid");
    expect(grid?.layout).toBe("grid-candidate"); // HORIZONTAL + WRAP
  });

  it("component 検出・variant・分類", async () => {
    const m = buildDesignManifest(await loadInput());
    const ids = m.components.map((c) => c.component_id);
    expect(ids).toContain("header");
    expect(ids).toContain("footer");
    expect(ids).toContain("button");
    expect(ids).toContain("works-card");

    const button = m.components.find((c) => c.component_id === "button");
    expect(button?.kind).toBe("COMPONENT_SET");
    expect(button?.classification).toBe("UI_COMPONENT");
    expect(button?.variants.map((v) => v.properties.Type)).toEqual([
      "Primary",
      "Secondary",
    ]);
    expect(button?.component_key).toBe("key-button-set");

    const header = m.components.find((c) => c.component_id === "header");
    expect(header?.classification).toBe("LAYOUT");

    const card = m.components.find((c) => c.component_id === "works-card");
    expect(card?.classification).toBe("CMS_COMPONENT");
    expect(card?.instance_count).toBeGreaterThanOrEqual(12); // 3+3+6+6 instances

    // Component 化されていない繰り返し → CANDIDATE（勝手に Component 化しない）
    const feature = m.components.find((c) => c.component_id === "feature");
    expect(feature?.kind).toBe("CANDIDATE");
    expect(feature?.confidence).toBe("REVIEW");
  });

  it("token 抽出: STYLE > INFERRED、Variables fallback（SPEC §37–38）", async () => {
    const m = buildDesignManifest(await loadInput());
    const accent = m.tokens.colors.find((t) => t.name === "color-accent");
    expect(accent?.source).toBe("STYLE");
    expect(accent?.value).toBe("#0f4c46");
    expect(accent?.confidence).toBe("CONFIDENT");

    const muted = m.tokens.colors.find((t) => t.value === "#6b6f76");
    expect(muted?.source).toBe("INFERRED");
    expect(muted?.confidence).toBe("REVIEW");

    const heading = m.tokens.typography.find(
      (t) => t.name === "type-heading-m",
    );
    expect(heading?.source).toBe("STYLE");
    expect(heading?.size).toBe(32);

    expect(m.tokens.spacing.some((t) => t.name === "space-24")).toBe(true);
    expect(m.tokens.radius.some((t) => t.value === "12px")).toBe(true);
    expect(m.tokens.shadows.length).toBeGreaterThanOrEqual(1);

    // Variables 不可 → capability UNAVAILABLE（Phase 6 の失敗にはしない: SPEC §87）
    const cap = m.capabilities.find((c) => c.name === "variables");
    expect(cap?.state).toBe("UNAVAILABLE");
  });

  it("Standard Mode 既定: Variables は NOT REQUESTED（API call を消費しない）", async () => {
    const m = buildDesignManifest(
      await loadInput({
        variablesRequested: false,
        variablesUnavailableReason: null,
      }),
    );
    const cap = m.capabilities.find((c) => c.name === "variables");
    expect(cap?.state).toBe("OPTIONAL");
    expect(cap?.detail).toContain("NOT REQUESTED");
    // fallback（STYLE / INFERRED）は要求時と同一の token を生成する
    expect(m.tokens.colors.find((t) => t.name === "color-accent")?.source).toBe(
      "STYLE",
    );
  });

  it("Variables が利用可能なら VARIABLE source が最優先（SPEC §38）", async () => {
    const variables: FigmaVariablesResponse = {
      meta: {
        variableCollections: {
          "VC:1": {
            id: "VC:1",
            name: "Primitives",
            defaultModeId: "M:1",
            modes: [{ modeId: "M:1", name: "Default" }],
          },
        },
        variables: {
          "V:1": {
            id: "V:1",
            name: "Brand/Primary",
            resolvedType: "COLOR",
            variableCollectionId: "VC:1",
            valuesByMode: {
              "M:1": { r: 15 / 255, g: 76 / 255, b: 70 / 255, a: 1 },
            },
          },
        },
      },
    };
    const m = buildDesignManifest(
      await loadInput({ variables, variablesUnavailableReason: null }),
    );
    const token = m.tokens.colors.find((t) => t.value === "#0f4c46");
    expect(token?.source).toBe("VARIABLE"); // STYLE より優先
    expect(token?.name).toBe("brand-primary");
    expect(m.capabilities.find((c) => c.name === "variables")?.state).toBe(
      "PASS",
    );
  });

  it("asset 分類: CONTENT_MEDIA / ICON / DECORATION / STATIC（SPEC §48–50）", async () => {
    const m = buildDesignManifest(await loadInput());
    const byRef = (ref: string) => m.assets.find((a) => a.image_ref === ref);
    expect(byRef("imgref-w1")?.classification).toBe("CONTENT_MEDIA");
    expect(byRef("imgref-w6")?.classification).toBe("CONTENT_MEDIA"); // Works page のみの画像も対象
    expect(byRef("imgref-hero")?.classification).toBe("STATIC_DESIGN_ASSET");

    const logo = m.assets.find((a) => a.name === "Logo");
    expect(logo?.kind).toBe("vector");
    expect(logo?.classification).toBe("STATIC_DESIGN_ASSET");

    const blob = m.assets.find((a) => a.name === "Deco Blob");
    expect(blob?.classification).toBe("DECORATION");

    const icon = m.assets.find((a) => a.name === "Icon Spark");
    expect(icon?.classification).toBe("ICON");

    // 同一 imageRef は 1 asset（重複排除: SPEC §52）
    const cardThumb = byRef("imgref-card");
    expect(cardThumb?.node_ids.length).toBeGreaterThanOrEqual(1);
    const refs = m.assets.filter((a) => a.image_ref).map((a) => a.image_ref);
    expect(new Set(refs).size).toBe(refs.length);
  });

  it("CMS candidate 検出: works は EXISTING_MODULE、feature は REVIEW（SPEC §42–43）", async () => {
    const m = buildDesignManifest(await loadInput());
    const works = m.cms_candidates.find(
      (c) => c.candidate_id === "works-repeat",
    );
    expect(works?.classification).toBe("EXISTING_MODULE");
    expect(works?.module_hint).toBe("works");
    expect(works?.item_count).toBe(3);
    const fieldNames = works?.fields.map((f) => f.name);
    expect(fieldNames).toContain("title");
    expect(fieldNames).toContain("date");
    expect(fieldNames).toContain("image");
    // Never Invent Content: サンプルは Figma の実テキストのみ
    const title = works?.fields.find((f) => f.name === "title");
    expect(title?.samples).toEqual(["WORKS A", "WORKS B", "WORKS C"]);

    const feature = m.cms_candidates.find(
      (c) => c.candidate_id === "feature-repeat",
    );
    expect(feature?.classification).toBe("REVIEW");
    expect(feature?.confidence).toBe("REVIEW");

    // nav リンクの繰り返しは candidate にならない
    expect(m.cms_candidates.some((c) => c.candidate_id.startsWith("nav"))).toBe(
      false,
    );
  });

  it("フォント検出と Manifest First 統計", async () => {
    const m = buildDesignManifest(await loadInput());
    const families = m.fonts.map((f) => f.family);
    expect(families).toContain("Inter");
    expect(families).toContain("Clash Display");
    expect(m.stats.raw_nodes).toBeGreaterThan(200);
    const manifestBytes = Buffer.byteLength(JSON.stringify(m, null, 2));
    expect(manifestBytes).toBeLessThan(m.stats.raw_bytes / 2); // Raw より大幅に小さい（SPEC §21）
  });

  it("secret redaction: credential らしき text は Manifest へ複製しない（SPEC §91）", async () => {
    const input = await loadInput();
    const canvas = input.roots[0]?.children?.[0];
    const home = canvas?.children?.[0];
    home?.children?.push({
      id: "99:1",
      name: "Leaked Note",
      type: "TEXT",
      characters: "api_key = figd_UNSAFE1234567890abcdef",
      style: { fontFamily: "Inter", fontWeight: 400, fontSize: 12 },
    });
    const m = buildDesignManifest(input);
    const json = JSON.stringify(m);
    expect(json).not.toContain("figd_UNSAFE");
    expect(json).toContain("[REDACTED]");
    expect(m.warnings.some((w) => w.code === "SECRET_REDACTED")).toBe(true);
  });

  it("stale / 改ざん Manifest の検出: fingerprint 再計算（SPEC §100 #9）", async () => {
    const m = buildDesignManifest(await loadInput());
    const stored = m.source.fingerprint;
    expect(manifestFingerprint(m)).toBe(stored);
    const screen = m.screens[0];
    if (screen) screen.name = "tampered";
    expect(manifestFingerprint(m)).not.toBe(stored);
  });

  it("Manifest snapshot（形式変更の検出）", async () => {
    const m = buildDesignManifest(await loadInput());
    // 全体 snapshot はサイズが大きいため、構造の要約を snapshot する
    expect({
      screens: m.screens.map((s) => ({
        id: s.screen_id,
        pairing: s.pairing,
        variants: s.variants.map((v) => `${v.breakpoint}:${v.width}`),
      })),
      components: m.components.map(
        (c) => `${c.component_id}:${c.kind}:${c.classification}`,
      ),
      tokens: {
        colors: m.tokens.colors.map((t) => `${t.name}=${t.value}(${t.source})`),
        typography: m.tokens.typography.map((t) => t.name),
        spacing: m.tokens.spacing.map((t) => t.name),
        radius: m.tokens.radius.map((t) => t.name),
      },
      assets: m.assets.map((a) => `${a.asset_id}:${a.classification}`),
      cms: m.cms_candidates.map((c) => `${c.candidate_id}:${c.classification}`),
      warnings: m.warnings.map((w) => w.code),
    }).toMatchSnapshot();
  });
});
