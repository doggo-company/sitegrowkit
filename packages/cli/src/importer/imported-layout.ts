import type { HTMLElement } from "node-html-parser";

import type { ImportedFrame } from "./imported-blocks.js";

/**
 * 取り込んだ意匠を、公開 layout として起こす（B-38 / B-55）。
 *
 * ## なぜ 2 本立てになるのか
 *
 * ページ（document 管理）は `layouts.public` に載る。それは **kit 既定のテーマ**で、
 * 案件の意匠を 1 字も持たない。実案件で実測すると、
 * **`<style>` 58,751 字・ナビ・フッター・`<script>` 3 本のすべてが落ちる**。
 *
 * だから案件側には**もう 1 本**、取り込んだ意匠の layout を置く。
 * `layouts.preserved` が在れば document ページはそちらに載る。
 *
 * ## 「header が取れないから standalone」は、1 枚に限れば成り立たない
 *
 * standalone（preserve）モードは「共通 header/footer を抽出できない」案件のための道だが、
 * **トップ 1 枚だけを見れば境界は明確**だった（2026-08-28 実測）。
 *
 * ```
 *  1〜2   div.bg-pattern / nav#navbar   ← 枠（上）
 *  3〜15  中身                          ← @yield('content')
 * 16〜19  footer / script ×3            ← 枠（下）
 * ```
 *
 * 「複数ページに共通する header を見つける」のは難しいが、
 * **「このページの中身がどこからどこまでか」は難しくない。**別の問題である。
 *
 * ## 取り込む元は、原本の HTML ではなく**生成後の Blade**
 *
 * 🔴 原本には `asset()` が無い。生成の段で資産の参照が書き換わっているので、
 * 原本から起こすと**画像がすべて壊れる**（実案件で実測。11 ブロックに 25 箇所）。
 * `<head>` の inline script も外に出されているため、原本の head では読み込みが変わる。
 */
export interface ImportedLayout {
  target: string;
  content: string;
}

/**
 * `resources/views/layouts/preserved.blade.php` を組む。
 *
 * @param head 生成後の Blade の `<head>` の中身（`<style>` や `asset()` を含む）
 * @param htmlAttrs `<html>` の属性（`lang` など）
 * @param bodyAttrs `<body>` の属性
 */
/**
 * ページ別 layout の置き場所（`resources/views/layouts/preserved/<slug>.blade.php`）。
 *
 * 🔴 **layout はページごとに隔離する**（Codex 指摘 ②）。1 本の `layouts.preserved` を
 * 全 document ページに当てると、**取り込みと無関係なページ（新規・WXR）まで
 * 取り込んだ意匠の枠に載る**。枠はそのページの原本から起こしたものなので、
 * **そのページだけ**が使う。綴りは PHP 側（`DocumentPageView::layoutFor()`）と揃える。
 */
export function importedLayoutTarget(slug: string): string {
  return `resources/views/layouts/preserved/${slug}.blade.php`;
}

/**
 * その layout の view 名（`layouts.preserved.<slug>`）。
 *
 * `agentcms:import-page` へ渡し、CMS が**作ったページの id に結び付けて**保存する
 * （`imported_page_layouts`）。当たるのはその行を持つページだけで、ファイルの有無や slug では決めない。
 */
export function importedLayoutView(slug: string): string {
  return `layouts.preserved.${slug}`;
}

export function buildImportedLayout(
  head: string,
  frame: ImportedFrame,
  htmlAttrs: string,
  bodyAttrs: string,
  /** この layout を使うページの slug。**無指定は認めない**（共有 layout を作らない） */
  slug: string,
): ImportedLayout {
  const top = frame.before.map((el) => el.toString()).join("\n");
  const bottom = frame.after.map((el) => el.toString()).join("\n");
  const seo = seoTags(head);

  const content = `{{--
    原本の枠をそのまま保った公開 layout（B-38）。**生成物** — 手で直すと再 apply 時に conflict になります。

    **ページ「${slug}」だけ**がこれに載る（\`DocumentPageView\` が slug で引く。他のページは \`layouts.public\`）。
    **枠は原本のまま**で、中身だけが差し替わる。

    ▷ **\`layouts.public\` の meta を持ち込んでいない。** 原本の \`<head>\` には
      その案件の title / og が既に書かれている。上から kit 既定を重ねると、
      **どちらが出ているのか読む人に分からなくなる**。
      ページごとに変えたくなった時点で、**ここを直す**のが正しい。
--}}
<!DOCTYPE html>
<html${htmlAttrs}>
<head>
${head.trim()}
${seo}
@stack('head')
</head>
<body${bodyAttrs}>
${top}

${frame.wrap === undefined ? "@yield('content')" : `${frame.wrap.open}\n@yield('content')\n${frame.wrap.close}`}

${bottom}
</body>
</html>
`;

  return { target: importedLayoutTarget(slug), content };
}

/**
 * 検索と共有に必要な tag を足す。**原本が既に持っているものは足さない。**
 *
 * 🔴 **原本の head を 1 バイトも変えない方針にしたので、
 * kit が足していたものが全部落ちていた**（2026-08-28・実案件で実測。
 * 公開 8 ページすべてで `description` も `og:` も `canonical` も 0 件だった）。
 *
 * **効くのは検索と SNS 共有。**トップを X や LINE に貼っても**タイトルしか出ない** —
 * 説明も画像も出ない。**「公開したのに誰にも届かない」の直接の原因になる。**
 *
 * ▷ **意匠は変わらない。**head は見えない。
 *
 * ▷ **原本の言葉が優先。**既に書いてあるなら、その案件のものである。
 *   二重に出すと、どちらが読まれるか分からなくなる。
 *
 * ▷ **layout を通らないページにも要る。**standalone は**ページごとに html を
 *   丸ごと持つ**ので、layout を 1 枚直しても**トップしか直らない**
 *   （2026-08-28 実測。7 ページが 0 件のまま残った）。
 *
 * ▷ 🔴 **共有画像は、値が在るときだけ出す。**空の `og:image` は無いより悪い —
 *   取りに行って失敗した扱いになり、代わりの画像も出せなくなる。
 *
 * ▷ 🔴 **`$site` を直に参照しない。**渡ってこないページがある
 *   （`pages/company` は `$site` を受け取らず、足した瞬間に 500 になった）。
 *   **足したものが原因でページを落とさない** — 出せないなら空で出す。
 */
export function seoTags(head: string): string {
  const has = (needle: string): boolean => head.includes(needle);

  const tags = [
    [
      'name="description"',
      `    <meta name="description" content="{{ $metaDescription ?? data_get($site ?? [], 'seo_description', '') }}">`,
    ],
    [
      'rel="canonical"',
      `    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">`,
    ],
    [
      'property="og:site_name"',
      `    <meta property="og:site_name" content="{{ data_get($site ?? [], 'site_name', config('app.name')) }}">`,
    ],
    [
      'property="og:title"',
      `    <meta property="og:title" content="{{ $title ?? data_get($site ?? [], 'site_name', config('app.name')) }}">`,
    ],
    [
      'property="og:description"',
      `    <meta property="og:description" content="{{ $metaDescription ?? data_get($site ?? [], 'seo_description', '') }}">`,
    ],
    [
      'property="og:type"',
      `    <meta property="og:type" content="{{ $ogType ?? 'website' }}">`,
    ],
    [
      'property="og:url"',
      `    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">`,
    ],
    [
      'property="og:image"',
      [
        `    @if (data_get($site ?? [], 'og_image_url'))`,
        `    <meta property="og:image" content="{{ data_get($site ?? [], 'og_image_url') }}">`,
        `    <meta name="twitter:card" content="summary_large_image">`,
        `    @endif`,
      ].join("\n"),
    ],
  ] as const;

  const added = tags.filter(([needle]) => !has(needle)).map(([, tag]) => tag);

  return added.length === 0
    ? ""
    : `\n    {{-- 検索と共有に必要なもの（原本に無かったぶんだけ足す）--}}\n${added.join("\n")}`;
}

/** `<html>` / `<body>` / `<head>` を、生成後の Blade から取り出す */
export function splitDocumentShell(document: HTMLElement | null): {
  head: string;
  htmlAttrs: string;
  bodyAttrs: string;
} | null {
  if (document === null) return null;

  const head = document.querySelector("head");
  const body = document.querySelector("body");

  // **枠が読み取れないなら組まない。**足りないまま出すと、読み込みが静かに変わる
  if (head === null || body === null) return null;

  return {
    head: head.innerHTML,
    htmlAttrs: attrsOf(document.querySelector("html")),
    bodyAttrs: attrsOf(body),
  };
}

function attrsOf(el: HTMLElement | null): string {
  if (el === null) return "";

  const entries = Object.entries(el.attributes);

  return entries.length === 0
    ? ""
    : ` ${entries.map(([k, v]) => `${k}="${v}"`).join(" ")}`;
}
