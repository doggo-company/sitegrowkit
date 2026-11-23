/**
 * 表示名から分類キーの下書きを作る（WP 一周 6 周目 2026-09-03）。
 *
 * サーバー（`TaxonomyKey::suggestFrom`）と**同じ規則**である。
 * ここは「打ちながら見える」ようにするためだけのもので、
 * 決めるのはサーバー — 空で送れば向こうが作る。
 *
 * 🔴 `-` はキーに使えない（`^[a-z][a-z0-9_]*$`）。`_` へ寄せる。
 * 日本語だけの名前からは作れないので**空**を返す。
 * その場合サーバーが `taxonomy_1` のような通し番号へ倒す —
 * **ここで番号を作らない**（既に使われているキーを知らないため、必ずずれる）。
 */
export function suggestTaxonomyKey(label: string): string {
    const slug = label
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 64);

    return /^[a-z][a-z0-9_]*$/.test(slug) ? slug : '';
}
