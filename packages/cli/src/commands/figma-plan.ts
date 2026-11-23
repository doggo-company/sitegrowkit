/**
 * agentcms figma plan（SPEC §56–59）。
 * Design Manifest（figma.json）のみから決定論的に Plan を生成する（API call なし）。
 * 出力: .agentcms/manifest/figma-plan.json + docs/figma-implementation-plan.md
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import type { DesignManifest } from "../figma/manifest.js";
import { buildFigmaPlan } from "../figma/plan.js";
import { renderFigmaPlanMarkdown } from "../figma/plan-md.js";
import {
  loadRegistry,
  saveRegistry,
  upsertRegistryEntry,
} from "../figma/registry.js";
import { figmaManifestPath } from "./figma-inspect.js";

export function figmaPlanPath(agentcmsDir: string): string {
  return join(agentcmsDir, "manifest", "figma-plan.json");
}

export async function figmaPlan(): Promise<number> {
  const agentcmsDir = findAgentcmsDir();
  const projectRoot = findProjectRoot();
  if (!agentcmsDir || !projectRoot) {
    fail({
      what: "figma plan を実行できません",
      why: ".agentcms/ またはプロジェクトルートが見つかりません",
      fix: "SiteGrowKit のプロジェクト内で実行してください",
    });
    return 1;
  }
  const manifestPath = figmaManifestPath(agentcmsDir);
  if (!existsSync(manifestPath)) {
    fail({
      what: "Design Manifest がありません",
      why: `${manifestPath} が存在しません`,
      fix: "先に agentcms figma inspect を実行してください",
    });
    return 1;
  }
  const manifest = JSON.parse(
    readFileSync(manifestPath, "utf8"),
  ) as DesignManifest;
  if (manifest.version !== 1) {
    fail({
      what: "Design Manifest の version が不正です",
      why: `version ${String(manifest.version)} は未対応`,
      fix: "agentcms figma inspect を再実行してください",
    });
    return 1;
  }

  const plan = buildFigmaPlan(manifest, projectRoot);

  const planPath = figmaPlanPath(agentcmsDir);
  mkdirSync(dirname(planPath), { recursive: true });
  writeFileSync(planPath, `${JSON.stringify(plan, null, 2)}\n`, "utf8");

  const mdPath = join(
    dirname(agentcmsDir),
    "docs",
    "figma-implementation-plan.md",
  );
  mkdirSync(dirname(mdPath), { recursive: true });
  writeFileSync(mdPath, renderFigmaPlanMarkdown(plan), "utf8");

  // Component Registry へ proposed を反映（mapped は退行させない）
  const registry = loadRegistry(agentcmsDir);
  for (const c of plan.components) {
    const component = manifest.components.find(
      (m) => m.component_id === c.component_id,
    );
    if (!component) continue;
    const target = c.existing_target ?? c.target;
    upsertRegistryEntry(
      registry,
      component,
      { type: target ? "blade" : null, target },
      c.action === "skip" ? "review" : "proposed",
    );
  }
  const registryFile = saveRegistry(agentcmsDir, registry);

  heading("Figma Implementation Plan");
  info(
    `screens: ${plan.screens.length} / components: ${plan.components.length} / tokens: ${plan.tokens.length} / cms: ${plan.cms.length} / assets: ${plan.assets.length}`,
  );
  const reuse = plan.components.filter(
    (c) => c.action === "reuse-existing",
  ).length;
  const generate = plan.components.filter(
    (c) => c.action === "generate",
  ).length;
  info(
    `components: reuse ${reuse} / generate ${generate}（Existing Component First）`,
  );
  const conflicts = plan.tokens.filter((t) => t.status === "CONFLICT");
  if (conflicts.length > 0) {
    warn(
      `token CONFLICT: ${conflicts.map((t) => t.name).join(", ")}（既存は書き換えない）`,
    );
  }
  for (const u of plan.uncertainties) {
    warn(`[${u.area}] ${u.message}`);
  }
  ok(`Plan: ${planPath}`);
  ok(`Review 用: ${mdPath}`);
  ok(`Component Registry: ${registryFile}`);
  info("内容を確認・承認後: agentcms figma apply");
  return 0;
}
