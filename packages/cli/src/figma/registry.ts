/**
 * Component Registry（SPEC §33–34）: .agentcms/components/registry.json
 * Figma Component ↔ SiteGrowKit の Code Component の安定対応表。
 * plan が proposed を書き、apply が mapped へ昇格する。将来の figma diff / re-sync で使う。
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

import type { ComponentEntry } from "./manifest.js";

export type MappingStatus = "mapped" | "proposed" | "review";

export interface RegistryEntry {
  component_id: string;
  figma: {
    node_id: string;
    component_key: string | null;
  };
  code: {
    type: "blade" | "vue" | null;
    target: string | null;
  };
  variants: string[];
  props: string[];
  /** Manifest の component tree のハッシュ（Figma 側変更の検出に使う） */
  source_hash: string;
  status: MappingStatus;
}

export interface ComponentRegistry {
  version: 1;
  components: RegistryEntry[];
}

export function registryPath(agentcmsDir: string): string {
  return join(agentcmsDir, "components", "registry.json");
}

export function loadRegistry(agentcmsDir: string): ComponentRegistry {
  const path = registryPath(agentcmsDir);
  if (!existsSync(path)) return { version: 1, components: [] };
  try {
    return JSON.parse(readFileSync(path, "utf8")) as ComponentRegistry;
  } catch {
    return { version: 1, components: [] };
  }
}

export function saveRegistry(
  agentcmsDir: string,
  registry: ComponentRegistry,
): string {
  const path = registryPath(agentcmsDir);
  mkdirSync(dirname(path), { recursive: true });
  const sorted: ComponentRegistry = {
    version: 1,
    components: [...registry.components].sort((a, b) =>
      a.component_id.localeCompare(b.component_id),
    ),
  };
  writeFileSync(path, `${JSON.stringify(sorted, null, 2)}\n`, "utf8");
  return path;
}

export function componentSourceHash(component: ComponentEntry): string {
  return createHash("sha256")
    .update(
      JSON.stringify({ tree: component.tree, variants: component.variants }),
    )
    .digest("hex")
    .slice(0, 16);
}

/** Manifest の component と code target の対応を registry へ反映（冪等 merge） */
export function upsertRegistryEntry(
  registry: ComponentRegistry,
  component: ComponentEntry,
  code: { type: "blade" | "vue" | null; target: string | null },
  status: MappingStatus,
): void {
  const entry: RegistryEntry = {
    component_id: component.component_id,
    figma: {
      node_id: component.node_id,
      component_key: component.component_key,
    },
    code,
    variants: component.variants.map((v) => v.name).sort(),
    props: Object.keys(component.variants[0]?.properties ?? {}).sort(),
    source_hash: componentSourceHash(component),
    status,
  };
  const index = registry.components.findIndex(
    (c) => c.component_id === component.component_id,
  );
  if (index >= 0) {
    const existing = registry.components[index] as RegistryEntry;
    // mapped を proposed で退行させない（apply 済みの対応を plan が壊さない）
    if (existing.status === "mapped" && status === "proposed") {
      registry.components[index] = {
        ...entry,
        code: existing.code,
        status: "mapped",
      };
      return;
    }
    registry.components[index] = entry;
    return;
  }
  registry.components.push(entry);
}
