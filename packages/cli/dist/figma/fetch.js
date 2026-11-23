/**
 * Metadata First 取得フロー（SPEC §11–15、ADR-0011 §4）。
 *
 *   metadata（Tier 3）
 *   → version 一致? YES → Raw Cache（Tier 1 call 0）
 *                   NO  → 必要 node だけ fetch（1 call に ids をまとめる）
 *   → variables は version ごとに 1 回だけ確認（結果は 403 含めて cache）
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { figmaCacheDir, loadCachedNodes, saveCachedMeta, saveCachedNodes, } from "./cache.js";
import { diagnoseApiFailure } from "./provider.js";
export async function fetchDesignData(api, agentcmsDir, config, opts = {}) {
    // 1. Metadata First（Tier 3 — 割当が多く、version 確認だけなら Tier 1 を消費しない）
    const metaRes = await api.getFileMeta(config.file_key);
    if (!metaRes.ok) {
        return { error: diagnoseApiFailure("file metadata の取得", metaRes) };
    }
    const meta = metaRes.data;
    saveCachedMeta(agentcmsDir, config.file_key, meta);
    // 2. nodes: version + selection が一致する Raw Cache があれば API を呼ばない
    const selection = [...config.entry_nodes].sort();
    let nodesResponse = loadCachedNodes(agentcmsDir, config.file_key, meta.version, selection);
    let cacheHit = nodesResponse !== null;
    if (!nodesResponse) {
        const fetched = await fetchNodes(api, config.file_key, selection);
        if ("error" in fetched)
            return fetched;
        nodesResponse = fetched.response;
        saveCachedNodes(agentcmsDir, config.file_key, meta.version, selection, nodesResponse);
        cacheHit = false;
    }
    // 3. roots とメタ情報の統合
    const roots = [];
    const componentsMeta = {};
    const componentSetsMeta = {};
    const stylesMeta = {};
    const missing = [];
    const ids = selection.length > 0 ? selection : Object.keys(nodesResponse.nodes).sort();
    for (const id of ids) {
        const entry = nodesResponse.nodes[id];
        if (!entry) {
            missing.push(id);
            continue;
        }
        roots.push(entry.document);
        Object.assign(componentsMeta, entry.components ?? {});
        Object.assign(componentSetsMeta, entry.componentSets ?? {});
        Object.assign(stylesMeta, entry.styles ?? {});
    }
    if (missing.length > 0) {
        return {
            error: {
                what: "指定 node を取得できませんでした",
                why: `entry_nodes に存在しない node があります: ${missing.join(", ")}（Figma 側で削除された可能性）`,
                fix: "agentcms figma connect で entry_nodes を更新するか、.agentcms/figma/project.json を修正してください",
            },
        };
    }
    if (roots.length === 0) {
        return {
            error: {
                what: "取得対象 node がありません",
                why: "nodes response が空です",
                fix: "figma connect で対象 Frame / Page を指定し直してください",
            },
        };
    }
    // 4. Variables（Advanced Capability。既定では要求しない = API call 0。
    //    要求時のみ実行し、結果は 403 含め version 単位で cache — SPEC §87）
    const varsEntry = opts.variables
        ? await resolveVariables(api, agentcmsDir, config.file_key, meta.version)
        : null;
    return {
        data: {
            meta,
            cacheHit,
            input: {
                fileKey: config.file_key,
                meta,
                roots,
                componentsMeta,
                componentSetsMeta,
                stylesMeta,
                selectedNodes: selection,
                variablesRequested: opts.variables === true,
                variables: varsEntry?.available === true ? (varsEntry.response ?? null) : null,
                variablesUnavailableReason: varsEntry && !varsEntry.available
                    ? (varsEntry.reason ?? "unavailable")
                    : null,
                rawBytes: Buffer.byteLength(JSON.stringify(nodesResponse), "utf8"),
            },
        },
    };
}
async function fetchNodes(api, fileKey, selection) {
    if (selection.length > 0) {
        // Node Targeting: 全 ids を 1 call へまとめる（公式推奨のバッチ化）
        const res = await api.getFileNodes(fileKey, selection);
        if (!res.ok)
            return { error: diagnoseApiFailure("nodes の取得", res) };
        return { response: res.data };
    }
    // entry_nodes 未指定 → file 全体（nodes response 形式へ正規化して cache 形式を統一）
    const res = await api.getFile(fileKey);
    if (!res.ok)
        return { error: diagnoseApiFailure("file の取得", res) };
    const file = res.data;
    return {
        response: {
            name: file.name,
            version: file.version,
            editorType: file.editorType ?? "figma",
            nodes: {
                [file.document.id]: {
                    document: file.document,
                    components: file.components,
                    componentSets: file.componentSets,
                    styles: file.styles,
                },
            },
        },
    };
}
async function resolveVariables(api, agentcmsDir, fileKey, version) {
    const dir = figmaCacheDir(agentcmsDir, fileKey);
    const path = join(dir, `variables-v${version}.json`);
    if (existsSync(path)) {
        try {
            return JSON.parse(readFileSync(path, "utf8"));
        }
        catch {
            // 壊れた cache は再取得
        }
    }
    const res = await api.getLocalVariables(fileKey);
    const entry = res.ok
        ? { available: true, response: res.data }
        : {
            available: false,
            reason: res.kind === "forbidden"
                ? `plan 制限（Enterprise 限定）: ${res.message}`
                : `${res.status}: ${res.message}`,
        };
    mkdirSync(dir, { recursive: true });
    writeFileSync(path, `${JSON.stringify(entry)}\n`, "utf8");
    return entry;
}
