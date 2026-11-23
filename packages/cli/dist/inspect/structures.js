/**
 * 意味のある共通・繰り返し構造の導出（spec §10）。
 * 誤検出を無条件に Component 化しないため、confidence と reason を必ず付ける。
 * 最終判断は Plan / AI が行う。
 */
export function deriveCommonStructures(ctx) {
    const out = [];
    for (const s of ctx.shared) {
        const kind = s.kind === "header"
            ? "header"
            : s.kind === "footer"
                ? "footer"
                : s.kind === "nav"
                    ? "navigation"
                    : /\bcta\b/.test(s.selector)
                        ? "cta"
                        : null;
        if (kind === null)
            continue;
        out.push({
            kind,
            selector: s.selector,
            occurrences: s.pages.map((page) => ({ page, count: 1 })),
            confidence: "high",
            reason: `${s.pages.length} ページで正規化 HTML が完全一致（hash ${s.hash}）`,
        });
    }
    // breadcrumb: class / aria-label のパターン検出（内容はページ毎に異なるため hash 一致では見つからない）
    const breadcrumbs = new Map();
    for (const [page, root] of ctx.parsed) {
        for (const el of root.querySelectorAll("nav, ol, ul")) {
            const cls = el.getAttribute("class") ?? "";
            const aria = el.getAttribute("aria-label") ?? "";
            if (/breadcrumb/i.test(cls) || /パンくず|breadcrumb/i.test(aria)) {
                const sel = selectorOf(el);
                const list = breadcrumbs.get(sel) ?? [];
                list.push({ page, count: 1 });
                breadcrumbs.set(sel, list);
                break; // 1 ページ 1 カウント
            }
        }
    }
    for (const [selector, occurrences] of [...breadcrumbs.entries()].sort()) {
        out.push({
            kind: "breadcrumb",
            selector,
            occurrences: sortOcc(occurrences),
            confidence: occurrences.length >= 2 ? "high" : "medium",
            reason: `class / aria-label のパンくずパターンが ${occurrences.length} ページで検出`,
        });
    }
    // section heading: 同一 class の見出しが複数ページに出現
    const headings = new Map();
    for (const [page, root] of ctx.parsed) {
        const perSelector = new Map();
        for (const el of root.querySelectorAll("h1, h2, h3")) {
            const cls = (el.getAttribute("class") ?? "").trim();
            if (!cls)
                continue;
            const sel = `${el.tagName.toLowerCase()}.${cls.split(/\s+/).join(".")}`;
            perSelector.set(sel, (perSelector.get(sel) ?? 0) + 1);
        }
        for (const [sel, count] of perSelector) {
            const list = headings.get(sel) ?? [];
            list.push({ page, count });
            headings.set(sel, list);
        }
    }
    for (const [selector, occurrences] of [...headings.entries()].sort()) {
        if (occurrences.length < 2)
            continue;
        out.push({
            kind: "section_heading",
            selector,
            occurrences: sortOcc(occurrences),
            confidence: "medium",
            reason: `同一 class の見出しが ${occurrences.length} ページで使用（共通スタイルの見出しパターン）`,
        });
    }
    // card grid: 繰り返し構造のうち image + heading を持つもの
    const cardGroups = new Map();
    for (const r of ctx.repeats) {
        const withImage = r.items.filter((i) => i.image && i.heading).length;
        if (withImage < Math.max(2, r.count - 1))
            continue;
        const key = `${r.container}|${r.item}`;
        const list = cardGroups.get(key) ?? [];
        list.push({ page: r.page, count: r.count });
        cardGroups.set(key, list);
    }
    for (const [key, occurrences] of [...cardGroups.entries()].sort()) {
        const [container, item] = key.split("|");
        out.push({
            kind: "card_grid",
            selector: `${container} > ${item}`,
            occurrences: sortOcc(occurrences),
            confidence: occurrences.length >= 2 ? "high" : "medium",
            reason: `image + heading を持つカードが ${occurrences
                .map((o) => `${o.page}:${o.count}件`)
                .join(" / ")} 繰り返されている`,
        });
    }
    for (const form of ctx.forms) {
        out.push({
            kind: "form",
            selector: form.selector,
            occurrences: [{ page: form.page, count: 1 }],
            confidence: "high",
            reason: `${form.fields.length} フィールドのフォーム（method=${form.method}）`,
        });
    }
    return out.sort((a, b) => a.kind.localeCompare(b.kind) || a.selector.localeCompare(b.selector));
}
/** JS の DOM セレクタを各ページへ突き合わせ、既存 JS が動く領域を特定する */
export function deriveInteractiveRegions(js, parsed) {
    const out = [];
    for (const file of js.files) {
        for (const selector of file.dom_selectors) {
            const pages = [];
            for (const [page, root] of parsed) {
                try {
                    if (root.querySelectorAll(selector).length > 0)
                        pages.push(page);
                }
                catch {
                    // node-html-parser が解釈できないセレクタは無視（決定論的にスキップ）
                }
            }
            out.push({
                selector,
                source: file.path,
                events: file.event_listeners,
                pages: pages.sort(),
            });
        }
    }
    return out.sort((a, b) => a.selector.localeCompare(b.selector) || a.source.localeCompare(b.source));
}
/**
 * Static Design Asset と CMS Media 候補の分類（spec §14–15）。
 * すべてを Media Library へ登録しない — 分類根拠を必ず持つ。
 */
export function classifyImages(ctx) {
    const static_assets = [];
    const media_candidates = [];
    for (const path of [...ctx.images].sort()) {
        const content = ctx.contentImages.get(path);
        if (content) {
            media_candidates.push({
                path,
                reason: `繰り返し構造 ${content} 内で使用（コンテンツ画像）`,
                confidence: "high",
                repeat: content,
            });
            continue;
        }
        let reason;
        let confidence;
        const auxiliary = ctx.auxiliaryImages.get(path);
        const decorative = ctx.decorativeImages.get(path);
        if (auxiliary) {
            static_assets.push({
                path,
                reason: `コンテンツ繰り返し ${auxiliary} 内の付随画像（装飾 — Static Design Asset）`,
                confidence: "medium",
                repeat: auxiliary,
            });
            continue;
        }
        if (decorative) {
            static_assets.push({
                path,
                reason: `テキストを持たない装飾繰り返し ${decorative} 内で使用（Static Design Asset）`,
                confidence: "medium",
                repeat: decorative,
            });
            continue;
        }
        if (ctx.sharedImages.has(path)) {
            reason = "共通 header / footer 内で使用（ロゴ等のデザイン asset）";
            confidence = "high";
        }
        else if (ctx.cssImages.has(path)) {
            reason = "CSS から参照（背景・装飾 asset）";
            confidence = "high";
        }
        else if (ctx.pageImages.has(path)) {
            reason = "単発使用のページ内画像（コンテンツ画像の可能性あり — REVIEW）";
            confidence = "medium";
        }
        else {
            reason = "参照が見つからない（未使用の可能性 — 削除はしない）";
            confidence = "low";
        }
        static_assets.push({ path, reason, confidence, repeat: null });
    }
    return { static_assets, media_candidates };
}
function sortOcc(occ) {
    return [...occ].sort((a, b) => a.page.localeCompare(b.page));
}
export function selectorOf(el) {
    const id = el.getAttribute("id");
    if (id)
        return `${el.tagName.toLowerCase()}#${id}`;
    const cls = (el.getAttribute("class") ?? "")
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .join(".");
    return cls ? `${el.tagName.toLowerCase()}.${cls}` : el.tagName.toLowerCase();
}
