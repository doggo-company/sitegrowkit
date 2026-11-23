/**
 * agentcms figma diff（SPEC §67–69）。
 * 保存済み Design Manifest と、現在の Figma（cache 経由）から再生成した Manifest の
 * Semantic Diff を表示する。Code へは一切適用しない（No Auto-Reapply）。
 */
import { existsSync, readFileSync } from "node:fs";

import { findAgentcmsDir } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import { loadFigmaConfig } from "../figma/config.js";
import { diffManifests } from "../figma/diff.js";
import { fetchDesignData } from "../figma/fetch.js";
import type { DesignManifest } from "../figma/manifest.js";
import { buildDesignManifest } from "../figma/normalize.js";
import { resolveFigmaApi } from "../figma/provider.js";
import { figmaManifestPath } from "./figma-inspect.js";

export async function figmaDiff(): Promise<number> {
  const agentcmsDir = findAgentcmsDir();
  if (!agentcmsDir) {
    fail({
      what: "figma diff を実行できません",
      why: ".agentcms/ が見つかりません",
      fix: "SiteGrowKit のプロジェクト内で実行してください",
    });
    return 1;
  }
  const manifestPath = figmaManifestPath(agentcmsDir);
  if (!existsSync(manifestPath)) {
    fail({
      what: "比較元の Design Manifest がありません",
      why: `${manifestPath} が存在しません`,
      fix: "先に agentcms figma inspect を実行してください",
    });
    return 1;
  }
  const previous = JSON.parse(
    readFileSync(manifestPath, "utf8"),
  ) as DesignManifest;

  const { config, error } = loadFigmaConfig(agentcmsDir);
  if (!config) {
    fail(
      error ?? { what: "config error", why: "unknown", fix: "figma connect" },
    );
    return 1;
  }
  const apiRes = resolveFigmaApi();
  if ("error" in apiRes) {
    fail(apiRes.error);
    return 1;
  }
  const fetched = await fetchDesignData(apiRes.api, agentcmsDir, config);
  if ("error" in fetched) {
    fail(fetched.error);
    return 1;
  }
  const next = buildDesignManifest(fetched.data.input);
  const diff = diffManifests(previous, next);

  heading("Semantic Figma Diff");
  info(`version: ${diff.from_version} → ${diff.to_version}`);
  if (!diff.changed) {
    ok("変更なし（Manifest は最新です）");
    return 0;
  }
  for (const e of diff.entries) {
    const line = `${e.kind.padEnd(7)} ${e.area.padEnd(9)} ${e.id}  ${e.detail}`;
    if (e.kind === "REMOVED") warn(line);
    else info(line);
  }
  if (diff.entries.length === 0) {
    info(
      "項目レベルの差分はありませんが fingerprint が変わっています（並び・内部変更）",
    );
  }
  warn(
    "Figma が更新されています。反映する場合: figma inspect → figma plan → 人の承認 → figma apply（自動では適用しません: SPEC §69）",
  );
  return 0;
}
