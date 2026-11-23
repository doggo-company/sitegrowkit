function collectionsOf(plan) {
    const seeds = plan.seeds;
    if (Array.isArray(seeds.collections))
        return seeds.collections;
    const out = [];
    if (seeds.works && seeds.works.length > 0) {
        out.push({
            type: "works",
            model: "Modules\\Works\\Models\\Work",
            shape: "cards",
            rows: seeds.works,
        });
    }
    if (seeds.news && seeds.news.length > 0) {
        out.push({
            type: "news",
            model: "Modules\\News\\Models\\News",
            shape: "dated",
            rows: seeds.news,
        });
    }
    return out;
}
/** 最初の `cards` collection（無ければ null） */
export function cardsOf(plan) {
    return (collectionsOf(plan).find((c) => c.shape === "cards") ?? null);
}
/** 最初の `dated` collection（無ければ null） */
export function datedOf(plan) {
    return (collectionsOf(plan).find((c) => c.shape === "dated") ?? null);
}
/** `cards` の行（無ければ空） */
export function cardRows(plan) {
    return cardsOf(plan)?.rows ?? [];
}
/** `dated` の行（無ければ空） */
export function datedRows(plan) {
    return datedOf(plan)?.rows ?? [];
}
/** 投入する行が 1 つでもあるか */
export function hasSeedRows(plan) {
    return collectionsOf(plan).some((c) => c.rows.length > 0);
}
