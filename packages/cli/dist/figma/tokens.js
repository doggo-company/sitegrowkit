import { firstSolidFill, slugify, toCssColor, walkVisible, } from "./normalize-util.js";
/** INFERRED 昇格に必要な最低使用回数（Do Not Over-Tokenize） */
const INFERRED_MIN_USAGE = 3;
export function typoKey(family, weight, size) {
    return `${family}|${weight}|${size}`;
}
export function extractTokens(roots, styles, variables) {
    const colorUsage = new Map();
    const typoUsage = new Map();
    const spacingUsage = new Map();
    const radiusUsage = new Map();
    const shadowUsage = new Map();
    const fontUsage = new Map();
    for (const root of roots) {
        for (const { node } of walkVisible(root)) {
            collectColor(node, styles, colorUsage);
            collectTypography(node, styles, typoUsage, fontUsage);
            collectSpacing(node, spacingUsage);
            collectRadius(node, radiusUsage);
            collectShadow(node, shadowUsage);
        }
    }
    const variableColors = extractVariableColors(variables);
    const variableNumbers = extractVariableNumbers(variables);
    const colors = buildColorTokens(colorUsage, variableColors);
    const typography = buildTypographyTokens(typoUsage);
    const spacing = buildNumberTokens(spacingUsage, "space", variableNumbers.spacing);
    const radius = buildNumberTokens(radiusUsage, "radius", variableNumbers.radius, 2);
    const shadows = buildShadowTokens(shadowUsage);
    const colorTokenByValue = new Map();
    for (const t of colors)
        colorTokenByValue.set(t.value, t.name);
    const typoTokenByKey = new Map();
    for (const [key, u] of typoUsage) {
        const token = typography.find((t) => typoKey(t.family, t.weight, t.size) === key);
        if (token)
            typoTokenByKey.set(key, token.name);
        void u;
    }
    const fonts = [...fontUsage.entries()]
        .map(([family, weights]) => ({
        family,
        weights: [...weights.keys()].sort((a, b) => a - b),
        usage_count: [...weights.values()].reduce((a, b) => a + b, 0),
    }))
        .sort((a, b) => a.family.localeCompare(b.family));
    return {
        tokens: { colors, typography, spacing, radius, shadows },
        fonts,
        colorTokenByValue,
        typoTokenByKey,
    };
}
function collectColor(node, styles, usage) {
    const fill = firstSolidFill(node);
    if (!fill?.color)
        return;
    const value = toCssColor(fill.color, fill.opacity);
    const entry = usage.get(value) ?? { value, count: 0, styleName: null };
    entry.count++;
    const styleId = node.styles?.fill ?? node.styles?.fills;
    if (styleId && node.type !== "TEXT") {
        const meta = styles[styleId];
        if (meta?.styleType === "FILL")
            entry.styleName = meta.name;
    }
    usage.set(value, entry);
}
function collectTypography(node, styles, usage, fontUsage) {
    if (node.type !== "TEXT" || !node.style)
        return;
    const family = node.style.fontFamily ?? "unknown";
    const weight = node.style.fontWeight ?? 400;
    const size = node.style.fontSize ?? 16;
    const key = typoKey(family, weight, size);
    const entry = usage.get(key) ?? {
        family,
        weight,
        size,
        lineHeight: node.style.lineHeightPx ?? null,
        letterSpacing: node.style.letterSpacing ?? null,
        count: 0,
        styleName: null,
    };
    entry.count++;
    const styleId = node.styles?.text;
    if (styleId) {
        const meta = styles[styleId];
        if (meta?.styleType === "TEXT")
            entry.styleName = meta.name;
    }
    usage.set(key, entry);
    const weights = fontUsage.get(family) ?? new Map();
    weights.set(weight, (weights.get(weight) ?? 0) + 1);
    fontUsage.set(family, weights);
}
function collectSpacing(node, usage) {
    if (!node.layoutMode || node.layoutMode === "NONE")
        return;
    const values = [
        node.itemSpacing,
        node.paddingTop,
        node.paddingRight,
        node.paddingBottom,
        node.paddingLeft,
    ];
    for (const v of values) {
        if (typeof v === "number" && v > 0) {
            usage.set(v, (usage.get(v) ?? 0) + 1);
        }
    }
}
function collectRadius(node, usage) {
    if (typeof node.cornerRadius === "number" && node.cornerRadius > 0) {
        usage.set(node.cornerRadius, (usage.get(node.cornerRadius) ?? 0) + 1);
    }
}
function collectShadow(node, usage) {
    for (const e of node.effects ?? []) {
        if (e.type !== "DROP_SHADOW" || e.visible === false || !e.color)
            continue;
        const css = `${e.offset?.x ?? 0}px ${e.offset?.y ?? 0}px ${e.radius ?? 0}px ${e.spread ? `${e.spread}px ` : ""}${toCssColor(e.color)}`;
        usage.set(css, (usage.get(css) ?? 0) + 1);
    }
}
function extractVariableColors(variables) {
    const out = new Map(); // value → variable 名
    if (!variables)
        return out;
    const collections = variables.meta.variableCollections;
    for (const v of Object.values(variables.meta.variables)) {
        if (v.resolvedType !== "COLOR")
            continue;
        const collection = collections[v.variableCollectionId];
        const modeId = collection?.defaultModeId;
        const raw = modeId ? v.valuesByMode[modeId] : undefined;
        if (!raw || typeof raw !== "object")
            continue;
        const c = raw;
        out.set(toCssColor(c), slugify(v.name));
    }
    return out;
}
function extractVariableNumbers(variables) {
    const spacing = new Map();
    const radius = new Map();
    if (!variables)
        return { spacing, radius };
    const collections = variables.meta.variableCollections;
    for (const v of Object.values(variables.meta.variables)) {
        if (v.resolvedType !== "FLOAT")
            continue;
        const collection = collections[v.variableCollectionId];
        const modeId = collection?.defaultModeId;
        const raw = modeId ? v.valuesByMode[modeId] : undefined;
        if (typeof raw !== "number")
            continue;
        const name = slugify(v.name);
        if (/radius|round/.test(name))
            radius.set(raw, name);
        else
            spacing.set(raw, name);
    }
    return { spacing, radius };
}
function buildColorTokens(usage, variableColors) {
    const out = [];
    for (const u of usage.values()) {
        const variableName = variableColors.get(u.value);
        if (variableName) {
            out.push({
                name: variableName,
                value: u.value,
                source: "VARIABLE",
                confidence: "CONFIDENT",
                usage_count: u.count,
            });
        }
        else if (u.styleName) {
            out.push({
                name: slugify(u.styleName),
                value: u.value,
                source: "STYLE",
                confidence: "CONFIDENT",
                usage_count: u.count,
            });
        }
        else if (u.count >= INFERRED_MIN_USAGE) {
            out.push({
                name: `color-${u.value.replace(/[^0-9a-z]/gi, "").slice(0, 8)}`,
                value: u.value,
                source: "INFERRED",
                confidence: "REVIEW",
                usage_count: u.count,
            });
        }
    }
    // Variables に使用実績が無い（usage 0）ものも候補として残す
    for (const [value, name] of variableColors) {
        if (!out.some((t) => t.value === value)) {
            out.push({
                name,
                value,
                source: "VARIABLE",
                confidence: "CONFIDENT",
                usage_count: 0,
            });
        }
    }
    return out.sort((a, b) => a.name.localeCompare(b.name));
}
function buildTypographyTokens(usage) {
    const out = [];
    for (const u of usage.values()) {
        const named = u.styleName !== null;
        out.push({
            name: named
                ? slugify(u.styleName ?? "")
                : `font-${Math.round(u.size)}-${u.weight}`,
            family: u.family,
            weight: u.weight,
            size: u.size,
            line_height: u.lineHeight,
            letter_spacing: u.letterSpacing,
            source: named ? "STYLE" : "INFERRED",
            confidence: named ? "CONFIDENT" : "REVIEW",
            usage_count: u.count,
        });
    }
    return out.sort((a, b) => a.name.localeCompare(b.name));
}
function buildNumberTokens(usage, prefix, variableNames, minUsage = INFERRED_MIN_USAGE) {
    const out = [];
    for (const [value, count] of usage) {
        const variableName = variableNames.get(value);
        if (variableName) {
            out.push({
                name: variableName,
                value: `${value}px`,
                source: "VARIABLE",
                confidence: "CONFIDENT",
                usage_count: count,
            });
        }
        else if (count >= minUsage) {
            out.push({
                name: `${prefix}-${value}`,
                value: `${value}px`,
                source: "INFERRED",
                confidence: "REVIEW",
                usage_count: count,
            });
        }
    }
    return out.sort((a, b) => Number.parseFloat(a.value) - Number.parseFloat(b.value));
}
function buildShadowTokens(usage) {
    return [...usage.entries()]
        .filter(([, count]) => count >= 2)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([value, count], i) => ({
        name: `shadow-${i + 1}`,
        value,
        source: "INFERRED",
        confidence: "REVIEW",
        usage_count: count,
    }));
}
