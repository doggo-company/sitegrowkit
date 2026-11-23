import { describe, expect, it } from 'vitest';
import { assignFieldKeys, slugifyFieldKey } from '@/lib/field-key';
import type { FieldRow } from '@/types/field-group-form';
import { emptyFieldRow } from '@/types/field-group-form';

const row = (over: Partial<FieldRow> = {}): FieldRow => ({
    ...emptyFieldRow('text'),
    ...over,
});

describe('表示名からキーを決める', () => {
    it('英字の表示名からは読めるキーを作る', () => {
        expect(slugifyFieldKey('Email')).toBe('email');
        expect(slugifyFieldKey('Your Name')).toBe('your_name');
    });

    /**
     * **日本語からは 1 文字も取れない。**
     * 取れないことを隠さず、空を返す（推測で埋めない）。
     */
    it('日本語の表示名からは作れない', () => {
        expect(slugifyFieldKey('お名前')).toBe('');
        expect(slugifyFieldKey('　')).toBe('');
    });

    it('数字で始まるキーは使えないので作らない', () => {
        expect(slugifyFieldKey('2nd choice')).toBe('');
    });
});

describe('新しい行にキーを付ける', () => {
    it('日本語の表示名なら通し番号になる', () => {
        const result = assignFieldKeys([
            row({ label: 'お名前' }),
            row({ label: 'ご用件' }),
        ]);

        expect(result.map((r) => r.key)).toEqual(['field_1', 'field_2']);
    });

    it('英字の表示名からは読めるキーになる', () => {
        expect(assignFieldKeys([row({ label: 'Email' })])[0].key).toBe('email');
    });

    it('既に使われているキーは避ける', () => {
        const result = assignFieldKeys([
            row({ key: 'email', id: '01ABC', label: 'メール' }),
            row({ label: 'Email' }),
        ]);

        expect(result.map((r) => r.key)).toEqual(['email', 'email_2']);
    });

    /**
     * **保存済みの行のキーは、二度と変えない。**
     * 受信データがそのキーで残っているので、付け直すと
     * 過去の受信が別の項目のものに見える。
     */
    it('保存済みの行は触らない', () => {
        const result = assignFieldKeys([
            row({ key: '', id: '01ABC', label: 'お名前' }),
        ]);

        expect(result[0].key).toBe('');
    });

    it('入れ子の中の新しい行にも付く', () => {
        const result = assignFieldKeys([
            row({
                label: 'まとまり',
                type: 'group',
                fields: [row({ label: '中の項目' })],
            }),
        ]);

        expect(result[0].fields[0].key).toBe('field_1');
        expect(result[0].key).toBe('field_2');
    });
});
