/**
 * 題の読み（形態素解析）の辞書を作る（UX 一周 19 周目）。
 *
 * 読みはサーバー（PHP）が出す。PHP だけで動くように、mecab-ipadic 2.7.0 の全部（単語・連接コスト・
 * 文字の種類・未知語）を SQLite 1 つにまとめ、gzip して template に置く。PHP は初めて使うときに
 * storage へ展開し、コスト最小の経路（Viterbi・MeCab / kuromoji と同じ考え方）で語に切って読みを出す
 * （`template/core/src/Pages/Reading/IpadicTokenizer.php`）。
 *
 * 使い方（kit の repo で）:
 *
 *   node scripts/build-reading-dictionary.mjs <mecab-ipadic のディレクトリ>
 *
 * 書き出す先: template/core/resources/reading/ipadic.sqlite.gz と ipadic.NOTICE（IPADIC の許諾文。配るときは必ず一緒に）
 */
import { mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { fileURLToPath } from "node:url";
import { gzipSync } from "node:zlib";

const src = process.argv[2];
if (!src) {
  console.error("使い方: node scripts/build-reading-dictionary.mjs <mecab-ipadic のディレクトリ>");
  process.exit(2);
}

const decoder = new TextDecoder("euc-jp");
const read = (name) => decoder.decode(readFileSync(join(src, name)));
const out = resolve(dirname(fileURLToPath(import.meta.url)), "../template/core/resources/reading");
mkdirSync(out, { recursive: true });
const tmp = join(out, "ipadic.sqlite");
rmSync(tmp, { force: true });

const db = new DatabaseSync(tmp);
db.exec(`
  PRAGMA journal_mode = OFF;
  PRAGMA synchronous = OFF;
  CREATE TABLE words (surface TEXT NOT NULL, lid INTEGER NOT NULL, rid INTEGER NOT NULL, cost INTEGER NOT NULL, pos TEXT NOT NULL, reading TEXT);
  CREATE TABLE matrix (lsize INTEGER NOT NULL, rsize INTEGER NOT NULL, costs BLOB NOT NULL);
  CREATE TABLE categories (name TEXT PRIMARY KEY, invoke INTEGER NOT NULL, grp INTEGER NOT NULL, len INTEGER NOT NULL);
  CREATE TABLE char_ranges (lo INTEGER NOT NULL, hi INTEGER NOT NULL, categories TEXT NOT NULL);
  CREATE TABLE unknown (category TEXT NOT NULL, lid INTEGER NOT NULL, rid INTEGER NOT NULL, cost INTEGER NOT NULL, pos TEXT NOT NULL);
  CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
`);

// 単語（すべての CSV）
const insertWord = db.prepare("INSERT INTO words VALUES (?, ?, ?, ?, ?, ?)");
let words = 0;
let maxLength = 0;
db.exec("BEGIN");
for (const file of readdirSync(src).filter((f) => f.endsWith(".csv")).sort()) {
  for (const line of read(file).split("\n")) {
    const cols = line.split(",");
    if (cols.length < 11) continue;
    const surface = cols[0];
    const reading = cols[11] && cols[11] !== "*" ? cols[11] : null;
    insertWord.run(surface, Number(cols[1]), Number(cols[2]), Number(cols[3]), cols[4], reading);
    words += 1;
    maxLength = Math.max(maxLength, [...surface].length);
  }
}
db.exec("COMMIT");
db.exec("CREATE INDEX words_surface ON words (surface)");

// 連接コスト（右文脈 × 左文脈 の int16）
const matrixLines = readFileSync(join(src, "matrix.def"), "latin1").split("\n");
const [lsize, rsize] = matrixLines[0].trim().split(/\s+/).map(Number);
const costs = Buffer.alloc(lsize * rsize * 2);
for (let i = 1; i < matrixLines.length; i += 1) {
  const parts = matrixLines[i].trim().split(/\s+/);
  if (parts.length < 3) continue;
  const [right, left, cost] = parts.map(Number);
  costs.writeInt16LE(cost, (right * rsize + left) * 2);
}
db.prepare("INSERT INTO matrix VALUES (?, ?, ?)").run(lsize, rsize, costs);

// 文字の種類（char.def）
const insertCategory = db.prepare("INSERT INTO categories VALUES (?, ?, ?, ?)");
const insertRange = db.prepare("INSERT INTO char_ranges VALUES (?, ?, ?)");
for (const raw of read("char.def").split("\n")) {
  const line = raw.replace(/#.*$/, "").trim();
  if (line === "") continue;
  if (line.startsWith("0x")) {
    const [range, ...cats] = line.split(/\s+/);
    const [lo, hi = lo] = range.split("..");
    insertRange.run(parseInt(lo, 16), parseInt(hi, 16), cats.join(" "));
  } else {
    const [name, invoke, group, length] = line.split(/\s+/);
    insertCategory.run(name, Number(invoke), Number(group), Number(length));
  }
}

// 未知語（unk.def）
const insertUnknown = db.prepare("INSERT INTO unknown VALUES (?, ?, ?, ?, ?)");
for (const line of read("unk.def").split("\n")) {
  const cols = line.split(",");
  if (cols.length < 5) continue;
  insertUnknown.run(cols[0], Number(cols[1]), Number(cols[2]), Number(cols[3]), cols[4]);
}

const setMeta = db.prepare("INSERT INTO meta VALUES (?, ?)");
setMeta.run("source", "mecab-ipadic-2.7.0-20070801");
setMeta.run("max_length", String(maxLength));
setMeta.run("words", String(words));
db.exec("VACUUM");
db.close();

const raw = readFileSync(tmp);
writeFileSync(join(out, "ipadic.sqlite.gz"), gzipSync(raw, { level: 9 }));
rmSync(tmp);
writeFileSync(
  join(out, "ipadic.NOTICE"),
  "ipadic.sqlite.gz は mecab-ipadic 2.7.0-20070801 を SQLite にまとめたもの（scripts/build-reading-dictionary.mjs）。\n" +
    "以下は IPADIC の許諾文（原文のまま）。\n\n" +
    readFileSync(join(src, "COPYING")).toString("latin1").replace(/[^\x00-\x7f]+\s*$/u, "\n"),
);
console.log(`${words} 語・最長 ${maxLength} 字・SQLite ${(raw.length / 1e6).toFixed(1)}MB・gzip ${(gzipSync(raw, { level: 9 }).length / 1e6).toFixed(1)}MB`);
