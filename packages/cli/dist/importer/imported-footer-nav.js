import { menuCall } from "./imported-nav.js";
/**
 * フッターの導線を CMS のメニューから描く形へ置き換える。**置き換えなければ null**。
 */
export function substituteImportedFooterNav(root, containerSelector, menu) {
    const box = root.querySelector(containerSelector);
    if (box === null)
        return null;
    const links = box.querySelectorAll("a");
    // **繰り返しが無いなら触らない。**1 本しか無いものは「並び」ではない
    if (links.length < 2)
        return null;
    const items = links.map((a) => ({
        label: a.text.trim(),
        href: a.getAttribute("href") ?? "",
    }));
    /*
     * 🔴 **並びの外側は分岐に入れない。**
     *
     * この入れ物には見出し（`<h4>🗺 サイトマップ</h4>`）も入っている。
     * 丸ごと差し替えると、**メニューが在るときだけ見出しが消える**。
     * 最初の `<a>` の手前までは、そのまま外に残す。
     */
    const inner = box.innerHTML;
    const start = inner.indexOf("<a");
    const end = inner.lastIndexOf("</a>") + "</a>".length;
    /*
     * 並びの範囲が取れないなら触らない。
     *
     * ▷ **ここは実際には通らない。**上で `<a>` が 2 本以上あることを見ているので、
     *   `innerHTML` には必ず `<a` が含まれる。**変異を打っても赤くならない。**
     *
     * ▷ **試験が足りないのではない** — 「リンクが無ければ中身を動かさない」で
     *   固定してある。残してあるのは、上の番人を後から緩めたときに
     *   **`slice(-1)` で中身を壊さない**ため（同じ判断を 2 段で持つのではなく、
     *   境界計算そのものの前提を書いている）。
     */
    if (start === -1 || end <= start)
        return null;
    const head = inner.slice(0, start);
    const list = inner.slice(start, end);
    const tail = inner.slice(end);
    const rows = rowWidths(list, links.length);
    const template = linkTemplate(links[0]);
    box.innerHTML = head + renderLoop(template, rows, menu, list) + tail;
    return { html: root.toString(), items, rows };
}
/**
 * 原本が何項目ごとに折り返していたか。
 *
 * `<br>` の前に何個の `<a>` があったかを数えるだけ。**規則は当てはめない。**
 */
function rowWidths(html, total) {
    const rows = [];
    let count = 0;
    for (const token of html.split(/(<br\s*\/?>)/i)) {
        if (/^<br/i.test(token)) {
            rows.push(count);
            count = 0;
            continue;
        }
        count += (token.match(/<a[\s>]/gi) ?? []).length;
    }
    if (count > 0)
        rows.push(count);
    // 折り返しが 1 つも無いなら「全部 1 行」
    return rows.length === 0 ? [total] : rows;
}
/** `<a>` を型にする。**文言と行き先だけを差し替える。** */
function linkTemplate(a) {
    const label = a.text;
    const href = a.getAttribute("href") ?? "";
    let out = a.toString();
    // **行き先を先に差し替える**（文言から置くと href の中まで届く）
    if (href !== "") {
        out = out.replace(`href="${href}"`, `href="{{ $__item['url'] }}"`);
    }
    return out.replace(`>${label}<`, ">{{ $__item['title'] }}<");
}
/**
 * ループを組む。
 *
 * 🔴 **`@php` を一切使わない。**Blade は `@php ... @endphp` をファイル全体から
 * 先に拾うので、短い形と混ざると片方が丸ごと食われる。取り込んだ view には
 * **どちらの形も既に入っている**（2026-08-28 に両方で 500 を出した）。
 *
 * 折り返しの位置は**生成の時点で数え切って、リテラルとして埋める**ので、
 * 実行時に変数を持つ必要がない。
 *
 * - 原本の項目数までは、記録した位置で折り返す（**原本と 1 バイトも同じ**）
 * - それを超えたぶんは、**最初の行と同じ幅**で続ける（増えた日の既定）
 */
function renderLoop(template, rows, menu, original) {
    // 折り返す位置（0 起点）。`[3,3,2]` → `[2,5]`（最後の行の後ろには入れない）
    const breaks = [];
    let at = -1;
    for (const w of rows.slice(0, -1)) {
        at += w;
        breaks.push(at);
    }
    const first = rows[0] ?? 1;
    const last = at;
    const rest = `($loop->index > ${last} && ($loop->index - ${last}) % ${first} === 0)`;
    return (`\n@forelse (${menuCall(menu)} as $__item)\n`
        + `${template}\n`
        + `@if (! $loop->last)\n`
        + `@if (in_array($loop->index, ${JSON.stringify(breaks)}, true) || ${rest})<br>@else / @endif\n`
        + `@endif\n`
        + `@empty\n`
        + `{{-- メニューが 1 件も無いので、取り込んだときの並びをそのまま出す --}}\n`
        + `${original}\n`
        + `@endforelse\n`);
}
