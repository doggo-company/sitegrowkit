/**
 * Component 検出・分類（SPEC §31–32）。
 * - COMPONENT / COMPONENT_SET / INSTANCE を取得し、variants を解析
 * - Component 化されていない繰り返し構造は COMPONENT CANDIDATE（勝手に Component 化はしない）
 * - LAYOUT / UI_COMPONENT / CONTENT_COMPONENT / CMS_COMPONENT / STATIC_DECORATION / REVIEW へ分類
 */
import type { FigmaComponentMeta, FigmaNode } from "./api.js";
import type {
  ComponentClassification,
  ComponentEntry,
  ComponentVariant,
  FigmaConfidence,
} from "./manifest.js";
import type { CmsDetection } from "./cms.js";
import { detectRepeatGroups } from "./cms.js";
import {
  hasTextDescendant,
  isVisible,
  slugify,
  walkVisible,
} from "./normalize-util.js";
import { buildElementTree, type TreeContext } from "./tree.js";

const LAYOUT_RE = /header|footer|gnav|global.?nav|sidebar|ヘッダ|フッタ/i;
const UI_RE =
  /button|btn|input|select|checkbox|radio|tab|badge|tag|pagination|form|field/i;

export interface ComponentDetection {
  components: ComponentEntry[];
  /** Figma componentId（node id）→ manifest component_id */
  componentIdMap: Map<string, string>;
}

/**
 * 事前パス: componentId → component_id の対応表を作る
 * （tree 構築時の INSTANCE 参照解決に必要なため、tree より先に確定する）
 */
export function buildComponentIdMap(
  roots: FigmaNode[],
  componentsMeta: Record<string, FigmaComponentMeta>,
  componentSetsMeta: Record<string, FigmaComponentMeta>,
): Map<string, string> {
  const map = new Map<string, string>();
  // COMPONENT_SET member → set 名で解決
  for (const [id, meta] of Object.entries(componentsMeta)) {
    const setMeta = meta.componentSetId
      ? componentSetsMeta[meta.componentSetId]
      : undefined;
    map.set(id, slugify(setMeta?.name ?? meta.name));
  }
  // ドキュメント内の COMPONENT node（メタに無い場合の保険）
  for (const root of roots) {
    for (const { node, ancestors } of walkVisible(root)) {
      if (node.type !== "COMPONENT") continue;
      if (map.has(node.id)) continue;
      const parent = ancestors.at(-1);
      const name = parent?.type === "COMPONENT_SET" ? parent.name : node.name;
      map.set(node.id, slugify(name));
    }
  }
  return map;
}

export function detectComponents(
  roots: FigmaNode[],
  componentsMeta: Record<string, FigmaComponentMeta>,
  componentSetsMeta: Record<string, FigmaComponentMeta>,
  cms: CmsDetection,
  ctx: TreeContext,
): ComponentDetection {
  const componentIdMap = ctx.componentIdMap;
  const instanceCounts = countInstances(roots);
  const entries = new Map<string, ComponentEntry>();

  for (const root of roots) {
    for (const { node, ancestors } of walkVisible(root)) {
      if (node.type === "COMPONENT_SET") {
        const meta = findMetaByNodeId(componentSetsMeta, node.id);
        const variants: ComponentVariant[] = (node.children ?? [])
          .filter((c) => isVisible(c) && c.type === "COMPONENT")
          .map((c) => ({
            name: c.name,
            node_id: c.id,
            properties: parseVariantName(c.name),
          }));
        const memberIds = (node.children ?? []).map((c) => c.id);
        const count = memberIds.reduce(
          (acc, id) => acc + (instanceCounts.get(id) ?? 0),
          0,
        );
        const componentId = slugify(node.name);
        const firstVariant = (node.children ?? []).find(
          (c) => isVisible(c) && c.type === "COMPONENT",
        );
        addEntry(entries, {
          component_id: componentId,
          name: node.name,
          node_id: node.id,
          component_key: meta?.key ?? null,
          kind: "COMPONENT_SET",
          ...classify(node, componentId, count, cms),
          variants,
          instance_count: count,
          tree: buildTreeOrEmpty(firstVariant ?? node, ctx),
        });
        continue;
      }
      if (node.type === "COMPONENT") {
        const parent = ancestors.at(-1);
        if (parent?.type === "COMPONENT_SET") continue; // set 側で扱う
        const meta = findMetaByNodeId(componentsMeta, node.id);
        const componentId = slugify(node.name);
        const count = instanceCounts.get(node.id) ?? 0;
        addEntry(entries, {
          component_id: componentId,
          name: node.name,
          node_id: node.id,
          component_key: meta?.key ?? null,
          kind: "COMPONENT",
          ...classify(node, componentId, count, cms),
          variants: [],
          instance_count: count,
          tree: buildTreeOrEmpty(node, ctx),
        });
      }
    }
  }

  // Node Targeting で Components canvas が selection 外の場合:
  // COMPONENT 定義ツリーは無いが、INSTANCE のサブツリー + components メタから復元する
  // （Page だけを targeted fetch する標準経路を成立させる — SPEC §13）
  const firstInstanceByComponentId = new Map<string, FigmaNode>();
  for (const root of roots) {
    for (const { node } of walkVisible(root)) {
      if (node.type === "INSTANCE" && node.componentId) {
        if (!firstInstanceByComponentId.has(node.componentId)) {
          firstInstanceByComponentId.set(node.componentId, node);
        }
      }
    }
  }
  for (const [componentNodeId, instance] of firstInstanceByComponentId) {
    const meta = componentsMeta[componentNodeId];
    const setId = meta?.componentSetId;
    const setMeta = setId ? componentSetsMeta[setId] : undefined;
    const displayName = setMeta?.name ?? meta?.name ?? instance.name;
    const componentId = slugify(displayName);
    if (entries.has(componentId)) continue;
    // classification 用の proxy（tree は instance のサブツリー = component 構造の複製）
    const proxy: FigmaNode = {
      ...instance,
      name: displayName,
      type: "COMPONENT",
    };
    if (setId && setMeta) {
      const members = Object.entries(componentsMeta)
        .filter(([, m]) => m.componentSetId === setId)
        .map(([id, m]) => ({
          name: m.name,
          node_id: id,
          properties: parseVariantName(m.name),
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
      const count = members.reduce(
        (acc, m) => acc + (instanceCounts.get(m.node_id) ?? 0),
        0,
      );
      addEntry(entries, {
        component_id: componentId,
        name: displayName,
        node_id: setId,
        component_key: setMeta.key ?? null,
        kind: "COMPONENT_SET",
        ...classify(
          proxy,
          componentId,
          count,
          cms,
          members.map((m) => m.node_id),
        ),
        variants: members,
        instance_count: count,
        tree: buildTreeOrEmpty(instance, ctx),
      });
      continue;
    }
    const count = instanceCounts.get(componentNodeId) ?? 0;
    addEntry(entries, {
      component_id: componentId,
      name: displayName,
      node_id: componentNodeId,
      component_key: meta?.key ?? null,
      kind: "COMPONENT",
      ...classify(proxy, componentId, count, cms, [componentNodeId]),
      variants: [],
      instance_count: count,
      tree: buildTreeOrEmpty(instance, ctx),
    });
  }

  // Component 化されていない繰り返し → COMPONENT CANDIDATE（SPEC §31）
  for (const root of roots) {
    for (const group of detectRepeatGroups(root)) {
      const first = group.items[0];
      if (!first || first.type === "INSTANCE") continue; // instance 繰り返しは既存 component
      const componentId = `${slugify(first.name)}`;
      if (entries.has(componentId)) continue;
      addEntry(entries, {
        component_id: componentId,
        name: first.name,
        node_id: first.id,
        component_key: null,
        kind: "CANDIDATE",
        classification: hasTextDescendant(first)
          ? "REVIEW"
          : "STATIC_DECORATION",
        confidence: "REVIEW",
        reason: `同一構造（signature 一致）が ${group.items.length} 回繰り返されるが Component 化されていない。Component 化は人の判断（勝手に作らない）`,
        variants: [],
        instance_count: group.items.length,
        tree: buildTreeOrEmpty(first, ctx),
      });
    }
  }

  const components = [...entries.values()].sort((a, b) =>
    a.component_id.localeCompare(b.component_id),
  );
  return { components, componentIdMap };
}

function addEntry(
  map: Map<string, ComponentEntry>,
  entry: ComponentEntry,
): void {
  if (!map.has(entry.component_id)) map.set(entry.component_id, entry);
}

function buildTreeOrEmpty(
  node: FigmaNode,
  ctx: TreeContext,
): ComponentEntry["tree"] {
  return (
    buildElementTree(node, ctx) ?? {
      id: node.id,
      role: "frame",
      name: node.name,
    }
  );
}

function findMetaByNodeId(
  meta: Record<string, FigmaComponentMeta>,
  nodeId: string,
): FigmaComponentMeta | undefined {
  return meta[nodeId];
}

/** "Type=Primary, Size=Large" → { Type: "Primary", Size: "Large" } */
export function parseVariantName(name: string): Record<string, string> {
  const props: Record<string, string> = {};
  for (const pair of name.split(",")) {
    const [key, value] = pair.split("=").map((s) => s.trim());
    if (key && value) props[key] = value;
  }
  return Object.fromEntries(
    Object.entries(props).sort(([a], [b]) => a.localeCompare(b)),
  );
}

function countInstances(roots: FigmaNode[]): Map<string, number> {
  const counts = new Map<string, number>();
  for (const root of roots) {
    for (const { node } of walkVisible(root)) {
      if (node.type === "INSTANCE" && node.componentId) {
        counts.set(node.componentId, (counts.get(node.componentId) ?? 0) + 1);
      }
    }
  }
  return counts;
}

function classify(
  node: FigmaNode,
  componentId: string,
  instanceCount: number,
  cms: CmsDetection,
  extraIds: string[] = [],
): {
  classification: ComponentClassification;
  confidence: FigmaConfidence;
  reason: string;
} {
  if (LAYOUT_RE.test(node.name)) {
    return {
      classification: "LAYOUT",
      confidence: "CONFIDENT",
      reason: `名前 "${node.name}" と全 screen での使用（${instanceCount} instance）から site layout 部品と判断`,
    };
  }
  // CMS candidate のアイテムがこの component の instance → CMS_COMPONENT
  const linked = [...cms.instanceComponentIds.entries()].find(([, compId]) => {
    const ids = new Set<string>([
      node.id,
      ...(node.children ?? []).map((c) => c.id),
      ...extraIds,
    ]);
    return ids.has(compId);
  });
  if (linked) {
    const candidate = cms.candidates.find((c) => c.candidate_id === linked[0]);
    return {
      classification: "CMS_COMPONENT",
      confidence: "CONFIDENT",
      reason: `CMS candidate "${linked[0]}"（${candidate?.module_hint ?? "unknown"}）の繰り返しアイテムとして使用されている`,
    };
  }
  if (UI_RE.test(node.name)) {
    return {
      classification: "UI_COMPONENT",
      confidence: "CONFIDENT",
      reason: `名前 "${node.name}" から UI 部品と判断（variants: ${node.children?.length ?? 0}）`,
    };
  }
  if (!hasTextDescendant(node)) {
    return {
      classification: "STATIC_DECORATION",
      confidence: "CONFIDENT",
      reason: "テキストを含まない vector/画像のみの component — 装飾",
    };
  }
  void componentId;
  return {
    classification: "REVIEW",
    confidence: "REVIEW",
    reason: "名前・構造から用途を確定できない — 人が分類を確認",
  };
}
