import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import {
  buildControlPartial,
  extractFieldTemplate,
  substituteCmsForm,
} from "../src/importer/blade-html.js";

/**
 * 取り込んだフォームを **CMS のフォーム**へ繋ぎ替える（R-12 Stage 2 / D-3 後半）。
 *
 * `substituteContactForm()`（旧 Contact パイプラインへ繋ぐ）の兄弟。
 * こちらは **CMS の項目を `@foreach` で描く**ので、運用者が項目を足しても
 * **原本の見た目のまま**出る。オーナー決定 = **(ア-3b) 意匠を完全に残す**。
 */
const WRAPPING = `
  <form>
    <p><label>お名前<br><input type="text" name="name" style="width:100%"></label></p>
    <p><label>メール<br><input type="email" name="email" style="width:100%"></label></p>
    <p style="text-align:center"><button type="submit">送信する</button></p>
  </form>`;

const SIBLING = `
  <form class="contact-form">
    <div class="field"><label for="a">お名前</label><input type="text" id="a" name="name"></div>
    <div class="field"><label for="b">メール</label><input type="email" id="b" name="email"></div>
    <button type="submit">送信</button>
  </form>`;

function convert(html: string): string {
  const main = parse(`<main>${html}</main>`);
  const form = main.querySelector("form")!;
  const template = extractFieldTemplate(form)!;

  substituteCmsForm(main, "form", template, "forms.partials.imported-control");

  return main.toString();
}

describe("CMS のフォームへ繋ぎ替える", () => {
  it("送信制限の理由を入力欄と一緒に表示できる", () => {
    const out = convert(SIBLING);
    expect(out).toContain('@if (isset($rateLimitMessage))');
    expect(out).toContain('<p class="form-error" role="alert">{{ $rateLimitMessage }}</p>');
    expect(out.indexOf('$rateLimitMessage')).toBeLessThan(out.indexOf('<form'));
  });

  it("モックの押せない送信ボタンを、押せるようにする", () => {
    // 🔴 残すと**フォームは動いているのに押せない**。裏側（確認 → 送信 → 保存 →
    //    通知）は全部通るのに、利用者はボタンを押せずに帰る。エラーは出ない
    //    （2026-08-28 に実案件で実測。ここだけ外し忘れていた）
    const out = convert(`
      <form>
        <p><label>お名前<br><input type="text" name="name"></label></p>
        <p><button type="submit" disabled style="opacity:0.6">送信（準備中）</button></p>
      </form>`);

    expect(out).not.toContain("disabled");
    expect(out).not.toContain("opacity:0.6");
  });

  it("文言は書き換えない（Preserve First）", () => {
    // 外すのは「押せない」という**振る舞い**だけ。
    // 「準備中」と書いてあることは `plan` が note で人に伝える役
    const out = convert(`
      <form>
        <p><label>お名前<br><input type="text" name="name"></label></p>
        <p><button type="submit" disabled>送信（準備中）</button></p>
      </form>`);

    expect(out).toContain("送信（準備中）");
  });

  it("項目は @foreach で描く（原本の 2 項目に固定されない）", () => {
    const out = convert(WRAPPING);

    expect(out).toContain("@foreach ($fields as $field)");
    expect(out).toContain("@endforeach");
    // 原本の項目名は 1 つも残らない（CMS が決めるため）
    expect(out).not.toContain('name="name"');
    expect(out).not.toContain('name="email"');
  });

  it("原本の外枠はそのまま残る", () => {
    const out = convert(WRAPPING);

    expect(out).toContain("<p><label>{{ $field->label }}<br>");
    expect(out).toContain("@include('forms.partials.imported-control'");
  });

  /** **送信ボタンの入れ物と整列を壊さない**（`contact-form-wrapper.test.ts` と同じ罠） */
  it("人に見えない欄と Turnstile は段落の外へ出す", () => {
    const out = convert(WRAPPING);

    const honeypot = out.indexOf("$honeypot");
    const turnstile = out.indexOf("cf-turnstile");
    const centered = out.indexOf('<p style="text-align:center">');

    expect(honeypot).toBeGreaterThan(-1);
    expect(turnstile).toBeGreaterThan(-1);
    expect(honeypot).toBeLessThan(centered);
    expect(turnstile).toBeLessThan(centered);
  });

  it("送信ボタンは元の段落の中に残る", () => {
    const out = convert(WRAPPING);
    const centered = out.slice(out.indexOf('<p style="text-align:center">'));

    expect(centered.slice(0, centered.indexOf("</p>"))).toContain("送信する");
  });

  /** **送信先はここで決めない。**確認画面を挟むかはフォームの設定である */
  it("action はサーバーが渡すものを使う", () => {
    const out = convert(WRAPPING);

    expect(out).toContain('action="{{ $action }}"');
    expect(out).toContain('method="post"');
    expect(out).toContain("@csrf");
  });

  /** 兄弟の形は、原本の id が 1 つ目のものなので**張り直さないと全部同じ id になる** */
  it("for と id は項目ごとに張り直す", () => {
    const out = convert(SIBLING);

    expect(out).toContain('for="{{ $fieldId }}"');
    expect(out).not.toContain('for="a"');
    expect(out).toContain("$fieldId = 'f-'.$field->key;");
  });
});

describe("制御要素の部品", () => {
  const partialFor = (html: string): string =>
    buildControlPartial(extractFieldTemplate(parse(html).querySelector("form"))!);

  /** **12 種すべて**を描き分ける（原本に無い型でも描けないと意匠が崩れる） */
  it("公開フォームの型を全部描き分ける", () => {
    const p = partialFor(WRAPPING);

    /*
     * **markup の断片ではなく、分岐そのものを見る。**
     * `toContain("textarea")` は `</textarea>` にも当たるので、
     * **分岐を潰しても緑のまま**だった（変異 M7 ですり抜けた）。
     */
    for (const type of ["radio", "checkbox", "boolean", "textarea", "select"]) {
      expect(p).toContain(`'${type}'`);
    }
    expect(p).toContain("$field->type === 'textarea'");
    expect(p).toContain("$field->type === 'select'");
    expect(p).toContain("$field->type === 'boolean'");
    expect(p).toContain("['radio', 'checkbox']");
    // text 系は $textType で 6 種を吸収する
    expect(p).toContain("$textType");
    expect(p).toContain("'datetime' => 'datetime-local'");
  });

  /** **原本の見た目を、型を越えて載せる**（原本に無い型でも浮かない） */
  it("原本の見た目を全部の型へ載せる", () => {
    const p = partialFor(WRAPPING);
    const withStyle = p.split("\n").filter((l) => l.includes('style="width:100%"'));

    // input / textarea / select の 3 つに載る
    expect(withStyle.length).toBeGreaterThanOrEqual(3);
  });

  it("原本が見た目を持たなければ、何も足さない", () => {
    expect(partialFor(SIBLING)).not.toContain('style="');
  });

  /** **外枠を出さない**（外枠は原本のもの。ここで出すと二重になり、`<p>` なら割れる） */
  it("外枠は出さない", () => {
    // 冒頭の説明（`{{-- … --}}`）には `<div class="form-field">` の話が出てくるので、
    // **描くところだけ**を見る。説明の文言で緑赤が変わるのは番人として弱い
    const p = partialFor(WRAPPING);
    const code = p.slice(p.indexOf("--}}") + 4);

    expect(code).not.toContain('class="form-field"');
    expect(code).not.toContain("<div");
    expect(code).toContain("<input");
  });

  it("生成物であることを頭に書く", () => {
    expect(partialFor(WRAPPING)).toContain("生成物");
  });
});
