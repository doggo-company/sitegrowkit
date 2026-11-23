/**
 * Figma Raw Cache（SPEC §14–15、ADR-0011 §4）。
 * - .agentcms/cache/figma/<file_key>/ に Raw API response を保存（gitignore 対象）
 * - file version + node selection で識別。Metadata First: version 一致なら Tier 1 call を出さない
 * - Raw Cache ≠ AI Context。AI は原則 Design Manifest（figma.json）を読む
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

import type { FigmaFileMeta, FigmaNodesResponse } from "./api.js";

export function figmaCacheDir(agentcmsDir: string, fileKey: string): string {
  return join(agentcmsDir, "cache", "figma", fileKey);
}

/** node selection の識別子（sorted ids の sha256 先頭 12 桁。空 = file 全体） */
export function selectionHash(nodeIds: string[]): string {
  if (nodeIds.length === 0) return "full";
  return createHash("sha256")
    .update([...nodeIds].sort().join(","))
    .digest("hex")
    .slice(0, 12);
}

function nodesCachePath(
  dir: string,
  version: string,
  selection: string,
): string {
  return join(dir, `nodes-v${version}-${selection}.json`);
}

export interface CachedFetch {
  response: FigmaNodesResponse;
  /** true = Raw Cache から供給（Tier 1 API call なし） */
  cacheHit: boolean;
}

export function loadCachedNodes(
  agentcmsDir: string,
  fileKey: string,
  version: string,
  nodeIds: string[],
): FigmaNodesResponse | null {
  const path = nodesCachePath(
    figmaCacheDir(agentcmsDir, fileKey),
    version,
    selectionHash(nodeIds),
  );
  if (!existsSync(path)) return null;
  try {
    return JSON.parse(readFileSync(path, "utf8")) as FigmaNodesResponse;
  } catch {
    return null; // 壊れた cache は miss 扱い（再取得で回復）
  }
}

export function saveCachedNodes(
  agentcmsDir: string,
  fileKey: string,
  version: string,
  nodeIds: string[],
  response: FigmaNodesResponse,
): string {
  const path = nodesCachePath(
    figmaCacheDir(agentcmsDir, fileKey),
    version,
    selectionHash(nodeIds),
  );
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, `${JSON.stringify(response)}\n`, "utf8");
  return path;
}

/** 最後に確認した file metadata（cache 世代の判断根拠として保存） */
export function saveCachedMeta(
  agentcmsDir: string,
  fileKey: string,
  meta: FigmaFileMeta,
): void {
  const dir = figmaCacheDir(agentcmsDir, fileKey);
  mkdirSync(dir, { recursive: true });
  writeFileSync(
    join(dir, "meta.json"),
    `${JSON.stringify(meta, null, 2)}\n`,
    "utf8",
  );
}

export function loadCachedMeta(
  agentcmsDir: string,
  fileKey: string,
): FigmaFileMeta | null {
  const path = join(figmaCacheDir(agentcmsDir, fileKey), "meta.json");
  if (!existsSync(path)) return null;
  try {
    return JSON.parse(readFileSync(path, "utf8")) as FigmaFileMeta;
  } catch {
    return null;
  }
}

/** Visual Baseline render の保存先（gitignore: Client Design Data） */
export function renderCachePath(
  agentcmsDir: string,
  fileKey: string,
  nodeId: string,
  version: string,
  format: "png" | "svg",
): string {
  return join(
    figmaCacheDir(agentcmsDir, fileKey),
    "renders",
    `${nodeId.replace(/[:;]/g, "-")}-v${version}.${format}`,
  );
}
