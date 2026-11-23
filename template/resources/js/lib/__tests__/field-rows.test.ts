import { describe, expect, it } from 'vitest';
import { addRow, moveRow, removeRow, updateRow } from '@/lib/field-rows';
import type { FieldRow } from '@/types/field-group-form';
import { emptyFieldRow } from '@/types/field-group-form';

const rows = (...keys: string[]): FieldRow[] =>
    keys.map((key) => ({ ...emptyFieldRow('text'), key }));

const keys = (list: FieldRow[]): string[] => list.map((row) => row.key);

describe('項目の行の操作', () => {
    it('足すと末尾に空欄が 1 つ増える', () => {
        const result = addRow(rows('a'), 'textarea');

        expect(result).toHaveLength(2);
        expect(result[1].key).toBe('');
        expect(result[1].type).toBe('textarea');
    });

    it('直すのは指定した 1 行だけ', () => {
        const before = rows('a', 'b');
        const after = updateRow(before, 1, { ...before[1], key: 'changed' });

        expect(keys(after)).toEqual(['a', 'changed']);
    });

    /**
     * **最後の 1 行を外したら空欄を 1 行残す。**
     *
     * 項目が 1 つも無いまま送っても必ず弾かれるので、
     * 「消えて何も無くなった」画面を見せない。
     */
    it('最後の 1 行を外すと空欄が 1 行残る', () => {
        const after = removeRow(rows('a'), 0, 'text');

        expect(after).toHaveLength(1);
        expect(after[0].key).toBe('');
    });

    it('2 行あるときは、外した分だけ減る', () => {
        expect(keys(removeRow(rows('a', 'b'), 0, 'text'))).toEqual(['b']);
    });

    it('動かすと入れ替わる', () => {
        expect(keys(moveRow(rows('a', 'b', 'c'), 1, -1))).toEqual([
            'b',
            'a',
            'c',
        ]);
        expect(keys(moveRow(rows('a', 'b', 'c'), 1, 1))).toEqual([
            'a',
            'c',
            'b',
        ]);
    });

    it('端を越えるときは何も変わらない', () => {
        expect(keys(moveRow(rows('a', 'b'), 0, -1))).toEqual(['a', 'b']);
        expect(keys(moveRow(rows('a', 'b'), 1, 1))).toEqual(['a', 'b']);
    });

    it('元の配列は書き換えない', () => {
        const before = rows('a', 'b');

        addRow(before, 'text');
        removeRow(before, 0, 'text');
        moveRow(before, 0, 1);

        expect(keys(before)).toEqual(['a', 'b']);
    });
});
