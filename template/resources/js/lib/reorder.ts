/**
 * 一覧で 1 つ動かすとき、**どの行の直後へ送るか**（P1 ②）。
 *
 * ## なぜ画面から出すのか
 *
 * 口（API）が受けるのは「**どれを、どれの直後へ**」だけである。
 * 「1 つ上へ」を「**2 つ上の直後へ**」に読み替えるのはこの 1 行で、
 * **off-by-one がそのまま並びの誤りになる**。画面の中に置くと、押して確かめるしかない。
 *
 * ## 3 つの答えを混ぜない
 *
 * | 返り値 | 意味 |
 * | --- | --- |
 * | `undefined` | **動かす先が無い**（もう端に居る）。呼ぶ側は送らない |
 * | `null` | **先頭へ**（前に何も無い場所）。API 側も未指定と `null` を区別しない |
 * | id | その行の**直後**へ |
 *
 * `undefined` と `null` を 1 つに畳むと、**端にいる行を押したとき先頭へ飛ぶ**。
 * 「動かせない」と「先頭へ動かす」は別のことなので、別の値で表す。
 */

export interface OrderableRow {
    id: number | string;
}

/** 動かす先。`undefined` は「動かす先が無い」 */
export type ReorderTarget = number | string | null | undefined;

/**
 * 1 つ上へ。**2 つ上の行の直後**へ送る（自分を抜いたあとの位置で考える）。
 *
 * 2 行目は前が 1 行しか無いので**先頭へ**（`null`）。
 */
export function afterIdForMoveUp(
    rows: readonly OrderableRow[],
    index: number,
): ReorderTarget {
    if (index <= 0 || index >= rows.length) {
        return undefined;
    }

    return index >= 2 ? rows[index - 2].id : null;
}

/** 1 つ下へ。**次の行の直後**へ送る。 */
export function afterIdForMoveDown(
    rows: readonly OrderableRow[],
    index: number,
): ReorderTarget {
    if (index < 0 || index >= rows.length - 1) {
        return undefined;
    }

    return rows[index + 1].id;
}
