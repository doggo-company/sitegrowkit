import { describe, expect, it } from 'vitest';

import {
    removeFromTree,
    removedSummary,
    restoreToTree,
} from '@/lib/menu-removal';

/**
 * メニューの「取り除く」を、**取り消せる**ようにする
 * （WP 一周 6 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * 「取り除く」を押すと、行が**その場で消える**。保存前なので実際にはまだ
 * 消えていないのだが、**画面はそれを言わない**。
 * さらに `remove()` は「親を消したら子も消える」ので、
 * 入れ子にした項目が**一緒に、黙って**消える。
 *
 * 押した人から見れば「戻せないことをした」ようにしか見えない。
 *
 * ## ここで固定すること
 *
 * 1. 消したものを**同じ場所へ**戻せる（順番も、親子も）
 * 2. **一緒に消えた子の数**を言える（黙って持っていかない）
 */
interface Item {
    id: number;
    label: string;
    children: Item[];
}

const item = (id: number, label: string, children: Item[] = []): Item => ({
    id,
    label,
    children,
});

describe('メニューの取り除き', () => {
    it('上の階層から外して、同じ場所へ戻せる', () => {
        const a = item(1, 'ホーム');
        const b = item(2, '私たちについて');
        const c = item(3, 'お問い合わせ');
        const tree = [a, b, c];

        const removed = removeFromTree(tree, b);

        expect(removed).not.toBeNull();
        expect(tree.map((i) => i.id)).toEqual([1, 3]);

        restoreToTree(tree, removed!);

        // **真ん中に戻る**（末尾に付け足すのは「戻した」ではない）
        expect(tree.map((i) => i.id)).toEqual([1, 2, 3]);
    });

    it('子を外して、同じ親の同じ場所へ戻せる', () => {
        const child1 = item(11, '会社概要');
        const child2 = item(12, '沿革');
        const parent = item(1, '私たちについて', [child1, child2]);
        const tree = [parent];

        const removed = removeFromTree(tree, child1);

        expect(parent.children.map((i) => i.id)).toEqual([12]);

        restoreToTree(tree, removed!);

        expect(parent.children.map((i) => i.id)).toEqual([11, 12]);
    });

    /** 🔴 親を外すと子も一緒に消える。**それを言えるようにする** */
    it('親を外すと子も付いてきて、まとめて戻る', () => {
        const child = item(11, '会社概要');
        const parent = item(1, '私たちについて', [child]);
        const tree = [parent, item(2, 'お問い合わせ')];

        const removed = removeFromTree(tree, parent);

        expect(removed?.childCount).toBe(1);
        expect(tree.map((i) => i.id)).toEqual([2]);

        restoreToTree(tree, removed!);

        expect(tree.map((i) => i.id)).toEqual([1, 2]);
        expect(tree[0].children.map((i) => i.id)).toEqual([11]);
    });

    it('木に無いものは外せない（null を返す）', () => {
        const tree = [item(1, 'ホーム')];

        expect(removeFromTree(tree, item(9, 'よそ'))).toBeNull();
    });

    describe('言い方', () => {
        it('子がいなければ、名前だけを言う', () => {
            expect(removedSummary('お問い合わせ', 0)).toBe(
                '「お問い合わせ」を外しました',
            );
        });

        /** 🔴 **黙って持っていかない。** 数まで言う */
        it('子がいれば、一緒に外れた数も言う', () => {
            expect(removedSummary('私たちについて', 2)).toBe(
                '「私たちについて」と、その下の 2 件を外しました',
            );
        });

        it('名前が空なら、名前のところで嘘をつかない', () => {
            expect(removedSummary('', 0)).toBe('項目を外しました');
        });
    });
});
