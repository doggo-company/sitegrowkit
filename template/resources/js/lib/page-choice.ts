/**
 * ページを指す欄（`page` 型）の値の扱い（R-5 / 監査 指摘 1）。
 *
 * ## なぜ判断だけを切り出すか
 *
 * 「いま選べない値をどう扱うか」は **画面の 2 か所**（Select の表示と、
 * フォームの初期値）が同じ答えを出さなければならない。片方だけ直すと、
 * **画面には『選ばない』と出ているのに、送るのは古い id** になり、
 * 保存が丸ごと弾かれる — 監査 指摘 1 がまさにこれだった。
 *
 * 判断を 1 つの純関数にして、両方がここを呼ぶ。試験もここに当てられる。
 */
export interface PageChoice {
    id: number;
    label: string;
    slug: string;
}

/**
 * 保存されている値を、**いま選べる値**へ落とす。
 *
 * - 候補に在る id  … その id
 * - 候補に無い id  … `null`（＝「選ばない」）
 * - **候補が `null`（読み込めていない）… 値をそのまま返す**
 *   分からないことを「無効」と読み替えない。読み込みに失敗しただけで
 *   設定を消してしまうのは、直せない side effect である
 */
export function normalizePageValue(
    value: unknown,
    choices: PageChoice[] | null,
): number | null {
    const id = toId(value);

    if (id === null) {
        return null;
    }

    if (choices === null) {
        return id;
    }

    return choices.some((choice) => choice.id === id) ? id : null;
}

/** 保存されている値が、いま候補の中に在るか（表示の出し分け用） */
export function isSelectablePage(
    value: unknown,
    choices: PageChoice[] | null,
): boolean {
    const id = toId(value);

    return id !== null && choices !== null
        ? choices.some((choice) => choice.id === id)
        : false;
}

/** 数として読める値だけを id とみなす（空文字・null・非数は id ではない） */
function toId(value: unknown): number | null {
    if (typeof value === 'number') {
        return Number.isInteger(value) ? value : null;
    }

    if (typeof value === 'string' && /^[0-9]+$/.test(value)) {
        return Number(value);
    }

    return null;
}
