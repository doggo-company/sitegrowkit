/**
 * Design Manifest → Figma Implementation Plan（決定論・AI 不要）。
 * - Existing Component First（SPEC §35: Reuse > Adapt > Generate New）
 * - Existing CSS Token を全面置換しない（SPEC §41: MATCH / CONFLICT / NEW_CANDIDATE）
 * - Blade First（SPEC §61）
 * - 生成先は Project 層のみ、既存 imported サイトとは独立の figma/ 名前空間
 */
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

import type { DesignManifest } from "./manifest.js";
import type {
  ComponentPlan,
  FigmaPlan,
  FontPlan,
  SchemaOverridePlan,
  TokenPlan,
  Uncertainty,
} from "./plan-types.js";

export function buildFigmaPlan(
  manifest: DesignManifest,
  projectRoot: string,
): FigmaPlan {
  const uncertainties: Uncertainty[] = [];

  const screens = planScreens(manifest, uncertainties);
  const components = planComponents(manifest, projectRoot, uncertainties);
  const tokens = planTokens(manifest, projectRoot);
  const cms = planCms(manifest, uncertainties, projectRoot);
  const assets = planAssets(manifest);
  const fonts = planFonts(manifest, projectRoot, uncertainties);
  const schemaOverrides = planSchemaOverrides(manifest, cms);

  for (const t of tokens.filter((t) => t.status === "CONFLICT")) {
    uncertainties.push({
      area: "tokens",
      message: `token "${t.name}" が既存 ${t.existing_name}=${t.existing_value} と競合（Figma: ${t.value}）。既存を書き換えない — 人が確認`,
    });
  }

  return {
    version: 1,
    generated_by: "agentcms figma plan",
    manifest_fingerprint: manifest.source.fingerprint,
    source: {
      file_key: manifest.source.file_key,
      figma_version: manifest.source.figma_version,
    },
    screens,
    components,
    tokens,
    cms,
    assets,
    fonts,
    schema_overrides: schemaOverrides,
    uncertainties,
    warnings: manifest.warnings,
  };
}

function planScreens(
  manifest: DesignManifest,
  uncertainties: Uncertainty[],
): FigmaPlan["screens"] {
  return manifest.screens.map((s) => {
    const missingMobile = s.missing.includes("mobile");
    if (missingMobile) {
      uncertainties.push({
        area: "responsive",
        message: `screen "${s.screen_id}" に Mobile design が無い。Web best practice での responsive 実装は可能だが Figma design ではない（SPEC §26）`,
      });
    }
    return {
      screen_id: s.screen_id,
      route: `/figma/${s.screen_id}`,
      target: `resources/views/figma/${s.screen_id}.blade.php`,
      breakpoints: s.variants.map((v) => ({
        breakpoint: v.breakpoint,
        node_id: v.node_id,
        width: v.width,
      })),
      responsive_note: missingMobile
        ? "MOBILE DESIGN: REVIEW（Figma に Mobile が無い — 勝手にデザインしない）"
        : `Desktop/Mobile pairing: ${s.pairing}`,
      confidence: s.pairing,
      reason:
        s.pairing === "CONFIDENT"
          ? `${s.reason}。route は figma/ 名前空間（既存 imported サイトと衝突しない）`
          : s.reason,
    };
  });
}

/** 既存 Blade component の探索（Existing Component First: SPEC §35） */
export function scanExistingComponents(projectRoot: string): string[] {
  const found: string[] = [];
  const scanDirs = [
    join(projectRoot, "resources", "views", "components"),
    join(projectRoot, "modules"),
  ];
  const walk = (dir: string): void => {
    if (!existsSync(dir)) return;
    for (const entry of readdirSync(dir).sort()) {
      const abs = join(dir, entry);
      if (statSync(abs).isDirectory()) {
        walk(abs);
      } else if (entry.endsWith(".blade.php")) {
        found.push(relative(projectRoot, abs));
      }
    }
  };
  for (const dir of scanDirs) walk(dir);
  return found;
}

function planComponents(
  manifest: DesignManifest,
  projectRoot: string,
  uncertainties: Uncertainty[],
): ComponentPlan[] {
  const existing = scanExistingComponents(projectRoot);
  const basenameOf = (p: string): string =>
    (p.split("/").pop() ?? "").replace(".blade.php", "");

  return manifest.components.map((c) => {
    const base: Omit<
      ComponentPlan,
      "action" | "existing_target" | "target" | "confidence" | "reason"
    > = {
      component_id: c.component_id,
      implementation: "blade",
      classification: c.classification,
    };

    // 1. Reuse: 既存 component と同名（Existing Component First）
    const exact = existing.find((p) => basenameOf(p) === c.component_id);
    if (exact) {
      return {
        ...base,
        action: "reuse-existing" as const,
        existing_target: exact,
        target: null,
        confidence: "REVIEW" as const,
        reason: `既存 component ${exact} と名前が一致。新規生成せず再利用を提案（構造の適合は人が確認）`,
      };
    }

    if (c.classification === "LAYOUT") {
      return {
        ...base,
        action: "layout" as const,
        existing_target: null,
        target: "resources/views/figma/layout.blade.php",
        confidence: c.confidence,
        reason: `${c.reason} — 全 screen 共通レイアウトへ統合`,
      };
    }
    if (c.classification === "STATIC_DECORATION") {
      return {
        ...base,
        action: "skip" as const,
        existing_target: null,
        target: null,
        confidence: c.confidence,
        reason: `${c.reason} — CSS / static asset として扱い component 化しない`,
      };
    }
    if (c.kind === "CANDIDATE") {
      uncertainties.push({
        area: "components",
        message: `"${c.component_id}" は Component 化されていない繰り返し。Component 化するかは人の判断（SPEC §31）`,
      });
      return {
        ...base,
        action: "generate" as const,
        existing_target: null,
        target: `resources/views/figma/components/${c.component_id}.blade.php`,
        confidence: "REVIEW" as const,
        reason: `${c.reason} — 生成はするが partial 扱い（Figma 側の Component 化を推奨）`,
      };
    }
    return {
      ...base,
      action: "generate" as const,
      existing_target: null,
      target: `resources/views/figma/components/${c.component_id}.blade.php`,
      implementation: "blade",
      confidence: c.confidence,
      reason: `${c.reason}。既存 component に一致なし → Blade partial を新規生成（Blade First: SPEC §61）`,
    };
  });
}

/** 既存 CSS custom property の抽出（resources/css/*.css の :root） */
export function scanExistingCssTokens(
  projectRoot: string,
): Map<string, string> {
  const out = new Map<string, string>();
  const cssDir = join(projectRoot, "resources", "css");
  if (!existsSync(cssDir)) return out;
  for (const file of readdirSync(cssDir).sort()) {
    if (!file.endsWith(".css")) continue;
    // app.css は admin（Tailwind theme）の変数定義。Figma import の比較対象は公開サイト CSS のみ
    if (file === "app.css") continue;
    const text = readFileSync(join(cssDir, file), "utf8");
    for (const m of text.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/gi)) {
      const name = m[1] ?? "";
      const value = (m[2] ?? "").trim();
      if (!out.has(name)) out.set(name, value);
    }
  }
  return out;
}

function planTokens(
  manifest: DesignManifest,
  projectRoot: string,
): TokenPlan[] {
  const existing = scanExistingCssTokens(projectRoot);
  const existingByValue = new Map<string, string>();
  for (const [name, value] of existing) {
    if (!existingByValue.has(value.toLowerCase()))
      existingByValue.set(value.toLowerCase(), name);
  }

  const all = [
    ...manifest.tokens.colors,
    ...manifest.tokens.spacing,
    ...manifest.tokens.radius,
    ...manifest.tokens.shadows,
  ];
  return all.map((t) => {
    const existingValue = existing.get(t.name);
    if (existingValue !== undefined) {
      const match = existingValue.toLowerCase() === t.value.toLowerCase();
      return {
        name: t.name,
        value: t.value,
        source: t.source,
        status: match ? ("MATCH" as const) : ("CONFLICT" as const),
        existing_name: t.name,
        existing_value: existingValue,
        confidence: match ? ("CONFIDENT" as const) : ("REVIEW" as const),
        reason: match
          ? `既存 --${t.name} と名前・値が一致 — 既存 token をそのまま使う`
          : `既存 --${t.name}=${existingValue} と名前が同じで値が異なる — 既存を書き換えない（SPEC §41）。figma 名前空間内でのみ Figma 値を使う`,
      };
    }
    const byValue = existingByValue.get(t.value.toLowerCase());
    if (byValue) {
      return {
        name: t.name,
        value: t.value,
        source: t.source,
        status: "MATCH" as const,
        existing_name: byValue,
        existing_value: t.value,
        confidence: "CONFIDENT" as const,
        reason: `既存 --${byValue} と値が一致 — 既存 token の別名として扱える`,
      };
    }
    return {
      name: t.name,
      value: t.value,
      source: t.source,
      status: "NEW_CANDIDATE" as const,
      existing_name: null,
      existing_value: null,
      confidence: t.confidence,
      reason: `既存 token に無い（source: ${t.source}、使用 ${t.usage_count} 回）。figma-tokens.css へ追加候補`,
    };
  });
}

/**
 * プロジェクトが持つ投稿タイプの id（R-13 と同じ門を figma 側へ・B-35）。
 *
 * `MODULE_HINTS` は `works` / `news` を「kit 標準 module」として持っているが、
 * **配布境界はその 2 つを配らない**（`distribution.ts`）。
 * 型が在るかを見ずに `existing-module` と決めると、生成物が
 * `Modules\Works\Models\Work` を書き、利用者の手元では静的解析も実行も落ちる。
 *
 * 読めなければ `null` を返す — **分からないことを「無い」と読み替えない**。
 * 分からないときは今までどおりに扱い、黙って落とすことはしない。
 */
function moduleBackedTypes(projectRoot: string): Set<string> | null {
  const dir = join(projectRoot, "content", "schemas");

  if (!existsSync(dir)) return null;

  const ids = new Set<string>();

  for (const file of readdirSync(dir).sort()) {
    if (!file.endsWith(".json")) continue;

    try {
      const raw = JSON.parse(readFileSync(join(dir, file), "utf8")) as {
        id?: unknown;
        managedBy?: unknown;
      };

      if (typeof raw.id !== "string" || raw.id === "") continue;

      /*
       * **CMS が作った型は、ここでは「在る」に数えない**（B-35 の 2 つ目の顔）。
       *
       * figma の生成器は kit の module のクラス名（`Modules\Works\Models\Work` — 単数）を
       * 直接書き出す。CMS が作った型のクラスは `…\Models\Works`（Studly を 2 度）で**別物**なので、
       * 在るからと `existing-module` に倒すと**存在しないクラスを書く**ことになる。
       *
       * 見た目を保ったまま static で取り込み、載せ替えは人へ渡す。
       */
      if (raw.managedBy === "cms") continue;

      ids.add(raw.id);
    } catch {
      // 壊れた 1 枚で全体を止めない。読めた分だけを「在る」と数える
      continue;
    }
  }

  return ids;
}

function planCms(
  manifest: DesignManifest,
  uncertainties: Uncertainty[],
  projectRoot: string,
): FigmaPlan["cms"] {
  const schemaIds = moduleBackedTypes(projectRoot);

  return manifest.cms_candidates.map((c) => {
    if (c.classification === "EXISTING_MODULE" && c.module_hint) {
      // 受け皿の投稿タイプが無いなら、**型を勝手に作らず static のまま置く**（B-35）
      if (schemaIds !== null && !schemaIds.has(c.module_hint)) {
        uncertainties.push({
          area: "cms",
          message: `"${c.candidate_id}": ${c.reason} — ただし受け皿の module [${c.module_hint}] がこのプロジェクトに無いので、CMS へは載せず見た目のまま静的に取り込む（CMS で作った同名の投稿タイプが在っても、figma の生成器が書けるのは kit の module のクラスだけなので、ここでは静的に扱います）`,
        });

        return {
          candidate_id: c.candidate_id,
          action: "static" as const,
          module: null,
          classification: c.classification,
          seed_items: 0,
          confidence: c.confidence,
          reason: `${c.reason} — 受け皿の投稿タイプ [${c.module_hint}] が無いため static として取り込む（型は勝手に作らない）`,
        };
      }

      return {
        candidate_id: c.candidate_id,
        action: "existing-module" as const,
        module: c.module_hint,
        classification: c.classification,
        seed_items: c.item_count,
        confidence: c.confidence,
        reason: `${c.reason}。既存 ${c.module_hint} module を Runtime Content Source にし、Figma のサンプルは Design Fixture seed として使う（SPEC §45）`,
      };
    }
    if (c.classification === "STATIC") {
      return {
        candidate_id: c.candidate_id,
        action: "static" as const,
        module: null,
        classification: c.classification,
        seed_items: 0,
        confidence: c.confidence,
        reason: c.reason,
      };
    }
    uncertainties.push({
      area: "cms",
      message: `"${c.candidate_id}": ${c.reason}`,
    });
    return {
      candidate_id: c.candidate_id,
      action: "review" as const,
      module: c.module_hint,
      classification: c.classification,
      seed_items: 0,
      confidence: "REVIEW" as const,
      reason: `${c.reason} — apply では static のまま扱い、CMS 化は人の承認後`,
    };
  });
}

function planAssets(manifest: DesignManifest): FigmaPlan["assets"] {
  return manifest.assets.map((a) => {
    if (a.classification === "CONTENT_MEDIA" && a.image_ref) {
      return {
        asset_id: a.asset_id,
        classification: a.classification,
        action: "media-seed" as const,
        target: `database/seeders/figma-import/${a.asset_id}.png`,
        confidence: a.confidence,
        reason: `${a.reason} — seeder 経由で Media Library へ取り込む（CMS 更新対象のみ: SPEC §49）`,
      };
    }
    if (a.kind === "image" && a.image_ref) {
      return {
        asset_id: a.asset_id,
        classification: a.classification,
        action: "static" as const,
        target: `public/figma/images/${a.asset_id}.png`,
        confidence: a.confidence,
        reason: `${a.reason} — 参照される asset のみ取得（Reference-Based: SPEC §51）`,
      };
    }
    // vector（icon / decoration）は CSS 表現に留める（Image API render の追加 call を避ける）。
    // export target は追跡のため常に決定論で示す（承認後の SVG export 先 — Gap 2: asset identity 追跡）
    return {
      asset_id: a.asset_id,
      classification: a.classification,
      action: "css-only" as const,
      target: `public/figma/svg/${a.asset_id}.svg`,
      confidence: "REVIEW" as const,
      reason: `${a.reason} — vector は CSS プレースホルダで表現（SVG export は追加 API call のため人の承認後。承認後の export 先 = target）`,
    };
  });
}

function planFonts(
  manifest: DesignManifest,
  projectRoot: string,
  uncertainties: Uncertainty[],
): FontPlan[] {
  const cssText = readAllCss(projectRoot);
  return manifest.fonts.map((f) => {
    const available = cssText.includes(f.family);
    if (!available) {
      uncertainties.push({
        area: "fonts",
        message: `FONT MISSING: "${f.family}"（weights: ${f.weights.join(",")}）がプロジェクトに無い。似た font への置換はしない — 人が font 導入可否を判断（SPEC §54）`,
      });
    }
    return {
      family: f.family,
      weights: f.weights,
      availability: available ? ("PROJECT" as const) : ("MISSING" as const),
      action: available ? ("use" as const) : ("review" as const),
      confidence: available ? ("CONFIDENT" as const) : ("REVIEW" as const),
      reason: available
        ? "プロジェクト CSS に定義済み"
        : "FONT MISSING → REVIEW。fallback は既存 font stack を使う（勝手に類似 font へ置換しない）",
    };
  });
}

function planSchemaOverrides(
  manifest: DesignManifest,
  cms: FigmaPlan["cms"],
): SchemaOverridePlan[] {
  const overrides: SchemaOverridePlan[] = [];
  for (const c of cms) {
    if (c.action !== "existing-module" || !c.module) continue;
    const candidate = manifest.cms_candidates.find(
      (m) => m.candidate_id === c.candidate_id,
    );
    const fieldNames = new Set(candidate?.fields.map((f) => f.name) ?? []);
    // Figma design に本文が無い → body を必須解除（内容を捏造しない。ADR-0010 Rev.1 の Override を再利用）
    if (
      c.module === "works" &&
      !fieldNames.has("body") &&
      !fieldNames.has("excerpt")
    ) {
      overrides.push({
        schema: "works",
        field: "body",
        patch: { required: false },
        reason:
          "Figma design に本文が存在しない。内容を捏造せず、Project Scope Schema Override で必須解除（SPEC §46/§63）",
      });
    }
  }
  return overrides;
}

function readAllCss(projectRoot: string): string {
  const cssDir = join(projectRoot, "resources", "css");
  if (!existsSync(cssDir)) return "";
  return readdirSync(cssDir)
    .sort()
    .filter((f) => f.endsWith(".css"))
    .map((f) => readFileSync(join(cssDir, f), "utf8"))
    .join("\n");
}
