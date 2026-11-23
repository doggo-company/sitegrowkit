import { describe, expect, it } from 'vitest';

import { saveErrorItems } from '@/lib/save-errors';

/** 保存できなかった理由を、保存ボタンの横で並べる（UX 一周 2026-09-28） */
describe('saveErrorItems', () => {
    const fields = [
        { key: 'site_name', label: 'サイト名' },
        { key: 'tel', label: '電話番号' },
        { key: 'contact_email', label: '問い合わせ通知先' },
    ];

    it('空なら何も出さない', () => {
        expect(saveErrorItems({}, fields)).toEqual([]);
    });

    it('サイト名が空で落ちたら、サイト名を名指しし入力欄の id を返す', () => {
        expect(
            saveErrorItems({ site_name: 'サイト名は必須です。' }, fields),
        ).toEqual([
            {
                key: 'site_name',
                label: 'サイト名',
                message: 'サイト名は必須です。',
                inputId: 'site_name',
            },
        ]);
    });

    it('画面の並び順で返す（上の項目から直せるように）', () => {
        const items = saveErrorItems(
            { contact_email: '形式が違います。', site_name: '必須です。' },
            fields,
        );

        expect(items.map((i) => i.key)).toEqual(['site_name', 'contact_email']);
    });

    it('入れ子の項目は親の名前で呼び、id は - でつなぐ', () => {
        const [item] = saveErrorItems({ 'tel.0.number': '数字で' }, fields);

        expect(item).toMatchObject({
            label: '電話番号',
            inputId: 'tel-0-number',
        });
    });

    it('知らない項目も落とさず、名前を付けずに理由だけ返す', () => {
        expect(
            saveErrorItems({ conflict: '別の人が保存しました' }, fields)[0],
        ).toMatchObject({ label: '', message: '別の人が保存しました' });
    });
});
