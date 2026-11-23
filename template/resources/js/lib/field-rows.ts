import type { FieldRow } from '@/types/field-group-form';
import { emptyFieldRow } from '@/types/field-group-form';

/**
 * 項目の行を足す・直す・外す・動かす（R-8A ②）。
 *
 * ## なぜ画面から出すか
 *
 * これまで**カスタムフィールドの画面 1 つ**だけが持っていた操作を、
 * **フォームの画面**からも使う。写すと、片方だけ直したときに
 * **同じ操作が 2 通りの振る舞いをする**（「外したら 1 行残る」を片方が忘れる、など）。
 *
 * ## 配列は作り直す
 *
 * その場で書き換えない。途中で例外が出ても、**半端に並び替わった状態が残らない**。
 */
export function addRow(rows: FieldRow[], type: string): FieldRow[] {
    return [...rows, emptyFieldRow(type)];
}

export function updateRow(
    rows: FieldRow[],
    index: number,
    row: FieldRow,
): FieldRow[] {
    return rows.map((current, i) => (i === index ? row : current));
}

/**
 * 外す。**最後の 1 行を外したら、空欄を 1 行残す。**
 *
 * 項目が 1 つも無いまま送っても必ず弾かれるので、
 * 「消えて何も無くなった」画面を見せない。
 */
export function removeRow(
    rows: FieldRow[],
    index: number,
    fallbackType: string,
): FieldRow[] {
    const remaining = rows.filter((_, i) => i !== index);

    return remaining.length === 0 ? [emptyFieldRow(fallbackType)] : remaining;
}

/** 動かす。**端を越えるときは何もしない**（黙って端で止める） */
export function moveRow(
    rows: FieldRow[],
    index: number,
    delta: number,
): FieldRow[] {
    const target = index + delta;

    if (target < 0 || target >= rows.length) {
        return rows;
    }

    return rows.map((row, i) => {
        if (i === index) {
            return rows[target];
        }

        if (i === target) {
            return rows[index];
        }

        return row;
    });
}
