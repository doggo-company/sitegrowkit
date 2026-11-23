import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * 生成側が `@php(...)` の短い形を書いていないか、**ソースを直接見る**。
 *
 * ## なぜ生成物ではなくソースを見るのか
 *
 * 🔴 **生成物を見る検査は、見本にその経路が無いと空振りする。**
 * 実際に「装飾の差し替え」の経路は同梱の見本に無く、
 * **短い形へ戻す変異が緑のまま通った**（2026-08-28 実測 / B-58）。
 *
 * ソースを見れば**経路の網羅に依存しない**。書いた時点で止まる。
 *
 * ## なぜ短い形が危ないのか
 *
 * Blade は `@php ... @endphp` を**ファイル全体から先に拾う**（`storePhpBlocks`）。
 * そこに `@php(` も引っかかるので、**短い形がブロック形より前に 1 つでもあると、
 * そこから最初の `@endphp` までが丸ごと 1 つの PHP ブロックとして食われる。**
 *
 * 施工事例の詳細 view は先頭に短い形が 2 つ、後ろにブロック形が 1 つあり、
 * **生成した時点から全ページ 500 だった**（実案件で実測）。
 * 古いコンパイル結果が残っていたので、しばらく 200 に見えていた。
 */
const SRC = new URL("../src/importer", import.meta.url).pathname;

function tsFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((e) => {
    const p = join(dir, e);

    if (statSync(p).isDirectory()) return tsFiles(p);

    return p.endsWith(".ts") ? [p] : [];
  });
}

/**
 * 実際に出力へ混ざる部分だけを返す（コメントを落とす）。
 *
 * ▷ **判断を 1 か所に置く。**「コメントを落とす」を検査ごとに書くと、
 *   片方だけ直した日に**同じ入力で違う答え**が出る（B-53 の族）。
 *
 * ▷ 落とすのは**行頭コメント**と**引用符の外側の行末コメント**。
 *   文字列の中の `//`（`https://` など）で切ると、行が壊れる。
 */
function codeOf(body: string): string {
  return body
    .split("\n")
    .filter((l) => {
      const t = l.trimStart();

      return !t.startsWith("*") && !t.startsWith("//") && !t.startsWith("/*");
    })
    .map(stripTrailingComment)
    .join("\n");
}

/** 引用符の外側の `//` から後ろを落とす */
function stripTrailingComment(line: string): string {
  let quote: string | null = null;

  for (let i = 0; i < line.length; i += 1) {
    const c = line[i]!;

    if (quote !== null) {
      if (c === "\\") {
        i += 1;

        continue;
      }

      if (c === quote) quote = null;

      continue;
    }

    if (c === '"' || c === "'" || c === "`") {
      quote = c;

      continue;
    }

    if (c === "/" && line[i + 1] === "/") return line.slice(0, i);
  }

  return line;
}

/**
 * `@php(` を**文字列として出している**か。
 *
 * コメントを落としたうえで、**引用符の後ろに在るものだけ**を見る。
 * 片方だけだと、docblock の `` `@php(...)` `` か行末コメントのどちらかに倒れる。
 */
function emitsShortPhp(code: string): boolean {
  return code.split("\n").some((line) => /["\u0027`][^"\u0027`\n]*@php\(/.test(line));
}

describe("綴りを組む場所は 1 つ（D-4）", () => {
  /*
   * 🔴 **同じ綴りを 3 か所が別々に組んでいた**（2026-08-28）。
   * しかも**答えが違った** — 6 通りで試すと 5 通りで食い違う:
   *
   * ```
   * "GALLERY"     imported_gallery     vs  imported_GALLERY
   * "works-list"  imported_works_list  vs  imported_works-list
   * ""            imported_section     vs  imported_
   * ```
   *
   * **定義が指す先と実ファイルの置き場所がずれると、一覧には並ぶのに描けない。**
   *
   * ▷ **D-1 と同じ形の番人**にしてある — ソースを走査して、
   *   **2 つ目の組み立てが現れたらそこで止まる。**
   */
  it("🔴 `imported_` を組み立てているのは `blade-html.ts` だけ", () => {
    const offenders = tsFiles(SRC)
      .filter((f) => !f.endsWith("blade-html.ts"))
      .filter((f) => /[`'"]imported_\$\{/.test(codeOf(readFileSync(f, "utf-8"))))
      .map((f) => f.replace(`${SRC}/`, ""));

    expect(offenders).toEqual([]);
  });

  it("🔴 view の名前を組み立てているのは `blade-html.ts` だけ", () => {
    const offenders = tsFiles(SRC)
      .filter((f) => !f.endsWith("blade-html.ts"))
      .filter((f) =>
        /[`'"]components\.document\.\$\{/.test(codeOf(readFileSync(f, "utf-8"))),
      )
      .map((f) => f.replace(`${SRC}/`, ""));

    expect(offenders).toEqual([]);
  });
});

describe("生成する Blade の @php の書き方", () => {
  it("🔴 `@php(` を 1 つも書かない", () => {
    /*
     * 🔴 **解説文の中の言及を数えない。**
     *
     * この罠は docblock で説明する価値があるので、`@php(` という綴りは
     * **コメントの中に必ず出てくる**。数えると検出器が常に赤になり、
     * **本物と区別が付かなくなる**（最初にそれをやった）。
     * コメント行を落としてから見る。
     */
    const offenders = tsFiles(SRC)
      .filter((f) => emitsShortPhp(codeOf(readFileSync(f, "utf-8"))))
      .map((f) => f.replace(`${SRC}/`, ""));

    expect(offenders).toEqual([]);
  });

  it("`@php` を書くなら `@endphp` と対で書く", () => {
    for (const f of tsFiles(SRC)) {
      const body = readFileSync(f, "utf-8");

      const emitted = codeOf(body);
      const open = (emitted.match(/@php\b/g) ?? []).length;
      const close = (emitted.match(/@endphp\b/g) ?? []).length;

      expect(open, f.replace(`${SRC}/`, "")).toBe(close);
    }
  });
});
