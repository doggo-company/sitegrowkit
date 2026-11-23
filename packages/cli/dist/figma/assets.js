import { bboxSize, firstImageFill, isVectorNode, slugify, walkVisible, } from "./normalize-util.js";
const ICON_MAX_SIZE = 64;
const DECORATION_RE = /deco|blob|pattern|ornament|背景|装飾/i;
const LOGO_RE = /logo|ロゴ/i;
export function extractAssets(roots, cmsImageNodeIds) {
    const images = new Map();
    const vectors = [];
    const assetIdByNodeId = new Map();
    for (const root of roots) {
        for (const { node, ancestors } of walkVisible(root)) {
            const fill = firstImageFill(node);
            if (fill?.imageRef) {
                const size = bboxSize(node);
                const agg = images.get(fill.imageRef) ?? {
                    ref: fill.imageRef,
                    name: node.name,
                    nodeIds: [],
                    width: size.width,
                    height: size.height,
                    inCms: false,
                };
                agg.nodeIds.push(node.id);
                agg.width = Math.max(agg.width, size.width);
                agg.height = Math.max(agg.height, size.height);
                if (cmsImageNodeIds.has(node.id))
                    agg.inCms = true;
                images.set(fill.imageRef, agg);
                continue;
            }
            if (isVectorNode(node)) {
                const size = bboxSize(node);
                const iconish = size.width <= ICON_MAX_SIZE && size.height <= ICON_MAX_SIZE;
                const decorative = DECORATION_RE.test(node.name) ||
                    ancestors.some((a) => DECORATION_RE.test(a.name));
                if (!iconish && !decorative && !LOGO_RE.test(node.name))
                    continue;
                const { classification, confidence, reason } = classifyVector(node, iconish, decorative);
                const assetId = `vec-${slugify(node.name)}-${size.width}x${size.height}`;
                const existing = vectors.find((v) => v.asset_id === assetId);
                if (existing) {
                    existing.node_ids.push(node.id);
                    assetIdByNodeId.set(node.id, assetId);
                    continue;
                }
                vectors.push({
                    asset_id: assetId,
                    name: node.name,
                    kind: "vector",
                    classification,
                    confidence,
                    reason,
                    node_ids: [node.id],
                    image_ref: null,
                    width: size.width,
                    height: size.height,
                });
                assetIdByNodeId.set(node.id, assetId);
            }
        }
    }
    const assetIdByImageRef = new Map();
    const imageEntries = [...images.values()].map((agg) => {
        const assetId = `img-${agg.ref.replace(/[^0-9a-z]/gi, "").slice(0, 12)}`;
        assetIdByImageRef.set(agg.ref, assetId);
        for (const nodeId of agg.nodeIds)
            assetIdByNodeId.set(nodeId, assetId);
        const { classification, confidence, reason } = classifyImage(agg);
        return {
            asset_id: assetId,
            name: agg.name,
            kind: "image",
            classification,
            confidence,
            reason,
            node_ids: [...agg.nodeIds].sort(),
            image_ref: agg.ref,
            width: agg.width,
            height: agg.height,
        };
    });
    const assets = [...imageEntries, ...vectors].sort((a, b) => a.asset_id.localeCompare(b.asset_id));
    return { assets, assetIdByImageRef, assetIdByNodeId };
}
function classifyImage(agg) {
    if (agg.inCms) {
        return {
            classification: "CONTENT_MEDIA",
            confidence: "CONFIDENT",
            reason: "CMS candidate の繰り返しアイテム内の画像 — CMS から更新される content media 候補",
        };
    }
    if (LOGO_RE.test(agg.name)) {
        return {
            classification: "STATIC_DESIGN_ASSET",
            confidence: "CONFIDENT",
            reason: "ロゴはサイト固定の static asset（Media Module へ入れない）",
        };
    }
    if (agg.width <= ICON_MAX_SIZE && agg.height <= ICON_MAX_SIZE) {
        return {
            classification: "ICON",
            confidence: "CONFIDENT",
            reason: `${agg.width}x${agg.height} の小型画像 — icon として扱う`,
        };
    }
    if (DECORATION_RE.test(agg.name)) {
        return {
            classification: "DECORATION",
            confidence: "CONFIDENT",
            reason: "名前から装飾用途と判断",
        };
    }
    if (agg.width >= 600) {
        return {
            classification: "STATIC_DESIGN_ASSET",
            confidence: "REVIEW",
            reason: `${agg.width}px 幅の大型画像。CMS 繰り返し外のため static 扱い（hero 等）。用途は人が確認`,
        };
    }
    return {
        classification: "REVIEW",
        confidence: "REVIEW",
        reason: "分類根拠が不足 — 用途を人が確認",
    };
}
function classifyVector(node, iconish, decorative) {
    if (LOGO_RE.test(node.name)) {
        return {
            classification: "STATIC_DESIGN_ASSET",
            confidence: "CONFIDENT",
            reason: "ロゴ vector — static asset（SVG は CMS upload 禁止: Phase 2 policy）",
        };
    }
    if (decorative) {
        return {
            classification: "DECORATION",
            confidence: "CONFIDENT",
            reason: "装飾 vector — static asset として配置",
        };
    }
    if (iconish) {
        return {
            classification: "ICON",
            confidence: "CONFIDENT",
            reason: "小型 vector — icon（SVG static asset。Media へは入れない）",
        };
    }
    return {
        classification: "REVIEW",
        confidence: "REVIEW",
        reason: "用途を特定できない vector",
    };
}
