/**
 * Deterministic Normalizer（SPEC §19–23、ADR-0011 §5）。
 * Raw Figma JSON → Design Manifest。同一の Figma version + node selection からは
 * バイト同一の Manifest を生成する（AI・時刻・乱数を使わない）。
 */
import { createHash } from "node:crypto";

import type {
  FigmaComponentMeta,
  FigmaFileMeta,
  FigmaNode,
  FigmaStyleMeta,
  FigmaVariablesResponse,
} from "./api.js";
import { detectCmsCandidates } from "./cms.js";
import { buildComponentIdMap, detectComponents } from "./components.js";
import { extractAssets } from "./assets.js";
import type { Capability, DesignManifest, FigmaWarning } from "./manifest.js";
import { countNodes, slugify } from "./normalize-util.js";
import { collectScreenFrames, detectScreens } from "./screens.js";
import { extractTokens } from "./tokens.js";
import type { TreeContext } from "./tree.js";

export interface NormalizeInput {
  fileKey: string;
  meta: FigmaFileMeta;
  /** 取得済み root node（selected nodes または file 全体の document） */
  roots: FigmaNode[];
  componentsMeta: Record<string, FigmaComponentMeta>;
  componentSetsMeta: Record<string, FigmaComponentMeta>;
  stylesMeta: Record<string, FigmaStyleMeta>;
  /** sorted。空 = file 全体 */
  selectedNodes: string[];
  /** Variables API を要求したか（Standard Mode の既定は false = NOT REQUESTED） */
  variablesRequested: boolean;
  variables: FigmaVariablesResponse | null;
  /** Variables が使えない理由（403 等）。null = 利用可能 or 未要求 */
  variablesUnavailableReason: string | null;
  rawBytes: number;
}

export function buildDesignManifest(input: NormalizeInput): DesignManifest {
  const warnings: FigmaWarning[] = [];

  // 1. tokens（Variable > Style > Inferred: SPEC §38）
  const tokenExtraction = extractTokens(
    input.roots,
    input.stylesMeta,
    input.variables,
  );

  // 2. CMS candidates（screen frame 内の繰り返し構造から）
  const screenFrames = collectScreenFrames(input.roots).map((f) => ({
    screen_id: slugify(f.base),
    root: f.node,
  }));
  const cms = detectCmsCandidates(screenFrames);

  // 3. assets（CMS 内画像 → CONTENT_MEDIA。hash 重複排除）
  const assetExtraction = extractAssets(input.roots, cms.cmsImageNodeIds);

  // 4. element tree 構築 context
  const ctx: TreeContext = {
    colorTokenByValue: tokenExtraction.colorTokenByValue,
    typoTokenByKey: tokenExtraction.typoTokenByKey,
    assetIdByImageRef: assetExtraction.assetIdByImageRef,
    assetIdByNodeId: assetExtraction.assetIdByNodeId,
    componentIdMap: buildComponentIdMap(
      input.roots,
      input.componentsMeta,
      input.componentSetsMeta,
    ),
    warnings,
  };

  // 5. components / screens
  const componentDetection = detectComponents(
    input.roots,
    input.componentsMeta,
    input.componentSetsMeta,
    cms,
    ctx,
  );
  const screens = detectScreens(input.roots, ctx, warnings);

  // 6. stats
  let total = 0;
  let hidden = 0;
  for (const root of input.roots) {
    const c = countNodes(root);
    total += c.total;
    hidden += c.hidden;
  }

  const capabilities: Capability[] = [
    { name: "file_metadata", state: "PASS", detail: "GET /v1/files/:key/meta" },
    { name: "file_content", state: "PASS", detail: "GET /v1/files/:key/nodes" },
    input.variables
      ? {
          name: "variables",
          state: "PASS",
          detail: "Variables API（Enterprise）を Token source として使用",
        }
      : input.variablesRequested
        ? {
            name: "variables",
            state: "UNAVAILABLE",
            detail:
              input.variablesUnavailableReason ??
              "未確認（Style / Inferred fallback を使用）",
          }
        : {
            name: "variables",
            state: "OPTIONAL",
            detail:
              "NOT REQUESTED — Standard Mode は file_metadata:read + file_content:read のみ使用（Variables は Advanced Capability。--variables で要求可能）",
          },
    {
      name: "library",
      state: "OPTIONAL",
      detail: `published component key: ${
        Object.values(input.componentsMeta).filter((c) => c.key).length
      } 件（file response 由来。library endpoints は未使用）`,
    },
    {
      name: "code_connect",
      state: "OPTIONAL",
      detail: "未使用（Org/Enterprise + Dev seat 限定。publish は行わない）",
    },
    {
      name: "mcp",
      state: "OPTIONAL",
      detail: "Assist Mode のみ（Standard Mode は REST。write 系ツールは禁止）",
    },
  ];

  const dedupedWarnings = dedupeWarnings(warnings);

  const manifest: DesignManifest = {
    version: 1,
    generated_by: "agentcms figma inspect",
    source: {
      file_key: input.fileKey,
      file_name: input.meta.name,
      figma_version: input.meta.version,
      editor_type: input.meta.editorType,
      selected_nodes: [...input.selectedNodes].sort(),
      fingerprint: "", // 下で確定
    },
    capabilities,
    stats: {
      raw_bytes: input.rawBytes,
      raw_nodes: total,
      skipped_hidden_nodes: hidden,
    },
    screens,
    components: componentDetection.components,
    tokens: tokenExtraction.tokens,
    assets: assetExtraction.assets,
    fonts: tokenExtraction.fonts,
    cms_candidates: cms.candidates,
    warnings: dedupedWarnings,
  };

  manifest.source.fingerprint = manifestFingerprint(manifest);
  return manifest;
}

/** figma version + selection + 正規化済み内容の sha256（SPEC §23） */
export function manifestFingerprint(manifest: DesignManifest): string {
  const content = JSON.stringify({
    figma_version: manifest.source.figma_version,
    selected_nodes: manifest.source.selected_nodes,
    screens: manifest.screens,
    components: manifest.components,
    tokens: manifest.tokens,
    assets: manifest.assets,
    fonts: manifest.fonts,
    cms_candidates: manifest.cms_candidates,
  });
  return createHash("sha256").update(content).digest("hex");
}

function dedupeWarnings(warnings: FigmaWarning[]): FigmaWarning[] {
  const seen = new Set<string>();
  const out: FigmaWarning[] = [];
  for (const w of warnings) {
    const key = `${w.code}:${w.node_id ?? ""}:${w.message}`;
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(w);
  }
  return out.sort(
    (a, b) =>
      a.code.localeCompare(b.code) ||
      (a.node_id ?? "").localeCompare(b.node_id ?? "") ||
      a.message.localeCompare(b.message),
  );
}
