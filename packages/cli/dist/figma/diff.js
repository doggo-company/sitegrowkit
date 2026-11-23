export function diffManifests(previous, next) {
    const entries = [];
    diffById(entries, "screen", previous.screens, next.screens, (s) => s.screen_id, (a, b) => {
        const changes = [];
        if (JSON.stringify(a.variants.map((v) => v.breakpoint)) !==
            JSON.stringify(b.variants.map((v) => v.breakpoint))) {
            changes.push("breakpoints");
        }
        const textA = collectTreeTexts(a.variants[0]?.tree);
        const textB = collectTreeTexts(b.variants[0]?.tree);
        if (JSON.stringify(textA) !== JSON.stringify(textB))
            changes.push("text");
        if (JSON.stringify(stripText(a.variants[0]?.tree)) !==
            JSON.stringify(stripText(b.variants[0]?.tree))) {
            changes.push("layout");
        }
        return changes;
    });
    diffById(entries, "component", previous.components, next.components, (c) => c.component_id, (a, b) => {
        const changes = [];
        if (JSON.stringify(a.variants) !== JSON.stringify(b.variants))
            changes.push("variants");
        if (a.classification !== b.classification)
            changes.push("classification");
        if (JSON.stringify(a.tree) !== JSON.stringify(b.tree))
            changes.push("structure");
        return changes;
    });
    const flatTokens = (m) => [
        ...m.tokens.colors.map((t) => ({ id: `color:${t.name}`, value: t.value })),
        ...m.tokens.typography.map((t) => ({
            id: `typography:${t.name}`,
            value: `${t.family} ${t.weight} ${t.size}/${t.line_height ?? "-"}`,
        })),
        ...m.tokens.spacing.map((t) => ({
            id: `spacing:${t.name}`,
            value: t.value,
        })),
        ...m.tokens.radius.map((t) => ({ id: `radius:${t.name}`, value: t.value })),
        ...m.tokens.shadows.map((t) => ({
            id: `shadow:${t.name}`,
            value: t.value,
        })),
    ];
    diffById(entries, "token", flatTokens(previous), flatTokens(next), (t) => t.id, (a, b) => (a.value !== b.value ? [`${a.value} → ${b.value}`] : []));
    diffById(entries, "asset", previous.assets, next.assets, (a) => a.asset_id, (a, b) => (a.classification !== b.classification ? ["classification"] : []));
    diffById(entries, "cms", previous.cms_candidates, next.cms_candidates, (c) => c.candidate_id, (a, b) => {
        const changes = [];
        if (a.item_count !== b.item_count)
            changes.push(`items ${a.item_count} → ${b.item_count}`);
        if (JSON.stringify(a.fields) !== JSON.stringify(b.fields))
            changes.push("fields");
        return changes;
    });
    entries.sort((a, b) => a.area.localeCompare(b.area) ||
        a.id.localeCompare(b.id) ||
        a.kind.localeCompare(b.kind));
    return {
        from_version: previous.source.figma_version,
        to_version: next.source.figma_version,
        changed: entries.length > 0 ||
            previous.source.fingerprint !== next.source.fingerprint,
        entries,
    };
}
function diffById(out, area, previous, next, idOf, changesOf) {
    const prevMap = new Map(previous.map((p) => [idOf(p), p]));
    const nextMap = new Map(next.map((n) => [idOf(n), n]));
    for (const [id, item] of nextMap) {
        const prev = prevMap.get(id);
        if (!prev) {
            out.push({ area, kind: "ADDED", id, detail: "新規" });
            continue;
        }
        const changes = changesOf(prev, item);
        if (changes.length > 0) {
            out.push({ area, kind: "CHANGED", id, detail: changes.join(", ") });
        }
    }
    for (const id of prevMap.keys()) {
        if (!nextMap.has(id)) {
            out.push({ area, kind: "REMOVED", id, detail: "削除" });
        }
    }
}
function collectTreeTexts(tree) {
    if (!tree)
        return [];
    const out = [];
    const visit = (node) => {
        if (node.text)
            out.push(node.text);
        for (const t of node.text_slots ?? [])
            out.push(t);
        for (const c of node.children ?? [])
            visit(c);
    };
    visit(tree);
    return out;
}
function stripText(tree) {
    if (!tree)
        return null;
    const visit = (node) => ({
        role: node.role,
        layout: node.layout,
        component: node.component,
        children: (node.children ?? []).map(visit),
    });
    return visit(tree);
}
