export function slugify(name) {
    const s = name
        .toLowerCase()
        .replace(/[/\\]+/g, "-")
        .replace(/[^a-z0-9぀-ヿ一-鿿-]+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "");
    return s || "item";
}
/** Figma color (0..1) → CSS 値。alpha 1 なら #rrggbb、それ以外は rgba() */
export function toCssColor(color, opacity) {
    const a = Math.round((color.a * (opacity ?? 1) + Number.EPSILON) * 100) / 100;
    const to255 = (v) => Math.max(0, Math.min(255, Math.round(v * 255)));
    const r = to255(color.r);
    const g = to255(color.g);
    const b = to255(color.b);
    if (a >= 1) {
        const hex = (v) => v.toString(16).padStart(2, "0");
        return `#${hex(r)}${hex(g)}${hex(b)}`;
    }
    return `rgba(${r}, ${g}, ${b}, ${a})`;
}
export function isVisible(node) {
    return node.visible !== false;
}
/** 最初の可視 SOLID fill */
export function firstSolidFill(node) {
    for (const p of node.fills ?? []) {
        if (p.type === "SOLID" && p.visible !== false && p.color)
            return p;
    }
    return null;
}
/** 最初の可視 IMAGE fill */
export function firstImageFill(node) {
    for (const p of node.fills ?? []) {
        if (p.type === "IMAGE" && p.visible !== false && p.imageRef)
            return p;
    }
    return null;
}
const VECTOR_TYPES = new Set([
    "VECTOR",
    "BOOLEAN_OPERATION",
    "STAR",
    "LINE",
    "ELLIPSE",
    "POLYGON",
    "REGULAR_POLYGON",
]);
export function isVectorNode(node) {
    return VECTOR_TYPES.has(node.type) && !firstImageFill(node);
}
/** 可視 node の深さ優先訪問（document order を保つ = 決定論） */
export function walkVisible(root) {
    const out = [];
    const visit = (node, ancestors) => {
        if (!isVisible(node))
            return;
        out.push({ node, ancestors });
        for (const child of node.children ?? []) {
            visit(child, [...ancestors, node]);
        }
    };
    visit(root, []);
    return out;
}
export function countNodes(root) {
    let total = 0;
    let hidden = 0;
    const visit = (node) => {
        total++;
        if (node.visible === false)
            hidden++;
        for (const child of node.children ?? [])
            visit(child);
    };
    visit(root);
    return { total, hidden };
}
/**
 * 構造署名: 繰り返し検出（Component Candidate / CMS Candidate）の同一性判定。
 * テキスト内容・座標は含めない（内容が違っても構造が同じなら同一）。
 * INSTANCE は componentId で同一視する。
 */
export function structureSignature(node, depth = 2) {
    if (node.type === "INSTANCE")
        return `N(${node.componentId ?? "?"})`;
    if (node.type === "TEXT")
        return "T";
    if (firstImageFill(node))
        return "I";
    if (isVectorNode(node))
        return "V";
    const layout = node.layoutMode ?? "";
    if (depth <= 0 || !node.children?.length)
        return `${node.type}:${layout}`;
    const childSig = node.children
        .filter(isVisible)
        .map((c) => structureSignature(c, depth - 1))
        .join(",");
    return `${node.type}:${layout}[${childSig}]`;
}
export function hasTextDescendant(node) {
    if (!isVisible(node))
        return false;
    if (node.type === "TEXT")
        return true;
    return (node.children ?? []).some(hasTextDescendant);
}
export function collectTexts(node) {
    const out = [];
    const visit = (n) => {
        if (!isVisible(n))
            return;
        if (n.type === "TEXT")
            out.push(n);
        for (const c of n.children ?? [])
            visit(c);
    };
    visit(node);
    return out;
}
export function collectImageRefs(node) {
    const out = [];
    const visit = (n) => {
        if (!isVisible(n))
            return;
        const fill = firstImageFill(n);
        if (fill?.imageRef)
            out.push(fill.imageRef);
        for (const c of n.children ?? [])
            visit(c);
    };
    visit(node);
    return out;
}
export function bboxSize(node) {
    const box = node.absoluteBoundingBox;
    return {
        width: Math.round(box?.width ?? 0),
        height: Math.round(box?.height ?? 0),
    };
}
