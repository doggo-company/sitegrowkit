import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import {
  buildImportedSectionComponent,
  extractSectionTemplate,
} from "../src/importer/blade-html.js";

/**
 * 取り込んだセクションを **CMS の component** として書き出す（NEW-2 後半）。
 *
 * **意匠は原本の markup そのもの**で、運用者が触るのは `propsSchema` の項目だけ。
 * 視覚エディタの属性パネルは `propsSchema` を回して入力欄を出すので、
 * **新しい型でもそのまま編集できる**（実測で確認済み）。
 */
const build = (html: string, id = "concept", label = "コンセプト") => {
  const t = extractSectionTemplate(parse(html).querySelector("section"))!;

  return buildImportedSectionComponent(id, label, t);
};

const SIMPLE =
  '<section class="section concept" id="concept"><span>CONCEPT</span><h2>こだわり</h2><p>庭を育てます。</p></section>';

describe("component の定義", () => {
  it("type と view の名前が対応する", () => {
    const c = build(SIMPLE);
    const d = JSON.parse(c.definition) as {
      type: string;
      renderer: { kind: string; view: string };
    };

    expect(c.type).toBe("imported_concept");
    expect(d.type).toBe("imported_concept");
    expect(d.renderer.kind).toBe("blade");
    expect(d.renderer.view).toBe("components.document.imported-concept");
  });

  /** **複数行かどうかは `max` が決める。**`multiline` という鍵は無い（実測） */
  it("本文だけ max を大きくする（1 行/複数行はこれで決まる）", () => {
    const d = JSON.parse(build(SIMPLE).definition) as {
      propsSchema: Record<string, { max: number; label: string }>;
    };

    // 属性パネルは max > 200 を複数行にする
    expect(d.propsSchema.body_1?.max).toBeGreaterThan(200);
    expect(d.propsSchema.heading_1?.max).toBe(200);
    expect(d.propsSchema.text_1?.max).toBe(200);
  });

  it("効かない鍵を送らない", () => {
    expect(build(SIMPLE).definition).not.toContain("multiline");
  });

  it("取り込んだ画像を残しながら公開メディアへ差し替えられる", () => {
    const c = build('<section><img class="hero-photo" src="/imported/photo.jpg" alt="庭"></section>');
    const d = JSON.parse(c.definition) as { propsSchema: Record<string, { type: string }> };

    expect(d.propsSchema.image_1_media?.type).toBe("media");
    expect(c.props.image_1_src).toBe("/imported/photo.jpg");
    expect(c.props.image_1_media).toBeUndefined();
    expect(c.view).toContain('class="hero-photo"');
    expect(c.view).toContain("where('visibility', 'public')");
    expect(c.view).toContain("find($props['image_1_media'] ?? null)?->url()");
    expect(c.view).toContain("?? ($props['image_1_src'] ?? '')");
    expect(c.view).not.toContain("{!!");
  });

  /** **ページ直下にだけ置ける。**container の中へ入れると意匠が崩れる */
  it("置ける場所を絞る", () => {
    const d = JSON.parse(build(SIMPLE).definition) as {
      allowedParents: string[];
      allowedChildren: string[];
    };

    expect(d.allowedParents).toStrictEqual(["page"]);
    // 中身は原本が決めている。ブロックを足させない
    expect(d.allowedChildren).toStrictEqual([]);
  });

  it("id を機械名にする", () => {
    expect(build(SIMPLE, "News List").type).toBe("imported_news_list");
    expect(build(SIMPLE, "").type).toBe("imported_section");
  });
});

describe("component の view", () => {
  /** **外枠は 1 バイトも変えない**（CSS が原本の class に載っている） */
  it("原本の markup がそのまま残る", () => {
    const c = build(SIMPLE);

    expect(c.view).toContain('class="section concept"');
    expect(c.view).toContain('id="concept"');
    expect(c.view).toContain("<h2>");
  });

  it("文字は props から出す", () => {
    const c = build(SIMPLE);

    expect(c.view).toContain("{{ $props['heading_1'] ?? '' }}");
    expect(c.view).toContain("{{ $props['body_1'] ?? '' }}");
    // 原本の文言は view に残らない（document 側の初期値になる）
    expect(c.view).not.toContain("こだわり</h2>");
  });

  /**
   * 🔴 **`{!! !!}` を使わない。**
   *
   * ここに入るのは**運用者が書いた文字**である。HTML として解釈させると、
   * **B-25「CSS や HTML を利用者に意識させない」の逆**を、しかも
   * **書けてしまう口として**開くことになる。
   */
  it("運用者の文字を HTML として解釈しない", () => {
    expect(build(SIMPLE).view).not.toContain("{!!");
  });

  it("生成物であることを頭に書く", () => {
    expect(build(SIMPLE).view).toContain("生成物");
  });

  /** 置き換えの取りこぼしを残さない */
  it("印が 1 つも残らない", () => {
    expect(build(SIMPLE).view).not.toContain("__PROP_");
  });
});

describe("document へ置く初期値", () => {
  it("原本の文言がそのまま入る", () => {
    const c = build(SIMPLE);

    expect(c.props.heading_1).toBe("こだわり");
    expect(c.props.body_1).toBe("庭を育てます。");
  });

  /** **項目と初期値が 1 対 1**（片方だけ在ると、空で描くか、触れない文字が残る） */
  it("項目と初期値の数が合う", () => {
    const c = build(SIMPLE);
    const d = JSON.parse(c.definition) as { propsSchema: Record<string, unknown> };

    expect(Object.keys(c.props).sort()).toStrictEqual(
      Object.keys(d.propsSchema).sort(),
    );
  });
});
