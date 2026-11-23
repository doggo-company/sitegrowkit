import { describe, expect, it } from 'vitest';

import { cellAt, fieldForHint, sampleCellAt } from '../sample-text';

/** UX 一周 4 周目 R4-1・R4-2（ヘアサロン・スマホ）の回帰 */
describe('sample-text', () => {
    const items = '〇〇（ドリンク）｜〇〇円\n〇〇（フード）｜〇〇円\nカット|4,400円';

    it('押した位置のマスを「｜」と改行で区切って返す', () => {
        expect(items.slice(...cellAt(items, 2))).toBe('〇〇（ドリンク）');
        expect(items.slice(...cellAt(items, 11))).toBe('〇〇円');
        expect(items.slice(...cellAt(items, items.length - 1))).toBe('4,400円');
    });

    it('見本のマスだけ選び、店が書いたマスは選ばない', () => {
        const at = items.indexOf('（フード）');
        expect(items.slice(...(sampleCellAt(items, at) ?? [0, 0]))).toBe(
            '〇〇（フード）',
        );
        expect(sampleCellAt(items, items.indexOf('カット'))).toBe(null);
        expect(sampleCellAt('〇〇曜日', 4)).toEqual([0, 4]);
    });

    it('欄の中身がまるごと見本なら、全部を選ぶ（5 周目 R4-2）', () => {
        const fresh = '〇〇（品名・コース名）｜〇〇円\n〇〇（品名・コース名）｜〇〇円';
        expect(sampleCellAt(fresh, 20)).toEqual([0, fresh.length]);
        // 1 マスでも店が書いていれば、押したマスだけ
        expect(items.slice(...(sampleCellAt(items, 2) ?? [0, 0]))).toBe('〇〇（ドリンク）');
    });

    it('ブロックの名前のままの文言（ボタンの「ボタン」）は全部選ぶ', () => {
        expect(sampleCellAt('ボタン', 3, 'ボタン')).toEqual([0, 3]);
        expect(sampleCellAt('予約する', 4, 'ボタン')).toBe(null);
    });

    it('プレビューで押した文字の欄を選ぶ（表の題に飛ばない）', () => {
        const fields = [
            { key: 'caption', text: '' },
            { key: 'items', text: items },
            { key: 'note', text: '' },
        ];

        expect(fieldForHint(fields, '〇〇（ドリンク）')).toBe('items');
        expect(
            fieldForHint(
                [
                    { key: 'caption', text: '営業時間' },
                    { key: 'rows', text: '〇曜日〜〇曜日｜〇〇:〇〇〜〇〇:〇〇' },
                    { key: 'closed', text: '〇〇曜日' },
                ],
                '〇曜日〜〇曜日',
            ),
        ).toBe('rows');
        expect(
            fieldForHint(
                [
                    { key: 'caption', text: '営業時間' },
                    { key: 'closed', text: '〇〇曜日' },
                ],
                '〇〇曜日',
            ),
        ).toBe('closed');
        expect(fieldForHint(fields, '関係ない文字')).toBe(undefined);
    });
});
