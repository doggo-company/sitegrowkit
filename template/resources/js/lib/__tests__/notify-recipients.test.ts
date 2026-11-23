import { describe, expect, it } from 'vitest';

import { notifyLines } from '@/lib/notify-recipients';

/**
 * 保存形（カンマ区切り）と、画面（1 行 1 アドレス）の橋渡し（R-11 ⑤）。
 *
 * ここがずれると、**2 件目以降が編集画面から消える**（そして保存で消える）。
 */
describe('notifyLines', () => {
    it('カンマ区切りを 1 行ずつにほどく', () => {
        expect(notifyLines('a@example.test,b@example.test')).toBe(
            'a@example.test\nb@example.test',
        );
    });

    it('前後の空白は落とす（保存側の正規化に任せない表示だけの整え）', () => {
        expect(notifyLines(' a@example.test , b@example.test ')).toBe(
            'a@example.test\nb@example.test',
        );
    });

    it('空・null は空文字（「未設定」を「,」にしない）', () => {
        expect(notifyLines(null)).toBe('');
        expect(notifyLines('')).toBe('');
        expect(notifyLines(',,')).toBe('');
    });

    it('1 件でもそのまま', () => {
        expect(notifyLines('a@example.test')).toBe('a@example.test');
    });
});
