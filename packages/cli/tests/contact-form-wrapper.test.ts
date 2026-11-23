import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { substituteContactForm } from "../src/importer/blade-html.js";

/**
 * 取り込んだフォームの**包みを壊さない**（R-16 ②）。
 *
 * 視覚 A/B で「送信ボタンの中央寄せが消える」差が出た。原因は文言でも CSS でもなく、
 * **`<p>` の中へ `<div>` を差し込んでいた**ことである。
 * `<p>` は段落の中身しか持てないので、ブラウザは `<div>` の手前で `<p>` を閉じる。
 * 後ろに居たボタンは `<p>` の外へ出て、`<p>` の `text-align:center` を失う。
 *
 * ここが厄介なのは、**生成物を読んでも気づけない**点である。
 * 変換に使っている node-html-parser は `<p>` を閉じないので、
 * 出力文字列の上ではボタンは `<p>` の中に居るように見える。
 * 実測は chromium に読ませて確かめた（親が FORM になり text-align が start へ戻る）。
 */
function convert(inner: string): string {
  const main = parse(`<main>${inner}</main>`);

  substituteContactForm(main, "form", { name: "name" });

  return main.toString();
}

describe("フォームの入れ物と整列を保つ", () => {
  const wrapped = `
    <form action="#" method="get">
      <p><input type="text" name="name"></p>
      <p style="text-align:center"><button type="submit">送信</button></p>
    </form>`;

  it("モックの押せない送信ボタンを、押せるようにする", () => {
    // 🔴 残すと**フォームは動いているのに押せない**。エラーは出ない
    //    （CMS フォーム側で同じ抜けを 2026-08-28 に実測した。こちらにも試験が無かった）
    const out = convert(`
      <form action="#" method="get">
        <p><input type="text" name="name"></p>
        <p><button type="submit" disabled style="opacity:0.6">送信（準備中）</button></p>
      </form>`);

    expect(out).not.toContain("disabled");
    expect(out).not.toContain("opacity:0.6");
    // 文言は書き換えない（Preserve First）
    expect(out).toContain("送信（準備中）");
  });

  it("段落の中へ block を入れない（Turnstile は段落の外）", () => {
    const out = convert(wrapped);

    const widget = out.indexOf("cf-turnstile");
    const centered = out.indexOf('<p style="text-align:center">');

    expect(widget).toBeGreaterThan(-1);
    expect(centered).toBeGreaterThan(-1);
    // 段落の**手前**に出す。中に入れると、その段落はブラウザで割れる
    expect(widget).toBeLessThan(centered);
  });

  it("ボタンは元の段落の中に残る（整列を持っているのは段落の側）", () => {
    const out = convert(wrapped);
    const centered = out.slice(out.indexOf('<p style="text-align:center">'));

    expect(centered.slice(0, centered.indexOf("</p>"))).toContain(
      '<button type="submit">送信</button>',
    );
  });

  it("エラー表示の段落も、入れ物の外へ出す", () => {
    const out = convert(wrapped);
    const field = out.indexOf('name="name"');
    const error = out.indexOf("@error('name')");
    const closeP = out.indexOf("</p>", field);

    expect(error).toBeGreaterThan(-1);
    // 入力の入れ物（`<p>`）を閉じた**後**に出る
    expect(error).toBeGreaterThan(closeP);
  });

  it("入れ物が段落でなければ、これまでどおりボタンの直前へ出す", () => {
    const out = convert(`
      <form action="#" method="get">
        <div class="actions"><button type="submit">送信</button></div>
      </form>`);

    const widget = out.indexOf("cf-turnstile");
    const actions = out.indexOf('<div class="actions">');

    // 入れ物は割れないので、外へ出す必要が無い
    expect(widget).toBeGreaterThan(actions);
  });
});
