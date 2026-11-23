import { buildImportedSectionComponent, componentType, extractSectionTemplate, } from "./blade-html.js";
import { buildImportedCollection } from "./imported-collection.js";
/** 中身の始まりと終わりを決める要素（それ以外は位置で判断しない） */
const CONTENT_START = "SECTION";
const CONTENT_END = "FOOTER";
/**
 * body 直下の子を、枠と中身に割る。**中身が取れなければ null**。
 */
export function buildImportedPageBlocks(document, options = {}) {
    if (document === null)
        return null;
    // 綴りは `componentType()` に決めさせる（D-4）。`imported_` は鍵の側では外す
    const scope = options.scope === undefined || options.scope === ""
        ? ""
        : componentType(options.scope).slice("imported_".length);
    const scoped = (key) => scope === "" ? key : `${scope}_${key}`;
    const body = document.querySelector("body") ?? document;
    const bodyChildren = body.childNodes.filter((node) => node.nodeType === 1 && node.tagName !== null);
    /*
     * ---- 境界の決め方は 2 通り ----
     *
     * (a) body 直下に `<section>` が在る: 最初の `<section>` から最初の `<footer>` まで（従来）
     * (b) 無いが body 直下に `<main>` が 1 つ在る: **`<main>` の中の子が中身**、`<main>` の前後
     *     （header / nav / footer / script）が枠。`<main>` 自身は枠が包み直す（`frame.wrap`）。
     *     一般的な `body > header + main + footer` の原本はこの形。
     *
     * どちらでもなければ割らない。「たぶんここから」で切らない。
     */
    let children = bodyChildren;
    let start = children.findIndex((el) => el.tagName === CONTENT_START);
    let footer = -1;
    let before = [];
    let after = [];
    let wrap;
    if (start !== -1) {
        footer = children.findIndex((el) => el.tagName === CONTENT_END);
        before = children.slice(0, start);
        after = footer === -1 ? [] : children.slice(footer);
    }
    else {
        const mains = bodyChildren.filter((el) => el.tagName === "MAIN");
        const main = mains.length === 1 ? mains[0] : undefined;
        if (main === undefined)
            return null;
        children = main.childNodes.filter((node) => node.nodeType === 1 && node.tagName !== null);
        if (children.length === 0)
            return null;
        start = 0;
        const mainIndex = bodyChildren.indexOf(main);
        before = bodyChildren.slice(0, mainIndex);
        after = bodyChildren.slice(mainIndex + 1);
        wrap = {
            open: `<main${main.rawAttrs === "" ? "" : ` ${main.rawAttrs}`}>`,
            close: "</main>",
        };
    }
    const end = footer === -1 ? children.length : footer;
    const blocks = [];
    const skipped = [];
    const taken = new Set();
    for (const [offset, el] of children.slice(start, end).entries()) {
        // `<script>` は中身ではない（枠の側で読み込む）
        if (el.tagName === "SCRIPT")
            continue;
        /*
         * 🔴 **Blade の綴りが入っているブロックは、部品にしない。**
         *
         * `@php` / `@foreach` / `$loop` は**その場でしか意味を持たない**。
         * 部品へ写すと、管理画面の一覧には正しく並ぶので**画面上は成功に見える**が、
         * 描こうとした瞬間に `Undefined variable $loop` で 500 になる
         * （2026-08-28 に実案件の `#gallery` で実測）。
         *
         * ▷ **ページごと断らない。**そこにあるのは大抵 CMS 連携済みの領域
         *   （施工事例・お知らせ）で、**静的でない方が正しい**。
         *   残りの静的なブロックまで作れなくする理由が無いので、
         *   **そのブロックだけ外して、名前を返す**。黙って落とさない。
         */
        const directive = bladeDirective(el.toString());
        if (directive !== null) {
            /*
             * **CMS 連携済みの節は、一覧の部品として組み直す。**
             *
             * `@foreach` が在るなら「中身が CMS から来る」ということなので、
             * ループの綴りだけ書き換えて意匠は残す。中身は `binding` から出る。
             *
             * ▷ **`@foreach` が無い Blade は、まだ組み直せない**（`@if` だけ、等）。
             *   そこは外して名前を返す。黙って落とさない。
             */
            const key = uniqueKey(scoped(blockKey(el, start + offset)), taken);
            const collection = buildImportedCollection(key, blockLabel(el, undefined), el.toString());
            if (collection === null) {
                /*
                 * **一覧にできない Blade は、原本のまま素通しする**（Codex 指摘 ①）。
                 *
                 * 外して名前を返すだけだと、`/` をページに切り替えた瞬間に
                 * **その領域が公開面から消える**。警告が出ても消えるのは不可。
                 *
                 * ▷ **素通しできるのは、そのブロックの中だけで意味が閉じている Blade**
                 *   （`@if (true)` / `@php ($x = 1)` … `{{ $x }}` / `@error` の `$message`）。
                 *   外の変数（`$works` など、静的な Blade では controller が渡していたもの）を
                 *   参照しているものは、部品にすると `Undefined variable` で 500 になるので
                 *   **外す** — そして呼び出し側はページ全体の切替を止める（黙って落とさない）。
                 */
                const free = freeBladeVariables((options.inspect ?? ((h) => h))(el.toString()));
                const structural = STRUCTURAL_DIRECTIVES.has(directive);
                if (free.length > 0 || structural) {
                    taken.delete(key);
                    skipped.push({
                        name: `${el.tagName.toLowerCase()}${identOf(el)}`,
                        directive: structural
                            ? directive
                            : `${directive}（外の変数 ${free.map((v) => `$${v}`).join(", ")} を参照）`,
                    });
                    continue;
                }
                const label = `${blockLabel(el, undefined)}（原本のまま・画面からは編集できません）`;
                const passthrough = buildImportedSectionComponent(key, label, {
                    unit: el.toString(),
                    props: [],
                });
                blocks.push({
                    key,
                    label,
                    passthrough: true,
                    files: [
                        {
                            target: `content/components/${passthrough.type}.json`,
                            content: passthrough.definition,
                            source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                        },
                        {
                            target: `resources/views/${passthrough.viewName.replaceAll(".", "/")}.blade.php`,
                            content: passthrough.view,
                            source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                        },
                    ],
                    block: { type: passthrough.type, props: {} },
                });
                continue;
            }
            blocks.push({
                key,
                label: blockLabel(el, undefined),
                files: [
                    {
                        target: `content/components/${collection.type}.json`,
                        content: collection.definition,
                        source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                    },
                    {
                        target: `resources/views/${collection.viewName.replaceAll(".", "/")}.blade.php`,
                        content: collection.view,
                        source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                    },
                ],
                block: {
                    type: collection.type,
                    props: {},
                    binding: collection.binding,
                },
            });
            continue;
        }
        const template = extractSectionTemplate(el);
        // 編集できる文字が 1 つも無いブロック（帯・区切りなど）は、
        // **落とさずに素通しの部品として置く** — 消すと意匠が変わる
        const key = uniqueKey(scoped(blockKey(el, start + offset)), taken);
        const component = buildImportedSectionComponent(key, blockLabel(el, template?.props[0]?.value), template ?? { unit: el.toString(), props: [] });
        blocks.push({
            key,
            label: blockLabel(el, template?.props[0]?.value),
            files: [
                {
                    target: `content/components/${component.type}.json`,
                    content: component.definition,
                    source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                },
                {
                    target: `resources/views/${component.viewName.replaceAll(".", "/")}.blade.php`,
                    content: component.view,
                    source: `${el.tagName.toLowerCase()}${identOf(el)}`,
                },
            ],
            block: { type: component.type, props: component.props },
        });
    }
    return {
        blocks,
        skipped,
        frame: wrap === undefined ? { before, after } : { before, after, wrap },
    };
}
/**
 * Blade の綴りが混ざっていないか。**在れば最初の 1 つを返す**。
 *
 * `{{ }}` は見ない — **原本の HTML にも書かれうる**（別のテンプレート言語の名残など）し、
 * 出力するだけなら 500 にはならない。**壊すのは指令と変数**の方である。
 */
function bladeDirective(html) {
    const found = /@(?:php|if|foreach|forelse|for|while|include|extends|section|yield|stack|push|props)\b|\$loop\b/.exec(html);
    return found === null ? null : found[0];
}
/**
 * ブロックの外に何かを要求する指令。**素通しの部品にはできない**
 * （`@include` の先が変数を要求する・`@section` は layout の中でしか意味を持たない）。
 */
const STRUCTURAL_DIRECTIVES = new Set([
    "@include",
    "@extends",
    "@section",
    "@yield",
    "@stack",
    "@push",
    "@props",
]);
/**
 * Blade の中で使われているが、**そのブロックの中で束ねられていない**変数。
 *
 * 空なら、そのブロックは部品の view として描いても `Undefined variable` にならない。
 * 判定は**保守的**にする — 分からないものは「外の変数」側へ倒す（500 を出すより外す方がよい）。
 *
 * 束ねられているとみなすもの:
 * - `@foreach ($xs as $k => $v)` / `@forelse` の `$k` / `$v`、その中の `$loop`
 * - `$x = …` と代入されているもの（`@php ($x = 1)` を含む）
 * - `@error('…')` の中の `$message`
 * - `$errors`（公開面の view には常に共有される）
 */
export function freeBladeVariables(html, 
/** 呼び出し側が渡してくれる変数（部品の view なら `props`） */
extraBound = []) {
    const bound = new Set(["errors", ...extraBound]);
    for (const m of html.matchAll(/@fore(?:ach|lse)\s*\([^)]*?\bas\s+\$(\w+)(?:\s*=>\s*\$(\w+))?/g)) {
        bound.add(m[1]);
        if (m[2] !== undefined)
            bound.add(m[2]);
    }
    if (/@fore(?:ach|lse)\b/.test(html))
        bound.add("loop");
    if (/@error\b/.test(html))
        bound.add("message");
    for (const m of html.matchAll(/\$([A-Za-z_]\w*)\s*=(?![=>])/g)) {
        bound.add(m[1]);
    }
    const free = new Set();
    for (const m of html.matchAll(/\$([A-Za-z_]\w*)/g)) {
        const name = m[1];
        if (!bound.has(name))
            free.add(name);
    }
    return [...free].sort();
}
/** `#id` か `.class` か、どちらも無ければ位置 */
function identOf(el) {
    const id = el.getAttribute("id");
    if (id !== undefined && id !== "")
        return `#${id}`;
    const cls = (el.getAttribute("class") ?? "").split(/\s+/).filter(Boolean)[0];
    return cls === undefined ? "" : `.${cls}`;
}
function blockKey(el, index) {
    const ident = identOf(el).slice(1);
    if (ident === "")
        return `block_${index + 1}`;
    /*
     * **機械名にするのは `componentType()` の仕事**（D-4）。
     * ここで独自に正規化すると、**同じ入力に 2 通りの答え**ができる
     * （実際に `GALLERY` → `gallery` と `GALLERY` に割れていた）。
     *
     * `componentType()` は `imported_` を付けて返すので、鍵の側では外す。
     */
    const key = componentType(ident).slice("imported_".length);
    return key === "" ? `block_${index + 1}` : key;
}
/** 同じ綴りが 2 つ出たら番号を足す（後ろのものが前のものを潰さない） */
function uniqueKey(key, taken) {
    let candidate = key;
    for (let n = 2; taken.has(candidate); n += 1)
        candidate = `${key}_${n}`;
    taken.add(candidate);
    return candidate;
}
/**
 * 管理画面に出す名前。**原本の文言があればそれを添える**（B-25）。
 * class 名だけを出すと、編集する人にはどのブロックか分からない。
 */
function blockLabel(el, firstText) {
    const ident = identOf(el);
    const name = ident === "" ? el.tagName.toLowerCase() : ident.slice(1);
    // **名前の頭に見えない文字を置かない。**値の側は原本の `&nbsp;` を残すので、
    // そのまま出すと「（　　富山県…）」のように頭が空く
    const shown = firstText?.trim() ?? "";
    if (shown === "")
        return name;
    const clipped = shown.length > 18 ? `${shown.slice(0, 18)}…` : shown;
    return `${name}（${clipped}）`;
}
