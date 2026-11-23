/**
 * Figma generate の回帰テスト。
 * Live Acceptance で実測した一般化不足の再発防止:
 * - "home" screen が存在しない実案件で layout の logo リンクが未定義 route を参照して 500
 */
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { FixtureFigmaApi } from "../src/figma/api-fixture.js";
import { generateFromFigmaPlan } from "../src/figma/generate.js";
import type { DesignManifest } from "../src/figma/manifest.js";
import { buildDesignManifest } from "../src/figma/normalize.js";
import { buildFigmaPlan } from "../src/figma/plan.js";

const FIXTURE = resolve(__dirname, "fixtures/figma");

async function manifestFromFixture(): Promise<DesignManifest> {
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

describe("figma generate — Live Acceptance 回帰（画像 loading / mobile clamp）", () => {
  it("screen 最初の画像は eager、以降は lazy（機械的 lazy 付与の禁止 — Phase 2 仕様）", async () => {
    const manifest = await manifestFromFixture();
    const plan = buildFigmaPlan(manifest, "/nonexistent-project");
    const result = generateFromFigmaPlan(manifest, plan);
    const home = String(
      result.files.find((f) => f.target.endsWith("figma/home.blade.php"))
        ?.content,
    );
    const first = home.indexOf('loading="eager"');
    expect(first).toBeGreaterThan(-1);
    // eager は 1 画面 1 回のみ（最初の画像 = ファーストビュー想定）
    expect(home.indexOf('loading="eager"', first + 1)).toBe(-1);
  });

  it("screen root / section の背景 token とテキスト色 token をクラスへ反映する", async () => {
    const manifest = await manifestFromFixture();
    const plan = buildFigmaPlan(manifest, "/nonexistent-project");
    const result = generateFromFigmaPlan(manifest, plan);
    const home = String(
      result.files.find((f) => f.target.endsWith("figma/home.blade.php"))
        ?.content,
    );
    // fixture: 白背景 root + accent 背景の CTA + 白文字の CTA title
    expect(home).toMatch(/fig-screen fig-bg-color-/);
    expect(home).toMatch(/fig-sec-cta fig-bg-color-accent/);
    expect(home).toMatch(/fig-c-color-/);
    const css = String(
      result.files.find((f) => f.target.endsWith("figma.css"))?.content,
    );
    expect(css).toContain(
      ".figma-page .fig-c-color-accent { color: var(--fig-color-accent); }",
    );
  });

  it("40px 超の display typography は mobile で viewport クランプされる", async () => {
    const manifest = await manifestFromFixture();
    const plan = buildFigmaPlan(manifest, "/nonexistent-project");
    const result = generateFromFigmaPlan(manifest, plan);
    const css = String(
      result.files.find((f) => f.target.endsWith("figma.css"))?.content,
    );
    // fixture の hero 見出しは 48px → mobile fallback で min(48px, 11vw)
    expect(css).toMatch(
      /@media \(max-width: 768px\) \{[\s\S]*min\(48px, 11vw\)/,
    );
  });
});

describe("figma generate — layout の screen 非依存性", () => {
  it("home screen がある場合: logo は figma.home へ", async () => {
    const manifest = await manifestFromFixture();
    const plan = buildFigmaPlan(manifest, "/nonexistent-project");
    const result = generateFromFigmaPlan(manifest, plan);
    const layout = result.files.find((f) =>
      f.target.endsWith("figma/layout.blade.php"),
    );
    expect(String(layout?.content)).toContain("route('figma.home')");
  });

  it("home screen が無い実案件でも未定義 route を参照しない（Live Acceptance 回帰）", async () => {
    const manifest = await manifestFromFixture();
    // "home" を除外し works のみの実案件形状を再現する
    manifest.screens = manifest.screens.filter((s) => s.screen_id !== "home");
    const plan = buildFigmaPlan(manifest, "/nonexistent-project");
    expect(plan.screens.map((s) => s.screen_id)).toEqual(["works"]);
    const result = generateFromFigmaPlan(manifest, plan);
    const layout = String(
      result.files.find((f) => f.target.endsWith("figma/layout.blade.php"))
        ?.content,
    );
    expect(layout).not.toContain("figma.home");
    expect(layout).toContain("route('figma.works')"); // 先頭 screen へフォールバック
  });
});
