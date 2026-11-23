/**
 * agentcms figma inspect（SPEC §19–23）。
 * Metadata First → targeted fetch → Raw Cache → Deterministic Normalize → Design Manifest。
 * AI はこの Manifest（.agentcms/manifest/figma.json）を読む。Raw Cache は AI Context ではない。
 */
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { findAgentcmsDir } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";
import { renderCachePath } from "../figma/cache.js";
import { loadFigmaConfig } from "../figma/config.js";
import { fetchDesignData } from "../figma/fetch.js";
import { buildDesignManifest } from "../figma/normalize.js";
import { resolveFigmaApi } from "../figma/provider.js";
export function figmaManifestPath(agentcmsDir) {
    return join(agentcmsDir, "manifest", "figma.json");
}
export async function figmaInspect(opts = {}) {
    const agentcmsDir = findAgentcmsDir();
    if (!agentcmsDir) {
        fail({
            what: "figma inspect を実行できません",
            why: ".agentcms/ ディレクトリが見つかりません",
            fix: "SiteGrowKit のプロジェクト内で実行してください",
        });
        return 1;
    }
    const { config, error } = loadFigmaConfig(agentcmsDir);
    if (!config) {
        fail(error ?? { what: "config error", why: "unknown", fix: "figma connect" });
        return 1;
    }
    const apiRes = resolveFigmaApi();
    if ("error" in apiRes) {
        fail(apiRes.error);
        return 1;
    }
    const api = apiRes.api;
    info(`取得元: ${api.label()}`);
    const fetched = await fetchDesignData(api, agentcmsDir, config, {
        variables: opts.variables,
    });
    if ("error" in fetched) {
        fail(fetched.error);
        return 1;
    }
    const { input, meta, cacheHit } = fetched.data;
    info(cacheHit
        ? `Raw Cache hit（version ${meta.version} 未変更 — Tier 1 API call なし）`
        : `Figma から取得しました（version ${meta.version}）`);
    const manifest = buildDesignManifest(input);
    const outPath = opts.out ?? figmaManifestPath(agentcmsDir);
    mkdirSync(dirname(outPath), { recursive: true });
    const json = `${JSON.stringify(manifest, null, 2)}\n`;
    writeFileSync(outPath, json, "utf8");
    printSummary(manifest, json.length, api);
    ok(`Design Manifest: ${outPath}`);
    if (opts.renders) {
        const code = await saveVisualBaselines(api, agentcmsDir, config.file_key, manifest);
        if (code !== 0)
            return code;
    }
    info("次: agentcms figma plan（または figma diff）");
    return 0;
}
function printSummary(manifest, manifestBytes, api) {
    heading("Design Manifest");
    info(`screens: ${manifest.screens.length} / components: ${manifest.components.length} / cms candidates: ${manifest.cms_candidates.length} / assets: ${manifest.assets.length}`);
    const t = manifest.tokens;
    info(`tokens: colors ${t.colors.length} / typography ${t.typography.length} / spacing ${t.spacing.length} / radius ${t.radius.length} / shadows ${t.shadows.length}`);
    const ratio = manifest.stats.raw_bytes > 0
        ? ((manifestBytes / manifest.stats.raw_bytes) * 100).toFixed(1)
        : "?";
    info(`Manifest First: raw ${formatBytes(manifest.stats.raw_bytes)} (${manifest.stats.raw_nodes} nodes) → manifest ${formatBytes(manifestBytes)} (${ratio}%)`);
    const byEndpoint = new Map();
    for (const c of api.calls) {
        byEndpoint.set(c.endpoint, (byEndpoint.get(c.endpoint) ?? 0) + 1);
    }
    info(`API calls: ${[...byEndpoint.entries()].map(([e, n]) => `${e}=${n}`).join(" ") || "0"}`);
    heading("Capabilities");
    for (const cap of manifest.capabilities) {
        const line = `${cap.name.padEnd(14)} ${cap.state}  ${cap.detail}`;
        if (cap.state === "UNAVAILABLE")
            warn(line);
        else
            info(line);
    }
    for (const w of manifest.warnings) {
        warn(`[${w.code}] ${w.message}`);
    }
}
/** Visual Baseline（SPEC §71）: screen frame を Image API で render し cache へ保存 */
async function saveVisualBaselines(api, agentcmsDir, fileKey, manifest) {
    const ids = manifest.screens.flatMap((s) => s.variants.map((v) => v.node_id));
    if (ids.length === 0)
        return 0;
    const res = await api.getImageRenders(fileKey, ids, {
        format: "png",
        scale: 1,
    });
    if (!res.ok) {
        fail({
            what: "Visual Baseline render の取得に失敗しました",
            why: `${res.status}: ${res.message}`,
            fix: "renders なしで続行できます（--renders を外す）",
        });
        return 1;
    }
    let saved = 0;
    for (const [nodeId, url] of Object.entries(res.data.images)) {
        if (!url)
            continue;
        const dl = await api.download(url);
        if (!dl.ok)
            continue;
        const path = renderCachePath(agentcmsDir, fileKey, nodeId, manifest.source.figma_version, "png");
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, dl.data);
        saved++;
    }
    ok(`Visual Baseline: ${saved} render を保存しました（gitignore 対象 cache）`);
    return 0;
}
function formatBytes(bytes) {
    if (bytes >= 1024 * 1024)
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    if (bytes >= 1024)
        return `${(bytes / 1024).toFixed(1)} KB`;
    return `${bytes} B`;
}
