import { existsSync, readFileSync } from "node:fs";
import { gunzipSync } from "node:zlib";
import { createRequire } from "node:module";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/**
 * サイト名の読み（フォルダ名の候補・UX 一周 17 周目 G1）。
 *
 * **ページの URL の候補と同じ関数**（同梱の template の `resources/js/lib/reading-slug.mjs`）を、
 * 形態素解析器 kuromoji.js（IPADIC 全体）の結果に当てる。以前は setup とページが別の自前の表で読み、
 * 松本珈琲店が setup では matsumoto-kohi、ページでは matsumoto-kohi-mise と食い違っていた。
 */
export interface Token {
  surface_form: string;
  reading?: string;
  pos: string;
}

type Tokenizer = { tokenize(text: string): Token[] };
type ReadingSlug = (
  title: string,
  tokenize: (text: string) => Token[],
  english?: Map<string, string> | null,
  exceptions?: Map<string, string> | null,
) => string;

const require = createRequire(import.meta.url);
let tokenizer: Promise<Tokenizer> | null = null;
let shared: Promise<ReadingSlug> | null = null;

/** kuromoji の解析器（辞書は kuromoji に同梱の IPADIC。1 回だけ読む） */
export function loadTokenizer(): Promise<Tokenizer> {
  if (!tokenizer) {
    const kuromoji = require("kuromoji") as {
      builder(options: { dicPath: string }): {
        build(callback: (error: Error | null, built: Tokenizer) => void): void;
      };
    };
    const dicPath = join(
      dirname(require.resolve("kuromoji/package.json")),
      "dict",
    );
    tokenizer = new Promise((done, fail) =>
      kuromoji
        .builder({ dicPath })
        .build((error, built) => (error ? fail(error) : done(built))),
    );
  }
  return tokenizer;
}

/** 同梱の template の reading-slug.mjs の場所（repo / npm 配布のどちらでも） */
export function readingSlugPath(): string | null {
  const here = dirname(fileURLToPath(import.meta.url));
  const rel = "resources/js/lib/reading-slug.mjs";
  return (
    [
      resolve(here, "../../../../template", rel),
      resolve(here, "../../template", rel),
    ].find((p) => existsSync(p)) ?? null
  );
}

function loadShared(): Promise<ReadingSlug> {
  if (!shared) {
    const file = readingSlugPath();
    shared = file
      ? import(pathToFileURL(file).href).then(
          (m: { readingSlug: ReadingSlug }) => m.readingSlug,
        )
      : Promise.reject(new Error("reading-slug.mjs が見つかりません"));
  }
  return shared;
}

let english: Map<string, string> | null = null;

/**
 * カタカナ語 → 英語の表（JMdict 由来・20 周目 R2）。同梱の template の
 * `core/resources/reading/katakana-english.tsv.gz`（PHP の TitleReading と同じ表）。
 */
function englishTable(): Map<string, string> {
  if (english) return english;
  const here = dirname(fileURLToPath(import.meta.url));
  const rel = "core/resources/reading/katakana-english.tsv.gz";
  const file = [
    resolve(here, "../../../../template", rel),
    resolve(here, "../../template", rel),
  ].find((p) => existsSync(p));
  const map = new Map<string, string>();
  if (file) {
    for (const line of gunzipSync(readFileSync(file))
      .toString("utf8")
      .split("\n")) {
      const tab = line.indexOf("\t");
      if (tab > 0) map.set(line.slice(0, tab), line.slice(tab + 1));
    }
  }
  english = map;
  return map;
}

/**
 * 読みの辞書（kuromoji・カタカナ語の表）を先に読み込んでおく（UX 一周 21 周目 Y3）。setup の画面を開いた時点で
 * 始めれば、サイト名を打つころには済んでいる。失敗は候補を出すときにもう一度試すので、ここでは黙る
 */
export function preloadReading(): Promise<void> {
  return Promise.all([loadTokenizer(), loadShared()])
    .then(() => {
      englishTable();
      exceptionsTable();
    })
    .catch(() => {});
}

let exceptions: Map<string, string> | null = null;

/** 読みがずれる語 → URL の語（UX 一周 23 周目 #4）。PHP の TitleReading と同じ `core/resources/reading/reading-exceptions.json` */
function exceptionsTable(): Map<string, string> {
  if (exceptions) return exceptions;
  const here = dirname(fileURLToPath(import.meta.url));
  const rel = "core/resources/reading/reading-exceptions.json";
  const file = [
    resolve(here, "../../../../template", rel),
    resolve(here, "../../template", rel),
  ].find((p) => existsSync(p));
  const map = new Map<string, string>();
  if (file) {
    const parsed = JSON.parse(readFileSync(file, "utf8")) as Record<
      string,
      unknown
    >;
    for (const [word, slug] of Object.entries(parsed)) {
      if (!word.startsWith("_") && typeof slug === "string")
        map.set(word, slug);
    }
  }
  exceptions = map;
  return map;
}

/** サイト名をローマ字の候補にする（読めなければ空文字）。ページの URL（PHP の TitleReading）と同じ決まり */
export async function readSiteName(siteName: string): Promise<string> {
  const [built, readingSlug] = await Promise.all([
    loadTokenizer(),
    loadShared(),
  ]);
  return readingSlug(
    siteName,
    (text) => built.tokenize(text),
    englishTable(),
    exceptionsTable(),
  );
}
