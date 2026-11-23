import { bboxSize, collectImageRefs, collectTexts, firstImageFill, firstSolidFill, isVectorNode, isVisible, toCssColor, } from "./normalize-util.js";
import { redactDesignText } from "./redact.js";
import { typoKey } from "./tokens.js";
const MAX_TEXT = 200;
const MAX_SLOT_TEXT = 120;
export function buildElementTree(node, ctx, depth = 0) {
    if (!isVisible(node))
        return null;
    if (node.type === "INSTANCE")
        return buildInstance(node, ctx);
    if (node.type === "TEXT")
        return buildText(node, ctx);
    const imageFill = firstImageFill(node);
    if (imageFill?.imageRef) {
        const size = bboxSize(node);
        const el = {
            id: node.id,
            role: "image",
            name: node.name,
            asset: ctx.assetIdByImageRef.get(imageFill.imageRef),
            width: size.width,
            height: size.height,
        };
        // fill の表示意図を Design Intent として保持（座標コピーではなく object-fit 等へ安全変換できる情報のみ）
        const mode = fillMode(imageFill.scaleMode);
        if (mode)
            el.fill_mode = mode;
        if (mode === "crop" && imageFill.imageTransform) {
            const flat = imageFill.imageTransform
                .flat()
                .map((v) => Math.round(v * 10000) / 10000);
            if (flat.length === 6)
                el.fill_transform = flat;
        }
        return el;
    }
    if (isVectorNode(node)) {
        const size = bboxSize(node);
        return {
            id: node.id,
            role: "vector",
            name: node.name,
            asset: ctx.assetIdByNodeId.get(node.id),
            width: size.width,
            height: size.height,
        };
    }
    // FRAME / GROUP / SECTION / COMPONENT 等のコンテナ
    const el = {
        id: node.id,
        role: node.type === "GROUP" ? "group" : "frame",
        name: node.name,
    };
    const layout = detectLayout(node, ctx);
    if (layout)
        el.layout = layout;
    if (typeof node.itemSpacing === "number" && node.itemSpacing > 0) {
        el.gap = node.itemSpacing;
    }
    const padding = [
        node.paddingTop ?? 0,
        node.paddingRight ?? 0,
        node.paddingBottom ?? 0,
        node.paddingLeft ?? 0,
    ];
    if (padding.some((p) => p > 0))
        el.padding = padding;
    const fill = firstSolidFill(node);
    if (fill?.color) {
        const token = ctx.colorTokenByValue.get(toCssColor(fill.color, fill.opacity));
        if (token)
            el.fill_token = token;
    }
    if (depth <= 1) {
        const size = bboxSize(node);
        if (size.width > 0) {
            el.width = size.width;
            el.height = size.height;
        }
    }
    const children = (node.children ?? [])
        .map((c) => buildElementTree(c, ctx, depth + 1))
        .filter((c) => c !== null);
    if (children.length > 0)
        el.children = children;
    return el;
}
function buildInstance(node, ctx) {
    const el = {
        id: node.id,
        role: "instance",
        name: node.name,
        component: (node.componentId && ctx.componentIdMap.get(node.componentId)) ||
            undefined,
    };
    const variant = {};
    for (const [key, prop] of Object.entries(node.componentProperties ?? {})) {
        if (prop.type === "VARIANT")
            variant[key.split("#")[0] ?? key] = String(prop.value);
    }
    if (Object.keys(variant).length > 0)
        el.variant = sortRecord(variant);
    const texts = collectTexts(node)
        .map((t) => redactText(t.characters ?? "", t.id, ctx, MAX_SLOT_TEXT))
        .filter(Boolean);
    if (texts.length > 0)
        el.text_slots = texts;
    const imageSlots = [...new Set(collectImageRefs(node))]
        .map((ref) => ctx.assetIdByImageRef.get(ref))
        .filter((v) => v !== undefined)
        .sort();
    if (imageSlots.length > 0)
        el.image_slots = imageSlots;
    return el;
}
function buildText(node, ctx) {
    const el = {
        id: node.id,
        role: "text",
        name: node.name,
        text: redactText(node.characters ?? "", node.id, ctx, MAX_TEXT),
    };
    // Figma に実在する hyperlink のみ保持（destination の発明はしない — SPEC §29 / Phase 6.1 Gap 4）
    const url = node.style?.hyperlink?.url?.trim();
    if (url && /^https?:\/\//.test(url))
        el.link = url;
    if (node.style) {
        const key = typoKey(node.style.fontFamily ?? "unknown", node.style.fontWeight ?? 400, node.style.fontSize ?? 16);
        const token = ctx.typoTokenByKey.get(key);
        if (token)
            el.text_token = token;
    }
    const fill = firstSolidFill(node);
    if (fill?.color) {
        const token = ctx.colorTokenByValue.get(toCssColor(fill.color, fill.opacity));
        if (token)
            el.fill_token = token;
    }
    return el;
}
function redactText(text, nodeId, ctx, max) {
    const { text: out, redacted } = redactDesignText(text);
    if (redacted && !ctx.warnings.some((w) => w.node_id === nodeId)) {
        ctx.warnings.push({
            code: "SECRET_REDACTED",
            message: "Text layer に credential らしき文字列を検出したため redact しました",
            node_id: nodeId,
        });
    }
    return out.length > max ? `${out.slice(0, max)}…` : out;
}
function fillMode(scaleMode) {
    switch (scaleMode) {
        case "FILL":
            return "cover";
        case "FIT":
            return "contain";
        case "CROP":
            return "crop";
        case "TILE":
            return "tile";
        default:
            return null;
    }
}
function detectLayout(node, ctx) {
    if (node.layoutMode === "VERTICAL")
        return "flex-column";
    if (node.layoutMode === "HORIZONTAL") {
        return node.layoutWrap === "WRAP" ? "grid-candidate" : "flex-row";
    }
    if (node.layoutMode === "GRID")
        return "grid-candidate";
    if (node.type === "GROUP")
        return "stack";
    const visibleChildren = (node.children ?? []).filter(isVisible);
    if ((node.type === "FRAME" || node.type === "SECTION") &&
        visibleChildren.length > 1) {
        // Auto Layout の無い多子 FRAME = absolute 配置。座標コピーは行わないため REVIEW を促す
        if (!ctx.warnings.some((w) => w.node_id === node.id)) {
            ctx.warnings.push({
                code: "ABSOLUTE_LAYOUT",
                message: `"${node.name}" は Auto Layout が無く絶対配置です。実装時は座標コピーではなく意図を汲んだ layout にしてください（SPEC §28）`,
                node_id: node.id,
            });
        }
        return "absolute";
    }
    return null;
}
function sortRecord(record) {
    return Object.fromEntries(Object.entries(record).sort(([a], [b]) => a.localeCompare(b)));
}
