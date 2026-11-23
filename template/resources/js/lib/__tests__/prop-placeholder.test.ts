import { describe, expect, it } from 'vitest';

import { propPlaceholder } from '../prop-placeholder';

/**
 * 仮の文字は Canvas の view（blade）と同じ文言でないといけない（UX 一周 3 周目 N3）。
 * ここの 3 つは `tests/Feature/PlaceholderBlocksTest.php` が blade 側で同じものを見る。
 */
describe('propPlaceholder', () => {
    it('見出し・テキスト・リッチテキストに「〇〇を入力」を出す', () => {
        expect(propPlaceholder('heading', '見出し', 'text', 'テキスト')).toBe(
            '見出しを入力',
        );
        expect(propPlaceholder('text', 'テキスト', 'text', 'テキスト')).toBe(
            'テキストを入力',
        );
        expect(propPlaceholder('richtext', 'リッチテキスト', 'html', '本文')).toBe(
            '本文を入力',
        );
    });

    it('値を入れて置く欄（ボタンの文言・見出しのレベルなど）には出さない', () => {
        expect(propPlaceholder('button', 'ボタン', 'label', 'ラベル')).toBe(
            undefined,
        );
        expect(propPlaceholder('heading', '見出し', 'level', '見出しレベル')).toBe(
            undefined,
        );
        expect(propPlaceholder('image', '画像', 'alt', '代替テキスト')).toBe(
            undefined,
        );
    });
});
