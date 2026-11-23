import { parse } from "node-html-parser";
import { buildImportedPageBlocks, freeBladeVariables, } from "./imported-blocks.js";
import { buildImportedLayout, importedLayoutView, splitDocumentShell, } from "./imported-layout.js";
/**
 * 組めなければ **理由の文字列**を返す（`null` にしない — 理由を落とすと dry-run で読めない）。
 */
export function buildImportedPage(input) {
    // 任意の Blade は実行しない。引数の無い既知の静的ルートの href だけを扱う。
    const editableBlade = input.blade.replace(/href="\{\{\s*route\('([^']+)'\)\s*\}\}([^"<>]*)"/g, (original, route, suffix) => {
        const url = input.routeUrls?.get(route);
        if (url === undefined || !url.startsWith("/") || url.startsWith("//") || /[{}"<>\s]/.test(url))
            return original;
        return `href="${url}${suffix}"`;
    });
    const { masked, restore } = maskPhpBlocks(editableBlade);
    const document = parse(masked, { comment: true });
    const shell = splitDocumentShell(document);
    if (shell === null) {
        return `${input.source}: <head> か <body> が読み取れないため、枠（layout）を組めません`;
    }
    const split = buildImportedPageBlocks(document, {
        scope: input.scope,
        inspect: restore,
    });
    if (split === null) {
        return `${input.source}: body 直下に <section> が 1 つも無く、中身の境界を決められません（枠と中身を取り違えて公開しないため、ページは作りません）`;
    }
    const files = [];
    const blocks = [];
    /*
     * 🔴 **1 つでも部品にできないブロックが在れば、ページごと作らない**（Codex 指摘 ①）。
     *
     * 以前は「そのブロックだけ外して名前を返す」だったが、`/` をページに切り替えると
     * **外した領域が公開面から消える**。警告を出しても、元サイトの一部が消えるのは不可。
     * 切替を止めれば静的な Blade がそのまま `/` に出て、何も消えない（編集できないだけ）。
     */
    const blockers = split.skipped.map((s) => `${s.name}: Blade の指令（${s.directive}）が入っていて、静的な部品にも一覧の部品にもできません`);
    for (const block of split.blocks) {
        const binding = block.block.binding;
        // 一覧の元が無い型は、CMS に断らせる前にこちらで分かる（`knownTypes` は apply が作る型も含む）
        if (binding !== undefined && !input.knownTypes.has(binding.type)) {
            blockers.push(`${block.files[0]?.source ?? block.key}: 一覧の中身の元になるコンテンツタイプ [${binding.type}] がこの案件にありません`);
            continue;
        }
        /*
         * 静的な部品の view が、部品の外の変数を参照していないか（`{{ $works->count() }}` 等）。
         * 指令が無くても `{{ $x }}` だけで `Undefined variable` になる。**500 を出す部品は作らない**
         */
        if (binding === undefined) {
            const view = block.files.find((f) => f.target.endsWith(".blade.php"));
            const free = view === undefined
                ? []
                : freeBladeVariables(restore(view.content.toString()), ["props"]);
            if (free.length > 0) {
                blockers.push(`${block.files[0]?.source ?? block.key}: 部品の外の変数 ${free.map((v) => `$${v}`).join(", ")} を参照しているため、部品にすると描けません`);
            }
        }
    }
    if (blockers.length > 0) {
        return (`${input.source}: 部品にできないブロックがあるため、ページは作りません（静的な Blade のまま公開され、何も消えません）— ` +
            blockers.join(" / "));
    }
    for (const block of split.blocks) {
        for (const file of block.files) {
            files.push({
                ...file,
                content: restore(file.content.toString()),
                source: `${input.source} ${file.source}`,
            });
        }
        blocks.push(block.block);
    }
    // 枠は**このページ専用**（slug で引く）。他のページには当たらない（Codex 指摘 ②）
    const layout = buildImportedLayout(shell.head, split.frame, shell.htmlAttrs, shell.bodyAttrs, input.slug);
    files.push({
        target: layout.target,
        content: restore(layout.content),
        source: `${input.source}（枠: 最初の <section> より前と <footer> から後。ページ [${input.slug}] 専用）`,
    });
    return {
        files,
        page: {
            source: input.source,
            title: input.title,
            slug: input.slug,
            publish: input.publish,
            front: input.front,
            blocks,
            layout: importedLayoutView(input.slug),
            passthrough: split.blocks
                .filter((b) => b.passthrough === true)
                .map((b) => b.files[0]?.source ?? b.key),
        },
    };
}
/**
 * `@php … @endphp` を、HTML として読ませない。
 *
 * 中身は PHP の文字列で、**HTML の断片が引用符の中に入っている**ことがある
 * （施工事例の装飾を配列で持つ形）。そのまま parse すると文字列の中の `<span>` が
 * 要素として解釈され、直列化し直したときに形が変わる。
 *
 * 印は `@` で始めておく — `extractSectionTemplate()` は `@` で始まる文字を
 * 項目にしないので、万一コメントが文字として拾われても編集項目にはならない。
 */
function maskPhpBlocks(blade) {
    const blocks = [];
    const masked = blade.replace(/@php\b[\s\S]*?@endphp/g, (whole) => {
        const index = blocks.push(whole) - 1;
        return `<!--@agentcms-php-${index}-->`;
    });
    const restore = (text) => text.replace(/<!--@agentcms-php-(\d+)-->/g, (_m, n) => blocks[Number(n)] ?? "");
    return { masked, restore };
}
