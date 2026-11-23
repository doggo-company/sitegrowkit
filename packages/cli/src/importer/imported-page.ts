import { parse } from "node-html-parser";

import type { GeneratedFile } from "./generate.js";
import {
  buildImportedPageBlocks,
  freeBladeVariables,
} from "./imported-blocks.js";
import type { ImportedBlock } from "./imported-blocks.js";
import {
  buildImportedLayout,
  importedLayoutView,
  splitDocumentShell,
} from "./imported-layout.js";

/**
 * 取り込んだ 1 枚を、**画面から編集できるページ**として起こす材料を組む（R03 / B-42）。
 *
 * ## ここは「繋ぐ」場所
 *
 * `buildImportedPageBlocks()`（中身をブロックに割る）と `buildImportedLayout()`（枠を layout に起こす）は
 * 出来ていたが、**どちらも試験からしか呼ばれていなかった**。生成（`generateFromPlan`）にも
 * apply にも繋がっておらず、`agentcms:import-page` には呼び出し元が無かった。
 * **利用者の実経路（inspect → plan → apply → 管理画面で編集 → 公開 URL へ反映）が欠けていた。**
 *
 * ## 元は「生成後の Blade」
 *
 * 原本の HTML ではなく、standalone（preserve）モードが書き出した Blade を読む。
 * 資産の参照は `asset()` へ、ナビは CMS のメニューへ、inline script は外部ファイルへ、
 * 既に書き換わっている。原本から起こすと**画像が全部壊れる**（`imported-layout.ts` 冒頭）。
 *
 * ## 決められないものは作らない。**元サイトの一部を消して公開しない**
 *
 * - `<section>` が 1 つも無く中身の境界を決められない → **ページを作らない**（理由を返す）
 * - `<head>` / `<body>` が読めず枠を組めない → 同上
 * - Blade の指令を含むが、そのブロックの中で意味が閉じている（`@if (true)` など）→
 *   **原本のまま素通しの部品**として元の位置に置く。画面からは編集できないと名前で明示する
 * - 外の変数を参照する Blade・`@include` 等で部品にできないブロック → **ページごと作らない**
 * - 一覧の中身の元（binding の型）が**この案件に無い** → 同上
 *
 * ▷ 以前は「そのブロックだけ外して名前を返す」だった。**それでは `/` を切り替えた瞬間に
 *   その領域が公開面から消える**（Codex 指摘 ①）。切替を止めれば静的な Blade がそのまま
 *   `/` に出て、何も消えない。理由は apply が表示し、dry-run でも同じものが見える。
 */

/** `agentcms:import-page` へ渡す 1 ページ分（stdin JSON の `page` そのもの ＋ 表示用の付帯） */
export interface ImportedPageSpec {
  /** 由来の原本（表示用） */
  source: string;
  title: string;
  slug: string;
  publish: boolean;
  /**
   * 作れたら `front_page_id` に立てる（トップの原本だったページ）。
   *
   * `/` は `PublicSiteController::home()` が受け、**`front_page_id` が立っているときだけ**
   * ページを出す。立てないと、作ったページは `/home` にしか出ず、
   * 元の URL（`/`）は静的な Blade のまま — 編集しても反映されない。
   */
  front: boolean;
  blocks: ImportedBlock["block"][];
  /**
   * このページ専用の枠の view 名（`layouts.preserved.<slug>`）。
   * CMS が**作ったページの id に結び付けて**保存する。既に在るページ（kept）には結び付けない
   */
  layout: string;
  /**
   * 原本のまま素通しにしたブロック（`section#contact` の形）。**画面からは編集できない**。
   * Blade の指令を含むが、そのブロックの中だけで意味が閉じているもの。落とさず、隠さない
   */
  passthrough: string[];
}

export interface ImportedPageBuild {
  files: GeneratedFile[];
  page: ImportedPageSpec;
}

export interface ImportedPageInput {
  /** 原本の名前（`index.html`）— 表示と provenance に使う */
  source: string;
  /** standalone モードが書き出した Blade の全文 */
  blade: string;
  title: string;
  slug: string;
  /** 鍵の頭に付けるページの名前（`home`）。ページをまたぐ衝突を避ける */
  scope: string;
  publish: boolean;
  front: boolean;
  /**
   * この案件に在る（または apply が作る）コンテンツタイプの id。
   * 一覧ブロックの binding がここに無ければ、そのブロックは外す。
   */
  knownTypes: ReadonlySet<string>;
  /** plan で URL が確定した名前付きルートだけを編集可能な初期値へ戻す */
  routeUrls?: ReadonlyMap<string, string>;
}

/**
 * 組めなければ **理由の文字列**を返す（`null` にしない — 理由を落とすと dry-run で読めない）。
 */
export function buildImportedPage(
  input: ImportedPageInput,
): ImportedPageBuild | string {
  // 任意の Blade は実行しない。引数の無い既知の静的ルートの href だけを扱う。
  const editableBlade = input.blade.replace(
    /href="\{\{\s*route\('([^']+)'\)\s*\}\}([^"<>]*)"/g,
    (original, route: string, suffix: string) => {
      const url = input.routeUrls?.get(route);
      if (url === undefined || !url.startsWith("/") || url.startsWith("//") || /[{}"<>\s]/.test(url)) return original;

      return `href="${url}${suffix}"`;
    },
  );
  const { masked, restore } = maskPhpBlocks(editableBlade);
  const document = parse(masked, { comment: true });
  const shell = splitDocumentShell(document);

  if (shell === null) {
    return `${input.source}: <head> か <body> が読み取れないため、枠（layout）を組めません`;
  }

  const split = buildImportedPageBlocks(document, {
    scope: input.scope,
    inspect: restore,
  });

  if (split === null) {
    return `${input.source}: body 直下に <section> が 1 つも無く、中身の境界を決められません（枠と中身を取り違えて公開しないため、ページは作りません）`;
  }

  const files: GeneratedFile[] = [];
  const blocks: ImportedBlock["block"][] = [];

  /*
   * 🔴 **1 つでも部品にできないブロックが在れば、ページごと作らない**（Codex 指摘 ①）。
   *
   * 以前は「そのブロックだけ外して名前を返す」だったが、`/` をページに切り替えると
   * **外した領域が公開面から消える**。警告を出しても、元サイトの一部が消えるのは不可。
   * 切替を止めれば静的な Blade がそのまま `/` に出て、何も消えない（編集できないだけ）。
   */
  const blockers: string[] = split.skipped.map(
    (s) =>
      `${s.name}: Blade の指令（${s.directive}）が入っていて、静的な部品にも一覧の部品にもできません`,
  );

  for (const block of split.blocks) {
    const binding = block.block.binding;

    // 一覧の元が無い型は、CMS に断らせる前にこちらで分かる（`knownTypes` は apply が作る型も含む）
    if (binding !== undefined && !input.knownTypes.has(binding.type)) {
      blockers.push(
        `${block.files[0]?.source ?? block.key}: 一覧の中身の元になるコンテンツタイプ [${binding.type}] がこの案件にありません`,
      );
      continue;
    }

    /*
     * 静的な部品の view が、部品の外の変数を参照していないか（`{{ $works->count() }}` 等）。
     * 指令が無くても `{{ $x }}` だけで `Undefined variable` になる。**500 を出す部品は作らない**
     */
    if (binding === undefined) {
      const view = block.files.find((f) => f.target.endsWith(".blade.php"));
      const free =
        view === undefined
          ? []
          : freeBladeVariables(restore(view.content.toString()), ["props"]);

      if (free.length > 0) {
        blockers.push(
          `${block.files[0]?.source ?? block.key}: 部品の外の変数 ${free.map((v) => `$${v}`).join(", ")} を参照しているため、部品にすると描けません`,
        );
      }
    }
  }

  if (blockers.length > 0) {
    return (
      `${input.source}: 部品にできないブロックがあるため、ページは作りません（静的な Blade のまま公開され、何も消えません）— ` +
      blockers.join(" / ")
    );
  }

  for (const block of split.blocks) {
    for (const file of block.files) {
      files.push({
        ...file,
        content: restore(file.content.toString()),
        source: `${input.source} ${file.source}`,
      });
    }

    blocks.push(block.block);
  }

  // 枠は**このページ専用**（slug で引く）。他のページには当たらない（Codex 指摘 ②）
  const layout = buildImportedLayout(
    shell.head,
    split.frame,
    shell.htmlAttrs,
    shell.bodyAttrs,
    input.slug,
  );

  files.push({
    target: layout.target,
    content: restore(layout.content),
    source: `${input.source}（枠: 最初の <section> より前と <footer> から後。ページ [${input.slug}] 専用）`,
  });

  return {
    files,
    page: {
      source: input.source,
      title: input.title,
      slug: input.slug,
      publish: input.publish,
      front: input.front,
      blocks,
      layout: importedLayoutView(input.slug),
      passthrough: split.blocks
        .filter((b) => b.passthrough === true)
        .map((b) => b.files[0]?.source ?? b.key),
    },
  };
}

/**
 * `@php … @endphp` を、HTML として読ませない。
 *
 * 中身は PHP の文字列で、**HTML の断片が引用符の中に入っている**ことがある
 * （施工事例の装飾を配列で持つ形）。そのまま parse すると文字列の中の `<span>` が
 * 要素として解釈され、直列化し直したときに形が変わる。
 *
 * 印は `@` で始めておく — `extractSectionTemplate()` は `@` で始まる文字を
 * 項目にしないので、万一コメントが文字として拾われても編集項目にはならない。
 */
function maskPhpBlocks(blade: string): {
  masked: string;
  restore: (text: string) => string;
} {
  const blocks: string[] = [];
  const masked = blade.replace(/@php\b[\s\S]*?@endphp/g, (whole) => {
    const index = blocks.push(whole) - 1;

    return `<!--@agentcms-php-${index}-->`;
  });

  const restore = (text: string): string =>
    text.replace(
      /<!--@agentcms-php-(\d+)-->/g,
      (_m, n: string) => blocks[Number(n)] ?? "",
    );

  return { masked, restore };
}
