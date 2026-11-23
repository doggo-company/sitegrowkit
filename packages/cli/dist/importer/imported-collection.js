/**
 * CMS 連携済みの節を、**意匠を保ったまま**一覧の部品にする（NEW-2 / collection）。
 *
 * ## なぜ静的な部品にできないのか
 *
 * 生成後の Blade では、施工事例とお知らせの節は既に `@foreach` で回っている。
 * 静的な部品へ写すと**中のデータが消える**（`$loop` が無くて 500 になるか、
 * 空で出るか、のどちらか）。**落とせないのが正しい。**
 *
 * ## 何を変えるか — ループの綴りだけ
 *
 * 意匠は 1 バイトも動かさない。書き換えるのは 3 つだけである。
 *
 * | もと | あと | なぜ |
 * | --- | --- | --- |
 * | `@foreach ($news as $item)` | `@foreach ($items as $item)` | 中身は binding から来る |
 * | `$item->title` | `$item['title']` | binding が渡すのは配列 |
 * | `$item->mainImage?->url()` | `Media::find($item['main_image_id'])?->url()` | 関連は id しか来ない |
 *
 * `$loop` はそのまま動く（自分の `@foreach` の中だから）。
 *
 * ## この版では、まわりの文言は編集できない
 *
 * 節の中の文字を項目にすると、**`@php` の中の文字列やループ本体まで拾う**。
 * 拾ったものを `{{ }}` に置き換えると Blade が壊れる。
 * **一覧の中身を出すことが先**で、まわりの文言は次の版で扱う。
 * できないことを黙って半端にやらない。
 */
import { componentType, componentViewName } from "./blade-html.js";
/** 1 ページに出す既定の件数（原本が何件出していたかは分からないので控えめに） */
const DEFAULT_LIMIT = 6;
/**
 * 節に `@foreach` が 1 つあれば、一覧の部品として組む。**無ければ null**。
 */
export function buildImportedCollection(key, label, html) {
    const loop = /@foreach\s*\(\s*\$([A-Za-z_][\w]*)\s+as\s+\$([A-Za-z_][\w]*)\s*\)/.exec(html);
    if (loop === null)
        return null;
    const [, sourceVar, itemVar] = loop;
    // **綴りを組み直さない**（D-4）。`blade-html.ts` が正本
    const type = componentType(key);
    const viewName = componentViewName(type);
    const body = rewriteLoop(html, loop[0], itemVar);
    const definition = JSON.stringify({
        type,
        label,
        category: "content",
        since: 1,
        propsSchema: {
            limit: {
                type: "number",
                min: 1,
                max: 50,
                default: DEFAULT_LIMIT,
                label: "表示件数",
            },
        },
        styleSchema: ["visibility"],
        bindable: [],
        allowedChildren: [],
        allowedParents: ["page"],
        renderer: { kind: "blade", view: viewName },
        // **型の名前で決めない。**宣言した component にだけ中身が渡る
        capabilities: { responsive: ["visibility"], container: false, collection: true },
    }, null, 2) + "\n";
    const view = `{{--
    取り込んだ一覧「${label}」（NEW-2）。**生成物** — 手で直すと再 apply 時に conflict になります。

    **意匠は原本の markup そのもの。**書き換えたのはループの綴りだけで、
    外枠・class・入れ子は 1 バイトも動かない。

    中身は \`binding\`（\`${sourceVar}\` → コンテンツタイプ）から来る。
    **件数は「表示件数」で変えられる**が、並び順と絞り込みは binding 側で決める。
--}}
${body}
`;
    return {
        definition,
        view,
        type,
        viewName,
        // **元の変数名をそのままコンテンツタイプとして使う。**
        // 存在しなければ CMS が「コンテンツタイプ [x] は存在しません」と断る。
        // 勝手に近い名前へ寄せない — 黙って別の一覧が出る方が悪い
        binding: {
            source: "collection",
            type: sourceVar,
            /*
             * **並び順は原本と同じにする。**
             *
             * 元のトップは `orderByDesc('published_at')` で出していた。
             * binding に並び順を書かないと既定の順（登録順）になり、
             * **同じ件数・同じ markup のまま、並びだけ違うものが出る**
             * （2026-08-28 に実案件で実測。バイト数まで一致していた）。
             */
            sort: [{ field: "published_at", direction: "desc" }],
        },
    };
}
/** ループの綴りだけを書き換える（意匠には触らない） */
function rewriteLoop(html, foreachTag, itemVar) {
    let out = html.replace(foreachTag, "@foreach ($items as $" + itemVar + ")");
    /*
     * **関連は id しか来ない。**`$item->mainImage?->url()` は
     * `main_image_id` を引いて Media を解く形へ落とす。
     *
     * ▷ **`?->url()` の付いたものだけを見る。**ただの `->foo` は次の規則が
     *   配列参照へ変えるので、先にこちらを処理する（順序が逆だと壊れる）。
     */
    out = out.replace(new RegExp(`\\$${itemVar}\\?->(\\w+)\\?->url\\(\\)`, "g"), (_m, rel) => `\\Modules\\Media\\Models\\Media::find($${itemVar}['${snake(rel)}_id'] ?? null)?->url()`);
    out = out.replace(new RegExp(`\\$${itemVar}->(\\w+)\\?->url\\(\\)`, "g"), (_m, rel) => `\\Modules\\Media\\Models\\Media::find($${itemVar}['${snake(rel)}_id'] ?? null)?->url()`);
    /*
     * 日付は文字列で来る（`2026-02-20T03:00:00.000000Z`）ので、
     * `?->format(...)` は Carbon で解いてから整える。
     */
    out = out.replace(new RegExp(`\\$${itemVar}->(\\w+)\\?->format\\(([^)]*)\\)`, "g"), (_m, field, fmt) => `\\Illuminate\\Support\\Carbon::parse($${itemVar}['${snake(field)}'] ?? null)?->format(${fmt})`);
    // 残りはただの項目参照。配列へ落とす
    out = out.replace(new RegExp(`\\$${itemVar}->(\\w+)`, "g"), (_m, field) => `$${itemVar}['${snake(field)}']`);
    return out;
}
/** `mainImage` → `main_image` */
function snake(name) {
    return name.replace(/([a-z0-9])([A-Z])/g, "$1_$2").toLowerCase();
}
