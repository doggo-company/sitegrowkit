import { describe, expect, it } from 'vitest';
import { fieldPickerNote } from '@/lib/form-field-picker';

describe('入力項目の欄に添える一言', () => {
    /**
     * **新規で「共有しています」と言わない。**
     * 作る前は束がまだ無いので、共有のしようがない（R-11 の実測 1）。
     */
    it('新規作成では、これから追加できることを言う', () => {
        const note = fieldPickerNote({ isNew: true, hasFieldEditor: false });

        expect(note).toBe('入力項目は、保存したあとにこの画面の中で追加できます。');
        expect(note).not.toContain('共有');
    });

    it('束が無いときは、選んでほしいことを言う', () => {
        expect(
            fieldPickerNote({ isNew: false, hasFieldEditor: false }),
        ).toContain('まだ決まっていません');
    });

    it('材料が在るのにこの欄が出ているなら、共有している', () => {
        expect(
            fieldPickerNote({ isNew: false, hasFieldEditor: true }),
        ).toContain('ほかでも使われている項目を共有しています');
    });

    it('3 通りとも違うことを言う', () => {
        const notes = [
            fieldPickerNote({ isNew: true, hasFieldEditor: false }),
            fieldPickerNote({ isNew: false, hasFieldEditor: false }),
            fieldPickerNote({ isNew: false, hasFieldEditor: true }),
        ];

        expect(new Set(notes).size).toBe(3);
    });
});
