/**
 * 配布物の中の Markdown リンクが、配布物の中で生きているかを言う。
 *
 * ## なぜ要るのか
 *
 * リポジトリで開くと通るリンクが、**配布物では死んでいる**ことがある。
 * `docs/` は一覧（`scripts/customer-docs.mjs`）で選んで入れているので、
 * 一覧から漏れたファイルへのリンクは、買った人の手元でだけ 404 になる。
 * 書いた本人は自分のリポジトリで確かめるので、**気づけない形の壊れ方**である。
 *
 * 実際に起きた（2026-09-06 / 0.9.4-rc2）: README_FIRST §7 の
 * `docs/MIGRATE_FROM_WORDPRESS.md` が、zip にも `.tgz` にも無かった。
 *
 * ## 何を見て、何を見ないか
 *
 * - 見る: `[文言](相対パス)` の形。`#見出し` は落として、**ファイルの実在だけ**を見る
 * - 見ない: `http(s):` `mailto:` などスキーム付き（外の世界は配布物の責任ではない）
 * - 見ない: `#` で始まるページ内リンク（同じ理由で、見出しの実在は追わない）
 * - 見ない: コードブロックの中（`--taxonomy=a:b` のような**リンクでない丸括弧**を
 *   拾わないため。逆に、コードブロックの中に書いたリンクは検査されない）
 *
 * **相対パスの実在だけ**に絞ってあるのは、その一点が「買った人の手元で 404 に
 * なるか」と 1 対 1 だからである。見出しの綴りまで見ようとすると偽陽性が増え、
 * 検査そのものが無視されるようになる。
 */

/** ``` / ~~~ で囲まれた範囲を空行へ潰す（リンクでない丸括弧を拾わないため） */
const withoutFencedCode = (markdown) => {
  const lines = markdown.split("\n");
  let fence = null;

  return lines
    .map((line) => {
      const opener = /^\s{0,3}(```+|~~~+)/.exec(line);

      if (fence === null) {
        if (opener) {
          fence = opener[1][0];

          return "";
        }

        return line;
      }

      // 開いたときと同じ記号で閉じる（``` の中の ~~~ で閉じない）
      if (opener && opener[1][0] === fence) fence = null;

      return "";
    })
    .join("\n");
};

/** `a/b/../c` を `a/c` へ。`..` が上へ抜けたら null（配布物の外） */
const resolveRelative = (fromDir, target) => {
  const segments = fromDir === "" ? [] : fromDir.split("/");

  for (const segment of target.split("/")) {
    if (segment === "" || segment === ".") continue;

    if (segment === "..") {
      if (segments.length === 0) return null;
      segments.pop();

      continue;
    }

    segments.push(segment);
  }

  return segments.join("/");
};

/**
 * 生きていないリンクの一覧。全部生きていれば空配列。
 *
 * @param {{
 *   files: Iterable<string>,        // 配布物に在るファイル（root からの相対パス）
 *   read: (rel: string) => string,  // その 1 つを読む
 * }} bundle
 * @returns {list<string>} `README_FIRST.md → docs/X.md（配布物にありません）` の形
 */
export function findBrokenDocLinks({ files, read }) {
  const present = new Set(files);
  const broken = [];

  for (const file of [...present].sort()) {
    if (!file.endsWith(".md")) continue;

    const dir = file.includes("/") ? file.slice(0, file.lastIndexOf("/")) : "";
    const body = withoutFencedCode(read(file));

    for (const [, target] of body.matchAll(/\[[^\]]*\]\(([^)\s]+)\)/g)) {
      // スキーム付き・ページ内リンク・絶対パスは見ない（docblock）
      if (/^[a-z][a-z0-9+.-]*:/i.test(target)) continue;
      if (target.startsWith("#") || target.startsWith("/")) continue;

      const path = decodeURI(target.split("#")[0].split("?")[0]);

      if (path === "") continue;

      const resolved = resolveRelative(dir, path);

      if (resolved === null || !present.has(resolved)) {
        broken.push(`${file} → ${target}（配布物にありません）`);
      }
    }
  }

  return broken;
}
