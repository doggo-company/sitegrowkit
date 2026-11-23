import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { extractSectionTemplate } from "../src/importer/blade-html.js";

/**
 * 取り込んだセクションから、**編集できる文字だけ**を項目にする（NEW-2）。
 *
 * ## なぜブロックへ組み直さないか
 *
 * `PageDocument` の既定のブロックは**自前の markup と class** を出す。
 * 取り込んだ意匠は **CSS が原本の class に載っている**ので、組み直した時点で消える
 * （実案件のトップは `<style>` が **58,751 字**）。
 *
 * ▷ オーナー決定は **「デザインは勝手に変えちゃダメ」**。
 * お問い合わせで採った (ア-3b) と同じ形をページにも当てる。
 */
const sec = (html: string) => parse(html).querySelector("section");

describe("編集できる文字を項目にする", () => {
  it("見出しと本文を項目にし、原本には印を残す", () => {
    const t = extractSectionTemplate(
      sec("<section><h2>事業内容</h2><p>庭を作ります。</p></section>"),
    )!;

    expect(t.props.map((p) => p.key)).toStrictEqual(["heading_1", "body_1"]);
    expect(t.unit).toContain("__PROP_heading_1__");
    expect(t.unit).toContain("__PROP_body_1__");
    // 原本の文言は残らない（差し替わっている）
    expect(t.unit).not.toContain("事業内容");
  });

  /** **外枠は 1 バイトも変えない**（class も入れ子もそのまま） */
  it("外枠はそのまま残る", () => {
    const t = extractSectionTemplate(
      sec('<section class="section concept" id="concept"><h2 class="t">見出し</h2></section>'),
    )!;

    expect(t.unit).toContain('class="section concept"');
    expect(t.unit).toContain('id="concept"');
    expect(t.unit).toContain('<h2 class="t">');
  });

  /** **本文だけ複数行**（短い見出しに大きな入力欄を出さない） */
  it("役割で入力欄の種類を分ける", () => {
    const t = extractSectionTemplate(
      sec("<section><h2>見出し</h2><p>本文です。</p></section>"),
    )!;

    expect(t.props.find((p) => p.key === "heading_1")?.multiline).toBe(false);
    expect(t.props.find((p) => p.key === "body_1")?.multiline).toBe(true);
  });

  // ── 触らせないもの ────────────────────────────────

  /**
   * 🔴 **`<a>` の中は触らせない。**
   *
   * 文言を変えても**行き先は変わらない** — 「詳しく見る」を「お問い合わせ」に
   * 変えたら、**押した人が違うところへ飛ぶ**。
   */
  it("文字だけのリンクは、行き先と文言を一緒に項目にする（R03 続き (2)）", () => {
    const t = extractSectionTemplate(
      sec('<section><a href="/works">詳しく見る</a><p>本文です。</p></section>'),
    )!;

    // 行き先と文言が同じ節の項目として並ぶので、「文言を変えたのに行き先が違う」は起きない
    expect(t.props.map((p) => p.key)).toStrictEqual(["body_1", "link_1_href", "link_1_text"]);
    expect(t.props.find((p) => p.key === "link_1_href")).toMatchObject({
      value: "/works",
      kind: "url",
    });
    expect(t.props.find((p) => p.key === "link_1_text")).toMatchObject({ value: "詳しく見る" });
    expect(t.unit).toContain('href="__PROP_link_1_href__"');
    expect(t.unit).toContain(">__PROP_link_1_text__<");
    // class / 構造は 1 バイトも動かない
    expect(t.unit).toContain("<a ");
  });

  /**
   * **入れ子になっていても、リンクの中の要素は触らせない。**
   *
   * ▷ この試験は**変異で生き残ったから足した**。
   * 「祖先を辿らず、直接の親しか見ない」に変えても 13 本とも緑だった —
   * **手持ちの例では、文字の親がそのまま `<a>` だった**からである。
   * 実際の意匠は `<a class="btn"><span>…</span></a>` のように**包む**。
   * 行き先（href）だけは項目になる。
   */
  it("入れ子のリンクの中の文字は項目にしない（行き先だけ）", () => {
    const t = extractSectionTemplate(
      sec('<section><a href="/works"><span class="btn-label">詳しく見る</span></a><p>本文です。</p></section>'),
    )!;

    expect(t.props.map((p) => p.key)).toStrictEqual(["body_1", "link_1_href"]);
    expect(t.unit).toContain("詳しく見る");
  });

  it("Blade の式・相対パスの href は項目にしない（url 型の検証に通らない）", () => {
    const t = extractSectionTemplate(
      sec('<section><a href="{{ route(\'contact.show\') }}">問い合わせ</a><a href="works.html">事例</a><p>本文です。</p></section>'),
    )!;

    expect(t.props.map((p) => p.key)).toStrictEqual(["body_1", "link_1_text", "link_2_text"]);
    expect(t.unit).toContain("{{ route('contact.show') }}");
    expect(t.unit).toContain('href="works.html"');
  });

  it("画像の場所と説明を項目にする（asset() は root 相対の初期値へ）", () => {
    const t = extractSectionTemplate(
      sec(
        '<section><img src="{{ asset(\'imported/images/hero.jpg\') }}" alt="庭の写真" class="hero-img"><img src="https://cdn.example.com/a.png" alt=""><p>本文です。</p></section>',
      ),
    )!;

    expect(t.props.map((p) => p.key)).toStrictEqual([
      "body_1",
      "image_1_src",
      "image_1_alt",
      "image_2_src",
      "image_2_alt",
    ]);
    expect(t.props.find((p) => p.key === "image_1_src")).toMatchObject({
      value: "/imported/images/hero.jpg",
      kind: "url",
    });
    expect(t.props.find((p) => p.key === "image_1_alt")).toMatchObject({ value: "庭の写真" });
    expect(t.props.find((p) => p.key === "image_2_alt")).toMatchObject({ value: "" });
    // class は動かない。src だけが印に
    expect(t.unit).toContain('src="__PROP_image_1_src__" alt="__PROP_image_1_alt__" class="hero-img"');
  });

  it("ボタンとスクリプトの中も項目にしない", () => {
    const t = extractSectionTemplate(
      sec("<section><button>送信する</button><script>var x = 1;</script><p>本文</p></section>"),
    )!;

    expect(t.props).toHaveLength(1);
    expect(t.unit).toContain("送信する");
  });

  /** **Blade の式は原本の文字ではない**（`{{ asset(...) }}` 等を項目にしない） */
  it("Blade の式は項目にしない", () => {
    const t = extractSectionTemplate(
      sec("<section><p>{{ $site['site_name'] }}</p><p>本文です。</p></section>"),
    )!;

    expect(t.props).toHaveLength(1);
    expect(t.unit).toContain("{{ $site['site_name'] }}");
  });

  it("1 文字だけの記号は項目にしない", () => {
    const t = extractSectionTemplate(sec("<section><span>/</span><p>本文</p></section>"))!;

    expect(t.props).toHaveLength(1);
  });

  // ── 名前の付け方 ──────────────────────────────────

  /**
   * **「文字 4」では、どれのことか分からない。**
   *
   * 実測（実案件の concept）で 14 項目あり、`CONCEPT` / `みどり造園` / `こだわり` / `01`
   * が全部「文字 N」になった。**もとの文言で見分けられるようにする。**
   */
  it("名前にもとの文言が入る", () => {
    const t = extractSectionTemplate(
      sec("<section><span>CONCEPT</span><h2>こだわり</h2></section>"),
    )!;

    expect(t.props[0]?.label).toBe("文字（CONCEPT）");
    expect(t.props[1]?.label).toBe("見出し（こだわり）");
  });

  /** **技術語彙を出さない**（B-25 の最上位の原則）。class 名を名前に使わない */
  it("名前に class 名を使わない", () => {
    const t = extractSectionTemplate(
      sec('<section><span class="section-title-accent">こだわり</span></section>'),
    )!;

    expect(t.props[0]?.label).not.toContain("section-title-accent");
  });

  it("長い文言は切り詰める", () => {
    const t = extractSectionTemplate(
      sec("<section><p>とても長い本文がここに入りますがラベルは短くします</p></section>"),
    )!;

    expect(t.props[0]?.label).toContain("…");
    expect(t.props[0]?.label.length).toBeLessThan(24);
    // 値のほうは切り詰めない
    expect(t.props[0]?.value).toContain("ラベルは短くします");
  });

  // ── 触らない ──────────────────────────────────────

  it("渡された DOM を書き換えない", () => {
    const root = parse("<section><h2>見出し</h2></section>");
    const before = root.toString();

    extractSectionTemplate(root.querySelector("section"));

    expect(root.toString()).toBe(before);
  });

  it("セクションが無ければ null", () => {
    expect(extractSectionTemplate(null)).toBeNull();
  });

  // ── 実物 ──────────────────────────────────────────

  /** **作った例だけで確かめない。**kit の同梱見本で当てる */
  it("同梱の見本から取り出せる", () => {
    const html = readFileSync(
      resolve(__dirname, "../../../examples/html-template/index.html"),
      "utf8",
    );
    const first = parse(html).querySelector("section");
    const t = extractSectionTemplate(first);

    expect(t).not.toBeNull();
    expect(t!.props.length).toBeGreaterThan(0);
    for (const p of t!.props) {
      expect(p.label).toMatch(/^(見出し|本文|文字|箇条書き|画像 \d+ の(場所|説明)|リンク \d+ の(行き先|文言))（/);
    }
  });
});
