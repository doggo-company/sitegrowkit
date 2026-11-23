import type { HTMLElement } from "node-html-parser";

/**
 * 取り込んだナビを、CMS のメニューから描く形に置き換える（B-56）。
 *
 * ## なぜ要るのか
 *
 * 取り込んだ案件のナビは**原本の markup に焼き付いている**。
 * 管理画面には「メニューを編集できます」と出るが、**直しても公開面は変わらない。
 * エラーも出ない。**運用者が「直したのに変わらない」と言ってきたとき、説明できない。
 *
 * 🔴 実案件で実測すると、**同じナビが 12 ファイルに複製**されていて、
 * しかも**行き先が 4 種類に割れていた**（同じ「NEWS」がページによって 3 か所へ飛ぶ）。
 * standalone はページごとに html を丸ごと持つので、layout を 1 枚直しても足りない。
 *
 * ## 何を変えるか — 繰り返しの単位だけ
 *
 * 意匠は 1 バイトも動かさない。`<ul>` の中の `<li>` を**型として 1 つ抜き**、
 * そこへ CMS のメニューを流す。`<ul>` の class も `<li>` の中の入れ子もそのまま。
 *
 * ```
 * <ul class="nav-links">          ← そのまま
 *   <li><a href="#home">HOME</a></li>   ← 型として抜く
 *   ...                                 ← 残りは消す（項目は CMS が決める）
 * </ul>
 * ```
 *
 * ## メニューが空なら、原本のまま出す
 *
 * 🔴 **入れた瞬間にナビが消える形にしない。**メニューを 1 件も作っていない案件では、
 * 原本の並びをそのまま出す。`layouts.public` 側の `x-navigation` も同じ作法
 * （項目が 0 件なら既定の導線へ落ちる）。
 *
 * ## 文言は書き換えない
 *
 * `HOME` や `CONCEPT` は**メニュー側の名前**で置き換わる。原本の文言は
 * 「もとはこう書いてあった」として、メニューを作るときの元データになる
 * （`agentcms:import-menu` が拾う）。
 */
export interface ImportedNav {
  /** 置き換えた後の markup */
  html: string;
  /** 原本に書かれていた項目（メニューを起こすときの元データ） */
  items: { label: string; href: string }[];
}

/**
 * ナビの `<ul>` を、CMS のメニューから描く形へ置き換える。**置き換えなければ null**。
 *
 * @param menu 流すメニューの key（`header` / `footer`）
 */
export function substituteImportedNav(
  root: HTMLElement,
  listSelector: string,
  menu: string,
): ImportedNav | null {
  const list = root.querySelector(listSelector);

  if (list === null) return null;

  const units = list.childNodes.filter(
    (n): n is HTMLElement =>
      n.nodeType === 1 && (n as HTMLElement).tagName === "LI",
  );

  const first = units[0];

  // **繰り返しの単位が無いなら触らない。**型が取れないまま切ると、ナビごと消える
  if (first === undefined) return null;

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
  first.insertAdjacentHTML(
    "beforebegin",
    `@forelse (${menuCall(menu)} as $__item)\n${template}\n@empty\n`
      + `{{-- メニューが 1 件も無いので、取り込んだときの並びをそのまま出す --}}\n`
      + `${original}\n@endforelse\n`,
  );

  for (const unit of units) unit.remove();

  return { html: root.toString(), items };
}

/** メニューを引く式。**呼ぶ場所を 1 か所にする** */
export function menuCall(menu: string): string {
  return `app(\\AgentCms\\Core\\Menus\\MenuTree::class)->forKey('${menu}', request()->path())`;
}

/**
 * `<li>` を型にする。**文言と行き先だけを差し替え、入れ子と class は動かさない。**
 */
function unitTemplate(li: HTMLElement): string {
  const anchor = li.querySelector("a");

  if (anchor === null) return li.toString();

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

