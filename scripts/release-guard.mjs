/**
 * 配布物を上書きしてよいかの判断（F-15 の根治）。
 *
 * ## なぜ止めるのか
 *
 * release ディレクトリを黙って作り直すと、**そこに在った成果物と、それが
 * どの commit から作られたかの記録が、同時に消える**。同じ版番号で中身の違う
 * 成果物が生まれても、あとから「どちらが何だったか」を言えなくなる。
 *
 * 実際に起きた（2026-08-18）: accepted な `v0.8.0-rc15` の成果物が、
 * 手順を実証するための再実行で置き換わり、**accepted な artifact は失われた**。
 * 残ったのは `PHASE8_5_SPEC` に書いてあった SHA だけだった。
 *
 * ## なぜ関数に切り出してあるのか
 *
 * 本体（`package-release.mjs`）は走らせると build も pack も smoke test もする。
 * **判断だけを試験できる形**にしておかないと、この門は試されないまま置かれる。
 */

/**
 * 上書きになるなら**止める理由**、進んでよければ `null`。
 *
 * @param {{bundleName: string, bundleExists: boolean, zipExists: boolean, force: boolean}} state
 * @returns {string | null}
 */
export function overwriteRefusal({ bundleName, bundleExists, zipExists, force }) {
  if (!bundleExists && !zipExists) {
    return null;
  }

  if (force) {
    return null;
  }

  return [
    `${bundleName} の配布物が既に在ります。上書きしません。`,
    "その場で作り直すと、在った成果物と「どの commit から作ったか」の記録が同時に消えます",
    `別の版として作るなら VERSION を上げてください。`,
    `どうしても置き換えるなら --force を付けてください（消えたものは戻りません）。`,
  ].join("\n  ");
}
