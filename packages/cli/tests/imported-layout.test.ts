import { parse } from "node-html-parser";
import { describe, expect, it } from "vitest";

import { buildImportedPageBlocks } from "../src/importer/imported-blocks.js";
import {
  buildImportedLayout,
  splitDocumentShell,
} from "../src/importer/imported-layout.js";

/**
 * 取り込んだ意匠を公開 layout として起こす（B-38 / B-55）。
 *
 * ここで固定するのは 3 つ:
 *   1. **枠が原本のまま残る**（head の style・nav・footer・script）
 *   2. **中身の場所が空く**（`@yield('content')`）
 *   3. **枠が読み取れないなら組まない**
 */
const SOURCE = `<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>株式会社 みどり造園</title>
  <style>.hero{color:#0f4c46}</style>
  <script src="{{ asset('imported/js/a.js') }}"></script>
</head>
<body class="home">
  <div class="bg-pattern"></div>
  <nav id="navbar"><a href="/">みどり造園</a></nav>
  <section id="home"><h1>庭をつくる</h1></section>
  <footer class="footer"><small>&copy;</small></footer>
  <script src="{{ asset('imported/js/b.js') }}"></script>
</body>
</html>`;

const layoutOf = (source = SOURCE): string => {
  const doc = parse(source, { blockTextElements: { script: true, style: true } });
  const shell = splitDocumentShell(doc)!;
  const frame = buildImportedPageBlocks(doc)!.frame;

  return buildImportedLayout(
    shell.head,
    frame,
    shell.htmlAttrs,
    shell.bodyAttrs,
    "home",
  ).content;
};

describe("buildImportedLayout", () => {
  // ── 1. 枠が原本のまま残る ────────────────────────────

  it("head の style をそのまま持つ", () => {
    // 🔴 これが落ちると、実案件では 58,751 字が消える
    expect(layoutOf()).toContain(".hero{color:#0f4c46}");
  });

  it("asset() の書き換えを壊さない", () => {
    // 原本には asset() が無い。**生成後の Blade から起こすのが正しい**
    const out = layoutOf();

    expect(out).toContain("{{ asset('imported/js/a.js') }}");
    expect(out).toContain("{{ asset('imported/js/b.js') }}");
  });

  it("nav と footer と script を枠として持つ", () => {
    const out = layoutOf();

    expect(out).toContain(`<nav id="navbar">`);
    expect(out).toContain(`<footer class="footer">`);
    expect(out).toContain(`<div class="bg-pattern">`);
  });

  it("html と body の属性を落とさない", () => {
    const out = layoutOf();

    expect(out).toContain(`<html lang="ja">`);
    expect(out).toContain(`<body class="home">`);
  });

  // ── 2. 中身の場所が空く ──────────────────────────────

  it("中身の場所が空いている", () => {
    expect(layoutOf()).toContain("@yield('content')");
  });

  it("中身のブロックは layout に残さない", () => {
    // 残すと、document が描いたものと二重に出る
    expect(layoutOf()).not.toContain(`<section id="home">`);
  });

  it("枠の中で、上と下の順序が入れ替わらない", () => {
    const out = layoutOf();

    expect(out.indexOf("navbar")).toBeLessThan(out.indexOf("@yield('content')"));
    expect(out.indexOf("@yield('content')")).toBeLessThan(out.indexOf("footer"));
  });

  it("main の中身を割った原本では、@yield を main で包み直す", () => {
    const doc = parse(
      `<html><head><title>x</title></head><body><header>h</header><main class="wrap"><section id="a">a</section></main><footer>f</footer></body></html>`,
    );
    const shell = splitDocumentShell(doc)!;
    const out = buildImportedLayout(
      shell.head,
      buildImportedPageBlocks(doc)!.frame,
      shell.htmlAttrs,
      shell.bodyAttrs,
      "about",
    ).content;

    expect(out).toContain('<main class="wrap">\n@yield(\'content\')\n</main>');
    expect(out).toContain("<header>h</header>");
    expect(out).toContain("<footer>f</footer>");
    expect(out).not.toContain('<section id="a">');
  });

  it("@stack('head') を置く（ページ側が足せるように）", () => {
    expect(layoutOf()).toContain("@stack('head')");
  });

  // ── 検索と共有に必要なもの ───────────────────────────

  it("🔴 原本に無くても、説明と OGP を足す", () => {
    /*
     * 🔴 **原本の head を 1 バイトも変えない方針にしたので、
     * kit が足していたものが全部落ちていた**（2026-08-28・実案件で実測。
     * 公開 8 ページすべてで `description` も `og:` も `canonical` も 0 件）。
     *
     * **効くのは検索と SNS 共有。**トップを X や LINE に貼っても
     * **タイトルしか出ない** — 説明も画像も出ない。
     * **「公開したのに誰にも届かない」の直接の原因になる。**
     *
     * ▷ **意匠は変わらない。**head は見えない。
     */
    const out = layoutOf();

    expect(out).toContain('name="description"');
    expect(out).toContain('rel="canonical"');
    expect(out).toContain('property="og:title"');
    expect(out).toContain('property="og:description"');
    expect(out).toContain('property="og:url"');
    expect(out).toContain('property="og:site_name"');
  });

  it("🔴 `$site` を直に参照しない（渡ってこないページがある）", () => {
    /*
     * `pages/company` は `$site` を受け取らないので、直に書くと
     * **足した瞬間に 500 になった**（2026-08-28 実測）。
     *
     * **足したものが原因でページを落とさない。**出せないなら空で出す。
     */
    const out = layoutOf();

    expect(out).not.toMatch(/\$site\[/);
    expect(out).toContain("$site ?? []");
  });

  it("🔴 共有画像（og:image）を足す", () => {
    /*
     * 🔴 **画像が無い OGP は、貼っても「文字だけの帯」になる。**
     * X / LINE / Facebook はどれも画像の有無で見え方が変わり、
     * **無い方は流れて消える。**「公開したのに誰にも届かない」の直接の原因。
     *
     * ▷ 画像は**案件が持っているもの**を使う。kit は場所だけ用意する。
     */
    const out = layoutOf();

    expect(out).toContain('property="og:image"');
    expect(out).toContain('name="twitter:card"');
  });

  it("🔴 画像が無いときは og:image を出さない", () => {
    /*
     * **空の `og:image` は、無いより悪い。**
     * 取りに行って失敗した扱いになり、代わりの画像も出せなくなる。
     * だから **値が在るときだけ出す**（`@if` で括る）。
     */
    const out = layoutOf();

    expect(out).not.toContain('property="og:image" content=""');
    expect(out).toContain("@endif");
  });

  it("原本が既に持っているものは足さない", () => {
    // **二重に出さない。**原本の言葉の方が、その案件のものである
    const out = layoutOf(
      SOURCE.replace(
        "<title>株式会社 みどり造園</title>",
        `<title>株式会社 みどり造園</title>\n  <meta name="description" content="原本の説明">`,
      ),
    );

    expect((out.match(/name="description"/g) ?? []).length).toBe(1);
    expect(out).toContain("原本の説明");
  });

  it("題と説明はページごとに変わる", () => {
    // 全ページ同じ説明だと、検索結果でどれも同じに見える
    const out = layoutOf();

    expect(out).toContain("$title");
    expect(out).toContain("$metaDescription");
  });

  // ── 3. 読み取れないなら組まない ──────────────────────

  it("head が無ければ組まない", () => {
    expect(splitDocumentShell(parse(`<div></div>`))).toBeNull();
  });

  it("渡されたものが無ければ組まない", () => {
    expect(splitDocumentShell(null)).toBeNull();
  });

  it("置き場所は layouts/preserved/<slug>（ページごとに 1 本。共有しない）", () => {
    const doc = parse(SOURCE, { blockTextElements: { script: true, style: true } });
    const shell = splitDocumentShell(doc)!;
    const frame = buildImportedPageBlocks(doc)!.frame;
    const home = buildImportedLayout(shell.head, frame, shell.htmlAttrs, shell.bodyAttrs, "home");
    const company = buildImportedLayout(shell.head, frame, shell.htmlAttrs, shell.bodyAttrs, "company");

    // 🔴 1 本を全 document ページに当てると、取り込みと無関係なページ（新規 / WXR）まで
    //    原本の枠に載る（Codex 指摘 ②）。slug で引ける置き場所に、ページごとに置く
    expect(home.target).toBe("resources/views/layouts/preserved/home.blade.php");
    expect(company.target).toBe("resources/views/layouts/preserved/company.blade.php");
    expect(home.content).toContain("ページ「home」だけ");
  });
});
