import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { substituteImportedNav } from "../src/importer/imported-nav.js";

/**
 * 取り込んだナビを CMS のメニューから描く（B-56）。
 *
 * ここで固定するのは 4 つ:
 *   1. **意匠に触らない**（`<ul>` の class・`<li>` の入れ子がそのまま）
 *   2. **繰り返しの単位だけ差し替える**
 *   3. **メニューが空なら原本のまま出す**（入れた瞬間にナビが消えない）
 *   4. **型が取れないなら触らない**
 */
const NAV = `<nav class="nav" id="navbar">
  <a href="#home" class="nav-logo"><img src="{{ asset('logo.svg') }}" alt="みどり造園"></a>
  <ul class="nav-links" id="navLinks">
    <li><a href="#home">HOME</a></li>
    <li><a href="#concept">CONCEPT</a></li>
    <li><a href="{{ route('company') }}">COMPANY</a></li>
  </ul>
  <button class="hamburger" aria-label="メニュー"><span></span></button>
</nav>`;

const run = (html = NAV, sel = "ul.nav-links") =>
  substituteImportedNav(parse(html), sel, "header");

describe("substituteImportedNav", () => {
  // ── 1. 意匠に触らない ────────────────────────────────

  it("ul の class と id を動かさない", () => {
    const out = run()!.html;

    expect(out).toContain(`<ul class="nav-links" id="navLinks">`);
  });

  it("ナビの外側は 1 バイトも動かさない", () => {
    const out = run()!.html;

    expect(out).toContain(`<nav class="nav" id="navbar">`);
    expect(out).toContain(`class="nav-logo"`);
    expect(out).toContain(`<button class="hamburger" aria-label="メニュー">`);
    expect(out).toContain("{{ asset('logo.svg') }}");
  });

  it("li の入れ子をそのまま型にする", () => {
    const out = run(
      NAV.replace(
        `<li><a href="#home">HOME</a></li>`,
        `<li class="is-active"><a href="#home"><span class="mark"></span>HOME</a></li>`,
      ),
    )!.html;

    expect(out).toContain(`<li class="is-active">`);
    expect(out).toContain(`<span class="mark"></span>`);
  });

  // ── 2. 繰り返しの単位だけ差し替える ──────────────────

  it("文言と行き先が CMS から入る", () => {
    const out = run()!.html;

    expect(out).toContain("{{ $__item['url'] }}");
    expect(out).toContain("{{ $__item['title'] }}");
  });

  it("型は 1 つだけ残し、残りは消す", () => {
    const out = run()!.html;
    const loop = out.slice(out.indexOf("@forelse"), out.indexOf("@empty"));

    // 回す側（メニューが在るとき）に原本の項目が残っていない
    expect(loop).not.toContain("CONCEPT");
    expect((out.match(/@forelse/g) ?? []).length).toBe(1);
  });

  it("原本の項目を、メニューを起こす元データとして返す", () => {
    // **黙って捨てない。**ここが `agentcms:import-menu` の入力になる
    expect(run()!.items).toEqual([
      { label: "HOME", href: "#home" },
      { label: "CONCEPT", href: "#concept" },
      { label: "COMPANY", href: "{{ route('company') }}" },
    ]);
  });

  it("文言が行き先の中にあっても取り違えない", () => {
    // `href` を先に差し替えないと、文言の置換が href の中まで届く
    const out = run(
      `<ul class="nav-links"><li><a href="/news">news</a></li></ul>`,
    )!.html;

    expect(out).toContain(`href="{{ $__item['url'] }}"`);
    expect(out).not.toContain(`href="{{ $__item['title'] }}"`);
  });

  // ── 3. メニューが空なら原本のまま ────────────────────

  it("メニューが空なら取り込んだ並びを出す", () => {
    // 🔴 入れた瞬間にナビが消える形にしない
    const out = run()!.html;
    const empty = out.slice(out.indexOf("@empty"), out.indexOf("@endforelse"));

    expect(empty).toContain(`<li><a href="#home">HOME</a></li>`);
    expect(empty).toContain(`<li><a href="#concept">CONCEPT</a></li>`);
  });

  it("判定は 1 か所だけ", () => {
    // 2 つ持つと「ナビも出ないし逃げ道も出ない」窓が開く
    const out = run()!.html;

    expect((out.match(/@forelse/g) ?? []).length).toBe(1);
    expect((out.match(/@empty/g) ?? []).length).toBe(1);
    expect((out.match(/@endforelse/g) ?? []).length).toBe(1);
  });

  it("メニューを引くのは 1 回だけ", () => {
    const out = run()!.html;

    expect((out.match(/MenuTree::class/g) ?? []).length).toBe(1);
  });

  it("@php を一切書かない", () => {
    /*
     * 🔴 Blade は `@php ... @endphp` を**ファイル全体から先に拾う**。
     * そこに `@php(` も引っかかるので、**同じファイルにブロック形が 1 つでもあると、
     * 短い形からそこまでが丸ごと食われる。**
     * 取り込んだフォームの view は `@php $fieldId = ...; @endphp` を持っているので、
     * 実際に `syntax error, unexpected token "@"` で 500 になった（実案件で実測）。
     */
    // **こちらがどちらを書いても、どこかで混ざる。書かないのが唯一の安全な道。**
    expect(run()!.html).not.toContain("@php");
  });

  it("指令を隣り合わせに書かない", () => {
    // Blade は `\B@` で始まるものだけを拾うので、続けると 2 つ目が認識されない
    const out = run()!.html;

    expect(out).not.toMatch(/@(?:endforelse|empty|endif|endforeach)@/);
  });

  it("どちらの形の @php が既に在っても増やさない", () => {
    const out = run(
      `<ul class="nav-links"><li><a href="#a">A</a></li></ul>@php($x = 1)`,
    )!.html;

    // 原本にある分はそのまま。**こちらが増やしていない**
    expect((out.match(/@php/g) ?? []).length).toBe(1);
  });

  // ── 4. 取れないなら触らない ──────────────────────────

  it("ul が無ければ触らない", () => {
    expect(run(`<nav><a href="/">HOME</a></nav>`)).toBeNull();
  });

  it("li が 1 つも無ければ触らない", () => {
    // 型が取れないまま切ると、ナビごと消える
    expect(run(`<ul class="nav-links"></ul>`)).toBeNull();
  });
});
