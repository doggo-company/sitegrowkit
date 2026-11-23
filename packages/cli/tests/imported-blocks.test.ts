import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import {
  buildImportedPageBlocks,
  freeBladeVariables,
} from "../src/importer/imported-blocks.js";

/**
 * 取り込んだ 1 枚を、編集できるブロックの並びに割る（NEW-2 / B-54）。
 *
 * ここで固定するのは 4 つ:
 *   1. **`<section>` でない意匠も落とさない**（帯・流れる文字が消えない）
 *   2. **枠と中身を取り違えない**（nav・footer・script がページの中に入らない）
 *   3. **並び順が原本のまま**
 *   4. **境界が決められないなら割らない**
 */
const page = (body: string): ReturnType<typeof parse> =>
  parse(`<html><body>${body}</body></html>`, {
    blockTextElements: { script: true, style: true },
  });

const REAL = `
  <div class="bg-pattern"></div>
  <nav id="navbar"><a href="/">みどり造園</a></nav>
  <section id="home"><h1>庭をつくる</h1></section>
  <div class="character-strip"><img src="a.png" alt=""></div>
  <section id="concept"><h2>CONCEPT</h2><p>植物が好き。</p></section>
  <div class="fullbleed-band"></div>
  <section id="contact"><h2>CONTACT</h2></section>
  <footer class="footer"><small>&copy; みどり造園</small></footer>
  <script src="a.js"></script>
`;

describe("buildImportedPageBlocks", () => {
  // ── 1. section でない意匠も落とさない ────────────────

  it("section でない意匠もブロックとして残す", () => {
    const r = buildImportedPageBlocks(page(REAL));

    expect(r).not.toBeNull();
    expect(r!.blocks.map((b) => b.key)).toEqual([
      "home",
      "character_strip",
      "concept",
      "fullbleed_band",
      "contact",
    ]);
  });

  it("文字が 1 つも無いブロックも置く", () => {
    const r = buildImportedPageBlocks(page(REAL));
    const band = r!.blocks.find((b) => b.key === "fullbleed_band");

    // **落とすと意匠が変わる。**編集する項目が無いだけで、出す必要はある
    expect(band).toBeDefined();
    expect(Object.keys(band!.block.props)).toHaveLength(0);
    expect(band!.files.map((f) => f.target)).toEqual([
      "content/components/imported_fullbleed_band.json",
      "resources/views/components/document/imported-fullbleed-band.blade.php",
    ]);
  });

  it("同じ綴りが 2 つあれば番号を足す", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <div class="band"></div><div class="band"></div>`),
    );

    // 後ろのものが前のものを潰すと、意匠が 1 つ消える
    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "band", "band_2"]);
  });

  // ── 2. 枠と中身を取り違えない ────────────────────────

  it("最初の section より前は枠に入る", () => {
    const r = buildImportedPageBlocks(page(REAL));

    expect(r!.frame.before.map((e) => e.rawTagName)).toEqual(["div", "nav"]);
    expect(r!.blocks.map((b) => b.key)).not.toContain("navbar");
  });

  it("footer から後は枠に入る", () => {
    const r = buildImportedPageBlocks(page(REAL));

    expect(r!.frame.after.map((e) => e.rawTagName)).toEqual([
      "footer",
      "script",
    ]);
    expect(r!.blocks.map((b) => b.key)).not.toContain("footer");
  });

  it("中身の途中の script はブロックにしない", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><script>1</script><section id="b"></section>`),
    );

    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "b"]);
  });

  // ── 3. 並び順 ────────────────────────────────────────

  it("並び順は原本のまま", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="one"></section><div class="mid"></div><section id="two"></section>`),
    );

    expect(r!.blocks.map((b) => b.key)).toEqual(["one", "mid", "two"]);
  });

  // ── 4. 境界が決められないなら割らない ────────────────

  it("body 直下に section が無くても、main が 1 つ在ればその中身を割る（header / footer は枠）", () => {
    // 一般的な body > header + main + footer の原本（R03 続き (3)）
    const r = buildImportedPageBlocks(
      page(`<header id="top"><nav>menu</nav></header>
            <main class="site-main" id="content">
              <section id="about"><h1>会社</h1></section>
              <div class="band"></div>
              <article class="news"><h2>お知らせ</h2></article>
            </main>
            <footer class="footer">&copy;</footer>`),
    );

    expect(r).not.toBeNull();
    expect(r!.blocks.map((b) => b.key)).toEqual(["about", "band", "news"]);
    expect(r!.frame.before.map((e) => e.rawTagName)).toEqual(["header"]);
    expect(r!.frame.after.map((e) => e.rawTagName)).toEqual(["footer"]);
    // main 自身は枠が包み直す（main に当たる CSS を落とさない）
    expect(r!.frame.wrap).toEqual({ open: '<main class="site-main" id="content">', close: "</main>" });
  });

  it("main が 2 つ在るなら境界を決めない", () => {
    expect(
      buildImportedPageBlocks(page(`<main><p>a</p></main><main><p>b</p></main>`)),
    ).toBeNull();
  });

  it("section も main も無ければ null", () => {
    expect(buildImportedPageBlocks(page(`<header>a</header><div><p>b</p></div>`))).toBeNull();
  });

  it("section が 1 つも無ければ null", () => {
    // **「たぶんここから」で切らない。**切るとヘッダーがページの中に入る
    expect(buildImportedPageBlocks(page(`<nav></nav><div></div>`))).toBeNull();
  });

  it("渡されたものが無ければ null", () => {
    expect(buildImportedPageBlocks(null)).toBeNull();
  });

  it("footer が無ければ最後まで中身", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><section id="b"></section>`),
    );

    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "b"]);
    expect(r!.frame.after).toEqual([]);
  });

  // ── Blade の入ったブロックは部品にしない ──────────────

  it("中で意味が閉じている Blade は、原本のまま素通しの部品にする（落とさない）", () => {
    // 🔴 外すと、`/` をページに切り替えた瞬間に**その領域が公開面から消える**（Codex 指摘 ①）。
    //    `@php($x = 1)` は中で束ねられているので、部品の view として描いても 500 にならない
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section id="gallery">@php($x = 1)<p>{{ $x }}</p></section>
            <section id="b"></section>`),
    );

    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "gallery", "b"]);
    expect(r!.skipped).toEqual([]);

    const gallery = r!.blocks[1]!;
    expect(gallery.passthrough).toBe(true);
    // 編集できないことを名前で明示する（編集欄が無いだけでは、壊れていると思われる）
    expect(gallery.label).toContain("画面からは編集できません");
    expect(Object.keys(gallery.block.props)).toHaveLength(0);
    // 原本の Blade がそのまま view に入る
    expect(String(gallery.files[1]!.content)).toContain("@php($x = 1)<p>{{ $x }}</p>");
  });

  it("@if (true) だけの節は、本文を保ったまま素通しにする", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section class="conditional">@if (true)<p>重要な本文</p>@endif</section>`),
    );

    expect(r!.skipped).toEqual([]);
    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "conditional"]);
    expect(String(r!.blocks[1]!.files[1]!.content)).toContain("重要な本文");
  });

  it("外の変数を参照する Blade は部品にできない — 名前と変数を返す", () => {
    // `$n` は静的な Blade では controller が渡していたもの。部品の view には来ないので 500 になる。
    // **黙って落とさず、何を参照しているかまで言う**（呼び出し側はページごと切替を止める）
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section id="x">@if ($n) あ @endif</section>`),
    );

    expect(r!.blocks.map((b) => b.key)).toEqual(["a"]);
    expect(r!.skipped).toEqual([
      { name: "section#x", directive: "@if（外の変数 $n を参照）" },
    ]);
  });

  it("@include など、外に何かを要求する指令は素通しにもしない", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section id="p">@include('partials.x')</section>`),
    );

    expect(r!.skipped).toEqual([{ name: "section#p", directive: "@include" }]);
  });

  it("@foreach が在るなら一覧の部品として組み直す", () => {
    // **落とさない。**中身が CMS から来るというだけで、意匠は残せる
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section id="news">@foreach ($news as $item)<p>{{ $item->title }}</p>@endforeach</section>`),
    );

    expect(r!.skipped).toEqual([]);
    expect(r!.blocks.map((b) => b.key)).toEqual(["a", "news"]);
    expect(r!.blocks[1]!.block.binding).toEqual({
      source: "collection",
      type: "news",
      sort: [{ field: "published_at", direction: "desc" }],
    });
  });

  it("@foreach の外の $loop は外の変数（部品にしない）", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section>
            <section id="g"><span>{{ sprintf('%02d', $loop->iteration) }}</span></section>`),
    );

    expect(r!.skipped.map((x) => x.directive)).toEqual([
      "$loop（外の変数 $loop を参照）",
    ]);
  });

  it("原本にありうる {{ }} だけでは外さない", () => {
    // 出すだけなら 500 にはならない。**壊すのは指令と変数の方**
    const r = buildImportedPageBlocks(
      page(`<section id="a"><span>{{ name }}</span></section>`),
    );

    expect(r!.skipped).toEqual([]);
    expect(r!.blocks).toHaveLength(1);
  });

  // ── 原本の文字をそのまま運ぶ ──────────────────────────

  it("実体参照は解いた文字として持つ", () => {
    // 🔴 綴りのまま持つと Blade の {{ }} が & を再び逃がし、
    //    **画面に &amp;nbsp; と出る**（実案件の流れる文字で実測）
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p>富山県高岡市&nbsp;&nbsp;高岡</p></section>`),
    );
    const value = Object.values(r!.blocks[0]!.block.props)[0]!;

    expect(value).not.toContain("&nbsp;");
    expect(value).toContain("\u00a0");
  });

  it("印の外側に、原本の空白が残る", () => {
    // 原本ごと置き換えると前後の空白まで消える。
    // 実案件では 5 ブロックで実際に落ちていた（1 文字ずつなので合計値では見えない）
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p> こんにちは </p></section>`),
    );
    const view = String(r!.blocks[0]!.files[1]!.content);

    expect(view).toContain("<p> {{");
    expect(view).toContain("}} </p>");
  });

  it("前後の &nbsp; は残す（意匠として置かれた隙間）", () => {
    // 🔴 String.trim() は U+00A0 も落とす。落とすと詰まって見える
    //    （実案件の流れる文字で、前後 3 つずつ消えていた）
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p>&nbsp;&nbsp; 富山県高岡市 &nbsp;&nbsp;</p></section>`),
    );
    const value = Object.values(r!.blocks[0]!.block.props)[0]!;

    expect(value.startsWith("\u00a0\u00a0")).toBe(true);
    expect(value.endsWith("\u00a0\u00a0")).toBe(true);
  });

  it("項目の名前の頭にも見えない文字を置かない", () => {
    // ブロックの名前だけでなく、**インスペクタに並ぶ項目の名前**も同じ
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p>&nbsp;&nbsp;富山県高岡市</p></section>`),
    );
    const definition = JSON.parse(String(r!.blocks[0]!.files[0]!.content)) as {
      propsSchema: Record<string, { label: string }>;
    };

    expect(Object.values(definition.propsSchema)[0]!.label).toContain(
      "（富山県高岡市",
    );
  });

  it("名前の頭には見えない文字を置かない", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p>&nbsp;&nbsp;富山県高岡市</p></section>`),
    );

    expect(r!.blocks[0]!.label).toContain("（富山県高岡市");
  });

  it("項目の値そのものは前後を詰める", () => {
    // 名前に使うので、値の側は詰まっているのが正しい
    const r = buildImportedPageBlocks(
      page(`<section id="a"><p>   こんにちは   </p></section>`),
    );

    expect(Object.values(r!.blocks[0]!.block.props)[0]).toBe("こんにちは");
  });

  // ── 名前 ──────────────────────────────────────────────

  it("名前に原本の文言を添える", () => {
    const r = buildImportedPageBlocks(page(REAL));
    const concept = r!.blocks.find((b) => b.key === "concept");

    // class 名だけでは、編集する人にどのブロックか分からない（B-25）
    expect(concept!.label).toBe("concept（CONCEPT）");
  });

  it("機械名にするのは 1 か所（大文字も記号も同じ答えになる）", () => {
    /*
     * 🔴 **同じ綴りを 3 か所が別々に組んでいた**（D-4）。答えが違っていて、
     * `GALLERY` は片方で `gallery`、片方で `GALLERY` になっていた。
     * **定義が指す先と実ファイルの置き場所がずれると、
     * 一覧には並ぶのに描けない。**
     */
    /*
     * ▷ **記号が続く例で試す。**`GALLERY-Top` のような素直な入力だと
     *   2 つの実装が**同じ答えを出す**ので、食い違いを拾えない
     *   （最初それで変異がすり抜けた）。
     */
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><section id="GALLERY--Top"></section>`),
    );
    const block = r!.blocks[1]!;
    const definition = JSON.parse(String(block.files[0]!.content)) as {
      type: string;
      renderer: { view: string };
    };

    // **鍵が既に機械名の形**になっている（`componentType` を通した答え）
    expect(block.key).toBe("gallery__top");
    expect(definition.type).toBe(`imported_${block.key}`);

    // 定義が指す先と、実ファイルの置き場所が揃っている
    expect(block.files[1]!.target).toBe(
      `resources/views/${definition.renderer.view.replaceAll(".", "/")}.blade.php`,
    );
  });

  it("id が無ければ class の先頭を使う", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><div class="band wide"></div>`),
    );

    expect(r!.blocks[1]!.key).toBe("band");
  });

  it("id も class も無ければ位置で名づける", () => {
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><div></div>`),
    );

    expect(r!.blocks[1]!.key).toBe("block_2");
  });

  // ── 中身 ──────────────────────────────────────────────

  it("原本の文言が初期値に入る", () => {
    const r = buildImportedPageBlocks(page(REAL));
    const concept = r!.blocks.find((b) => b.key === "concept");

    expect(Object.values(concept!.block.props)).toContain("植物が好き。");
  });

  it("原本の文言は、必ずその項目の上限に収まる", () => {
    // 🔴 取り込みが作ったページを、取り込みを動かした本人が保存できない
    //    状態にしない。245 字の流れる文字が上限 200 で弾かれた（実案件）
    const long = "🌿 庭づくり相談受付中 ✂️ お見積り無料 ".repeat(12);
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><div class="marquee"><span>${long}</span></div>`),
    );
    const marquee = r!.blocks.find((b) => b.key === "marquee")!;
    const definition = JSON.parse(String(marquee.files[0]!.content)) as {
      propsSchema: Record<string, { max: number }>;
    };

    expect(long.length).toBeGreaterThan(200);

    for (const [key, value] of Object.entries(marquee.block.props)) {
      expect(value.length).toBeLessThanOrEqual(definition.propsSchema[key]!.max);
    }
  });

  it("上限は原本ぴったりにしない（1 字足したら保存できない、を作らない）", () => {
    const long = "あ".repeat(245);
    const r = buildImportedPageBlocks(
      page(`<section id="a"></section><div class="m"><span>${long}</span></div>`),
    );
    const definition = JSON.parse(String(r!.blocks[1]!.files[0]!.content)) as {
      propsSchema: Record<string, { max: number }>;
    };

    expect(Object.values(definition.propsSchema)[0]!.max).toBe(300);
  });

  it("view の置き場所は定義が指す先と揃う", () => {
    const r = buildImportedPageBlocks(page(REAL));
    const home = r!.blocks.find((b) => b.key === "home")!;
    const definition = JSON.parse(String(home.files[0]!.content)) as {
      renderer: { view: string };
    };

    // 綴りを導く規則が 2 か所にあると、片方だけ直した日にずれる
    expect(home.files[1]!.target).toBe(
      `resources/views/${definition.renderer.view.replaceAll(".", "/")}.blade.php`,
    );
  });
});

/**
 * 変数の束ね判定は**保守的**に — 分からないものは「外の変数」へ倒す。
 */
describe("freeBladeVariables", () => {
  it("@foreach の as で束ねた変数と $loop は外の変数ではない", () => {
    expect(
      freeBladeVariables(
        "@foreach ($items as $k => $item)<p>{{ $loop->iteration }} {{ $item['t'] }} {{ $k }}</p>@endforeach",
      ),
    ).toEqual(["items"]);
  });

  it("代入された変数と @error の $message、共有される $errors は外の変数ではない", () => {
    expect(
      freeBladeVariables(
        "@php($x = 1) {{ $x }} @error('a'){{ $message }}@enderror @if($errors->any())a@endif",
      ),
    ).toEqual([]);
  });

  it("@foreach が無い $loop・@error が無い $message は外の変数", () => {
    expect(freeBladeVariables("{{ $loop->index }} {{ $message }}")).toEqual([
      "loop",
      "message",
    ]);
  });

  it("== は代入ではない", () => {
    expect(freeBladeVariables("@if ($mode == 'a')x@endif")).toEqual(["mode"]);
  });

  it("呼び出し側が渡す変数（props）は束ねられているとみなせる", () => {
    expect(freeBladeVariables("{{ $props['heading_1'] ?? '' }}", ["props"])).toEqual([]);
  });
});
