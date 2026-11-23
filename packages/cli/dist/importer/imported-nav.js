/**
 * ナビの `<ul>` を、CMS のメニューから描く形へ置き換える。**置き換えなければ null**。
 *
 * @param menu 流すメニューの key（`header` / `footer`）
 */
export function substituteImportedNav(root, listSelector, menu) {
    const list = root.querySelector(listSelector);
    if (list === null)
        return null;
    const units = list.childNodes.filter((n) => n.nodeType === 1 && n.tagName === "LI");
    const first = units[0];
    // **繰り返しの単位が無いなら触らない。**型が取れないまま切ると、ナビごと消える
    if (first === undefined)
        return null;
    const items = units.map((li) => ({
        label: li.text.trim(),
        href: li.querySelector("a")?.getAttribute("href") ?? "",
    }));
    const template = unitTemplate(first);
    const original = units.map((li) => li.toString()).join("\n");
    /*
     * 🔴 **`@php` を一切使わない。**
     *
     * Blade は `@php ... @endphp` を**ファイル全体から先に拾う**ので、
     * 短い形 `@php(...)` と混ざると**片方が丸ごと食われる**。
     * 取り込んだ view には**どちらの形も既に入っている**（フォームはブロック形、
     * 施工事例の詳細は短い形）ので、**こちらがどちらを書いても、どこかで混ざる。**
     * 2026-08-28 に両方で 500 を出した。
     *
     * `@forelse` は「回す」と「空のとき」を **1 つの指令**で書けるので、
     * 変数を置く必要がない。**判定も 1 か所のまま。**
     */
    first.insertAdjacentHTML("beforebegin", `@forelse (${menuCall(menu)} as $__item)\n${template}\n@empty\n`
        + `{{-- メニューが 1 件も無いので、取り込んだときの並びをそのまま出す --}}\n`
        + `${original}\n@endforelse\n`);
    for (const unit of units)
        unit.remove();
    return { html: root.toString(), items };
}
/** メニューを引く式。**呼ぶ場所を 1 か所にする** */
export function menuCall(menu) {
    return `app(\\AgentCms\\Core\\Menus\\MenuTree::class)->forKey('${menu}', request()->path())`;
}
/**
 * `<li>` を型にする。**文言と行き先だけを差し替え、入れ子と class は動かさない。**
 */
function unitTemplate(li) {
    const anchor = li.querySelector("a");
    if (anchor === null)
        return li.toString();
    const label = anchor.text;
    const href = anchor.getAttribute("href") ?? "";
    let out = li.toString();
    // **行き先を先に差し替える。**文言が href の中に現れることがあるので、
    // 文言から先に置くと href の中まで置き換わる
    if (href !== "") {
        out = out.replace(`href="${href}"`, `href="{{ $__item['url'] }}"`);
    }
    return out.replace(`>${label}<`, ">{{ $__item['title'] }}<");
}
