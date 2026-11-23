/**
 * Component Registry（SPEC §33–34）: .agentcms/components/registry.json
 * Figma Component ↔ SiteGrowKit の Code Component の安定対応表。
 * plan が proposed を書き、apply が mapped へ昇格する。将来の figma diff / re-sync で使う。
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
export function registryPath(agentcmsDir) {
    return join(agentcmsDir, "components", "registry.json");
}
export function loadRegistry(agentcmsDir) {
    const path = registryPath(agentcmsDir);
    if (!existsSync(path))
        return { version: 1, components: [] };
    try {
        return JSON.parse(readFileSync(path, "utf8"));
    }
    catch {
        return { version: 1, components: [] };
    }
}
export function saveRegistry(agentcmsDir, registry) {
    const path = registryPath(agentcmsDir);
    mkdirSync(dirname(path), { recursive: true });
    const sorted = {
        version: 1,
        components: [...registry.components].sort((a, b) => a.component_id.localeCompare(b.component_id)),
    };
    writeFileSync(path, `${JSON.stringify(sorted, null, 2)}\n`, "utf8");
    return path;
}
export function componentSourceHash(component) {
    return createHash("sha256")
        .update(JSON.stringify({ tree: component.tree, variants: component.variants }))
        .digest("hex")
        .slice(0, 16);
}
/** Manifest の component と code target の対応を registry へ反映（冪等 merge） */
export function upsertRegistryEntry(registry, component, code, status) {
    const entry = {
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
    const index = registry.components.findIndex((c) => c.component_id === component.component_id);
    if (index >= 0) {
        const existing = registry.components[index];
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
