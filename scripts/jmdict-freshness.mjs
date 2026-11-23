/**
 * カタカナ語の表（JMdict 由来）が新しい版から作られているかの判断（UX 一周 20 周目 R2 の後始末）。
 *
 * ## なぜ止めるのか
 *
 * EDRDG の許諾（https://www.edrdg.org/edrdg/licence.html）は、JMdict を使うソフトウェアに
 * **新しい版から定期的に作り直す手順を持つこと**を求めていて、守らないと許諾違反になる
 * （例として、辞書のサーバーは少なくとも月に 1 回）。だから版を切るとき、表の元にした JMdict の
 * 版の日付が {@link MAX_AGE_DAYS} 日より古ければ、版を切らない。
 *
 * 表を手で書き換えたもの（記録した sha256 と合わないもの）でも止める。どの版から作ったかを言えなくなるから。
 *
 * ## なぜ関数に切り出してあるのか
 *
 * 本体（`package-release.mjs`）は build も pack もする。判断だけを試験できる形にしておく
 * （`release-guard.mjs` と同じ作法）。
 */

/** 版の日付からこの日数を超えたら、作り直すまで版を切らない（許諾の例の「月に 1 回」） */
export const MAX_AGE_DAYS = 31;

export const REBUILD_HINT =
  "最新の JMdict から作り直して commit してください: python3 scripts/build-katakana-english.py --fetch";

/**
 * 進んでよければ `null`、止めるなら理由。
 *
 * @param {{meta: {jmdict_created?: string, table_sha256?: string} | null, tableSha256: string | null, today: Date}} state
 * @returns {string | null}
 */
export function jmdictRefusal({ meta, tableSha256, today }) {
  if (tableSha256 === null) {
    return `カタカナ語の表（katakana-english.tsv.gz）がありません。${REBUILD_HINT}`;
  }

  if (
    meta === null ||
    typeof meta.jmdict_created !== "string" ||
    typeof meta.table_sha256 !== "string"
  ) {
    return `カタカナ語の表の記録（katakana-english.json）がありません。どの JMdict の版から作ったか言えません。${REBUILD_HINT}`;
  }

  if (meta.table_sha256 !== tableSha256) {
    return `カタカナ語の表が、記録（katakana-english.json）と合いません（手で書き換えた？）。${REBUILD_HINT}`;
  }

  const created = Date.parse(`${meta.jmdict_created}T00:00:00Z`);

  if (Number.isNaN(created)) {
    return `JMdict の版の日付が読めません（${meta.jmdict_created}）。${REBUILD_HINT}`;
  }

  const age = Math.floor((today.getTime() - created) / 86_400_000);

  if (age > MAX_AGE_DAYS) {
    return [
      `カタカナ語の表の元にした JMdict が古い（版の日付 ${meta.jmdict_created}・${age} 日前・上限 ${MAX_AGE_DAYS} 日）。`,
      "EDRDG の許諾は、新しい版から定期的に作り直すことを求めています（守らないと許諾違反）。",
      REBUILD_HINT,
    ].join("\n  ");
  }

  return null;
}
