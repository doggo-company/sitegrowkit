import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { substituteImportedFooterNav } from "../src/importer/imported-footer-nav.js";

/**
 * `<ul>` を使っていないフッターの導線を CMS のメニューから描く（B-56 / footer）。
 *
 * ここで固定するのは 4 つ:
 *   1. **並びの外側は分岐に入れない**（見出しが消えない）
 *   2. **原本の折り返し位置をそのまま持つ**（規則を発明しない）
 *   3. **区切りは最後だけ付けない**（原本の読み取り）
 *   4. **並びが取れないなら触らない**
 */
const FOOTER = `<div class="footer-sticker-alt">
  <h4>🗺 サイトマップ</h4>
  <a href="#home">HOME</a> / <a href="#concept">CONCEPT</a> / <a href="#services">SERVICES</a><br>
  <a href="#gallery">GALLERY</a> / <a href="{{ route('company') }}">COMPANY</a> / <a href="#news">NEWS</a><br>
  <a href="#recruit">RECRUIT</a> /
  <a href="#contact">CONTACT</a>
</div>`;

const run = (html = FOOTER, sel = ".footer-sticker-alt") =>
  substituteImportedFooterNav(parse(html), sel, "footer");

describe("substituteImportedFooterNav", () => {
  // ── 1. 並びの外側 ────────────────────────────────────

  it("見出しは分岐の外に残す", () => {
    // 🔴 丸ごと差し替えると、**メニューが在るときだけ見出しが消える**
    const out = run()!.html;
    const head = out.slice(0, out.indexOf("@php"));

    expect(head).toContain("<h4>🗺 サイトマップ</h4>");
  });

  it("入れ物の class を動かさない", () => {
    expect(run()!.html).toContain(`<div class="footer-sticker-alt">`);
  });

  // ── 2. 原本の折り返しを記録して持つ ──────────────────

  it("原本の折り返し位置をそのまま持つ", () => {
    // 🔴 **「3 つごと」とは原本のどこにも書いていない。**
    //    8 項目でたまたまそう見えるだけなので、位置を記録する
    expect(run()!.rows).toEqual([3, 3, 2]);
    // 折り返す位置は**生成の時点で数え切って埋める**（実行時に変数を持たない）
    expect(run()!.html).toContain("in_array($loop->index, [2,5], true)");
  });

  it("折り返しが無ければ 1 行として持つ", () => {
    const r = run(
      `<div class="footer-sticker-alt"><a href="/a">A</a> / <a href="/b">B</a></div>`,
    );

    expect(r!.rows).toEqual([2]);
  });

  it("折り返しの数だけ行を持つ", () => {
    const r = run(
      `<div class="footer-sticker-alt"><a href="/a">A</a><br><a href="/b">B</a><br><a href="/c">C</a></div>`,
    );

    expect(r!.rows).toEqual([1, 1, 1]);
  });

  // ── 3. 区切り ────────────────────────────────────────

  it("最後の項目には区切りを付けない", () => {
    // 原本がそうなっている（意匠の読み取りであって推測ではない）
    expect(run()!.html).toContain("@if (! $loop->last)");
  });

  it("区切りと改行を出し分ける", () => {
    const out = run()!.html;

    expect(out).toContain("<br>");
    expect(out).toContain(" / ");
  });

  // ── メニューが空なら原本のまま ────────────────────────

  it("メニューが空なら取り込んだ並びを出す", () => {
    const out = run()!.html;
    const empty = out.slice(out.indexOf("@empty"), out.indexOf("@endforelse"));

    expect(empty).toContain(`<a href="#home">HOME</a>`);
    expect(empty).toContain(`<a href="#contact">CONTACT</a>`);
  });

  it("判定は 1 か所だけ", () => {
    const out = run()!.html;

    expect((out.match(/@forelse/g) ?? []).length).toBe(1);
    expect((out.match(/@empty/g) ?? []).length).toBe(1);
  });

  it("指令を隣り合わせに書かない", () => {
    /*
     * 🔴 Blade の指令は `\B@` で始まるものだけを拾うので、`@endif@endif` と続けると
     * **2 つ目が指令として認識されず、そのまま文字として残る。**
     * 対応が崩れて `unexpected token "endforeach"` で 500 になった（実案件で実測）。
     */
    const out = run()!.html;

    expect(out).not.toMatch(/@endif@/);
    expect(out).not.toMatch(/@endforeach@/);
    expect(out).not.toMatch(/@endphp@/);
  });

  it("@php を一切書かない", () => {
    // どちらの形で書いても、取り込んだ view のもう一方と混ざって食われる
    expect(run()!.html).not.toContain("@php");
  });

  it("原本より増えたら、最初の行と同じ幅で続ける", () => {
    // **こちらが決めた既定**。原本には何も書かれていないので、推測ではなく宣言
    expect(run()!.html).toContain("($loop->index - 5) % 3 === 0");
  });

  // ── 4. 取れないなら触らない ──────────────────────────

  it("入れ物が無ければ触らない", () => {
    expect(run(`<div class="other"><a href="/a">A</a></div>`)).toBeNull();
  });

  it("リンクが無ければ中身を 1 バイトも動かさない", () => {
    const box = parse(`<div class="footer-sticker-alt"><h4>見出し</h4></div>`);
    const target = box.querySelector(".footer-sticker-alt")!;

    expect(substituteImportedFooterNav(box, ".footer-sticker-alt", "footer")).toBeNull();
    expect(target.innerHTML).toBe("<h4>見出し</h4>");
  });

  it("リンクが 1 本なら触らない", () => {
    // 1 本しか無いものは「並び」ではない
    expect(
      run(`<div class="footer-sticker-alt"><a href="/a">A</a></div>`),
    ).toBeNull();
  });

  // ── 原本の項目 ────────────────────────────────────────

  it("原本の項目を、メニューを起こす元データとして返す", () => {
    expect(run()!.items.map((i) => i.label)).toEqual([
      "HOME",
      "CONCEPT",
      "SERVICES",
      "GALLERY",
      "COMPANY",
      "NEWS",
      "RECRUIT",
      "CONTACT",
    ]);
  });

  it("文言が行き先の中にあっても取り違えない", () => {
    const out = run(
      `<div class="footer-sticker-alt"><a href="/news">news</a> / <a href="/x">X</a></div>`,
    )!.html;

    expect(out).toContain(`href="{{ $__item['url'] }}"`);
    expect(out).not.toContain(`href="{{ $__item['title'] }}"`);
  });
});
