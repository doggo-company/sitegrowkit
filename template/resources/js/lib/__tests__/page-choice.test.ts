import { describe, expect, it } from 'vitest';
import { isSelectablePage, normalizePageValue } from '@/lib/page-choice';
import type { PageChoice } from '@/lib/page-choice';

const choices: PageChoice[] = [
    { id: 12, label: '会社概要', slug: 'company' },
    { id: 34, label: 'お知らせ', slug: 'info' },
];

describe('page 型の値を、いま選べる値へ落とす', () => {
    it('候補に在る id はそのまま', () => {
        expect(normalizePageValue(12, choices)).toBe(12);
        // 画面から来る値は文字列のことがある
        expect(normalizePageValue('34', choices)).toBe(34);
    });

    it('候補から消えた id は「選ばない」へ落ちる', () => {
        // 下書きへ戻した・ゴミ箱へ入れた、が起きたあとの状態。
        // ここで落とさないと、画面は「選ばない」と出しているのに古い id を送り、
        // **無関係な項目まで保存できなくなる**（監査 指摘 1）
        expect(normalizePageValue(99, choices)).toBeNull();
    });

    it('値が無いときは「選ばない」', () => {
        expect(normalizePageValue(null, choices)).toBeNull();
        expect(normalizePageValue('', choices)).toBeNull();
        expect(normalizePageValue(undefined, choices)).toBeNull();
        expect(normalizePageValue('abc', choices)).toBeNull();
    });

    it('候補を読み込めていないときは、値を消さない', () => {
        // 「分からない」を「無効」と読み替えると、読み込みに失敗しただけで
        // 設定が消える。消えたことは画面からは分からない
        expect(normalizePageValue(99, null)).toBe(99);
    });

    it('候補の中に在るかどうかを、表示の出し分けに使える', () => {
        expect(isSelectablePage(12, choices)).toBe(true);
        expect(isSelectablePage(99, choices)).toBe(false);
        expect(isSelectablePage(12, null)).toBe(false);
        expect(isSelectablePage(null, choices)).toBe(false);
    });
});
