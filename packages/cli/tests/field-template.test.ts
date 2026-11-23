import { readFileSync } from "node:fs";
import { join } from "node:path";

import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { extractFieldTemplate } from "../src/importer/blade-html.js";

/**
 * 取り込んだフォームから、項目 1 件ぶんの雛形を取り出す（R-12 Stage 2 / D-3）。
 *
 * ## なぜ雛形が要るのか
 *
 * `/contact` を CMS のフォームへ切り替えるとき、項目は**運用者が足したり改名したりする**
 * ので、原本の 3 項目を写すだけでは足りない。**原本の見た目のまま、任意の項目を描ける**
 * 形にする必要がある。オーナー決定は **(ア-3b)＝意匠を完全に残す**である。
 *
 * ## kit の入力欄の部品を流用できない
 *
 * `forms/partials/field.blade.php` は 12 種すべてを描き分けるが、出すのは
 * `<div class="form-field">` である。**実案件の外枠は `<p>`** なので、
 * 中へ入れるとブラウザが `<p>` を先に閉じる（`contact-form-wrapper.test.ts` の罠）。
 * だから**外枠は原本から取り、中の制御要素は自前で描く**。
 */
const parseForm = (html: string) => parse(html).querySelector("form");

describe("項目 1 件ぶんの雛形を取り出す", () => {
  // 実案件の形（`<label>` が包む・inline style を持つ）
  const wrapping = `
    <form>
      <p><label>お名前<br><input type="text" name="name" style="width:100%"></label></p>
      <p><label>メール<br><input type="email" name="email" style="width:100%"></label></p>
      <p><label>ご相談<br><textarea name="message" style="width:100%"></textarea></label></p>
      <p><button type="submit">送信</button></p>
    </form>`;

  // Green Leaf の形（`<label for>` が兄弟・見た目は CSS 側）
  const sibling = `
    <form class="contact-form">
      <div class="field"><label for="a">お名前</label><input type="text" id="a" name="name"></div>
      <div class="field"><label for="b">メール</label><input type="email" id="b" name="email"></div>
      <div class="field"><label for="c">ご相談</label><textarea id="c" name="message"></textarea></div>
      <div class="field field-checkbox"><label for="d"><input type="checkbox" id="d" name="agree">同意する</label></div>
      <button type="submit">送信</button>
    </form>`;

  it("包む形では、外枠と制御要素の位置とラベルの位置が取れる", () => {
    const t = extractFieldTemplate(parseForm(wrapping))!;

    expect(t.unit).toBe("<p><label>__LABEL__<br>__CONTROL__</label></p>");
    expect(t.labelStyle).toBe("wrapping");
  });

  it("兄弟の形でも取れる", () => {
    const t = extractFieldTemplate(parseForm(sibling))!;

    expect(t.unit).toContain("__LABEL__");
    expect(t.unit).toContain("__CONTROL__");
    expect(t.unit).toContain('class="field"');
    expect(t.labelStyle).toBe("sibling");
  });

  /** **型が違っても外枠は 1 枚**（1 の実測。ここが崩れると型ごとに N 枚要る） */
  it("textarea しか無くても同じ外枠になる", () => {
    const only = `<form><p><label>ご相談<br><textarea name="message" style="width:100%"></textarea></label></p></form>`;

    expect(extractFieldTemplate(parseForm(only))!.unit).toBe(
      "<p><label>__LABEL__<br>__CONTROL__</label></p>",
    );
  });

  // ── 制御要素の見た目 ────────────────────────────────

  it("見た目の属性だけ運ぶ（name / type / id は運ばない）", () => {
    const t = extractFieldTemplate(parseForm(wrapping))!;

    expect(t.controlAttrs).toStrictEqual({ style: "width:100%" });
  });

  it("原本が見た目を持たないなら、運ぶものは無い", () => {
    expect(extractFieldTemplate(parseForm(sibling))!.controlAttrs).toStrictEqual({});
  });

  // ── 多数派と外れ ────────────────────────────────────

  /** **黙って多数派へ寄せない。**外れたものは名指しで返す */
  it("外枠の形が違う項目は outliers で名指しする", () => {
    const t = extractFieldTemplate(parseForm(sibling))!;

    expect(t.outliers).toStrictEqual(["agree"]);
    expect(t.unit).not.toContain("field-checkbox");
  });

  /**
   * **多数派を採る。先頭を採るのではない。**
   *
   * ▷ この試験は**変異で生き残ったから足した**。
   * 「多数派の判定を外して先頭を採る」に変えても、それまでの 12 本は全部緑のままだった —
   * 手持ちの例では**たまたま先頭が多数派**だったからである。
   * **1 つ目だけ形が違う**例を置いて、初めて判定が効いていることが見える。
   */
  it("先頭が少数派でも、多数派の外枠を採る", () => {
    const oddFirst = `
      <form>
        <div class="field field-wide"><label for="a">お名前</label><input type="text" id="a" name="name"></div>
        <div class="field"><label for="b">メール</label><input type="email" id="b" name="email"></div>
        <div class="field"><label for="c">ご相談</label><textarea id="c" name="message"></textarea></div>
      </form>`;

    const t = extractFieldTemplate(parseForm(oddFirst))!;

    expect(t.unit).toContain('class="field"');
    expect(t.unit).not.toContain("field-wide");
    expect(t.outliers).toStrictEqual(["name"]);
  });

  it("全部同じ形なら外れは無い", () => {
    expect(extractFieldTemplate(parseForm(wrapping))!.outliers).toStrictEqual([]);
  });

  // ── 触らない・落とさない ────────────────────────────

  /** **原本の DOM を 1 バイトも変えない**（途中で失敗しても入力は元のまま） */
  it("渡された DOM を書き換えない", () => {
    const root = parse(wrapping);
    const before = root.toString();

    extractFieldTemplate(root.querySelector("form"));

    expect(root.toString()).toBe(before);
  });

  it("送信ボタンは項目として数えない", () => {
    // ボタンしか無いフォームは「項目が無い」
    expect(extractFieldTemplate(parseForm("<form><button type=\"submit\">送信</button></form>"))).toBeNull();
  });

  it("hidden も項目として数えない", () => {
    const withHidden = `<form><input type="hidden" name="t" value="1"><p><label>お名前<br><input type="text" name="name"></label></p></form>`;
    const t = extractFieldTemplate(parseForm(withHidden))!;

    expect(t.outliers).toStrictEqual([]);
    expect(t.unit).toBe("<p><label>__LABEL__<br>__CONTROL__</label></p>");
  });

  it("フォームが無ければ null", () => {
    expect(extractFieldTemplate(null)).toBeNull();
  });

  // ── 実物 ────────────────────────────────────────────

  /** **kit の見本そのもの**で当てる（作った例だけで確かめない） */
  it("同梱の見本（Green Leaf）から取り出せる", () => {
    const html = readFileSync(
      join(__dirname, "..", "..", "..", "examples", "html-template", "contact.html"),
      "utf8",
    );
    const t = extractFieldTemplate(parse(html).querySelector("form"))!;

    expect(t).not.toBeNull();
    expect(t.unit).toContain("__CONTROL__");
    expect(t.unit).toContain("__LABEL__");
    // 見本は checkbox だけ外枠が違う（実測）
    expect(t.outliers.length).toBeGreaterThan(0);
  });
});
