import { describe, expect, it } from 'vitest';

import {
    detachCommand,
    placementFor,
    impactSummary,
    instanceLabel,
    instanceOf,
    isInstance,
    placeCommand,
} from '@/lib/reusable';
import type { ReusableSummary } from '@/lib/reusable';
import type { ComponentSpec, DocumentNode } from '@/types/document';

/**
 * 画面が作るものを、画面を開かずに固める（Phase 9.6 / P0-C7）。
 *
 * C5 の教訓: **判定を固めても、画面が作る payload を通していなければ
 * 「押しても何も起きない」画面が出る**（f765cb7）。
 */

const components: ReusableSummary[] = [
    {
        id: '01HEADER',
        key: 'header',
        label: 'サイトヘッダー',
        rootType: 'section',
        published: true,
        publishedAt: '2026-08-19T00:00:00+09:00',
        archived: false,
    },
];

const node = (extra: Partial<DocumentNode> = {}): DocumentNode => ({
    id: 'N1',
    type: 'section',
    props: {},
    children: [],
    ...extra,
});

describe('参照かどうか', () => {
    it('instanceOf を持つ node は参照である', () => {
        expect(isInstance(node({ instanceOf: '01HEADER' }))).toBe(true);
        expect(instanceOf(node({ instanceOf: '01HEADER' }))).toBe('01HEADER');
    });

    it('空文字は参照ではない', () => {
        expect(isInstance(node({ instanceOf: '' }))).toBe(false);
        expect(instanceOf(node({ instanceOf: '' }))).toBeNull();
    });

    it('持たない node は参照ではない', () => {
        expect(isInstance(node())).toBe(false);
    });
});

describe('名前の出し方', () => {
    it('実体の名前を出す', () => {
        expect(
            instanceLabel(node({ instanceOf: '01HEADER' }), components),
        ).toBe('サイトヘッダー');
    });

    /** **ULID を見せても直せない。** 描画側も出さないので、その説明にもなる */
    it('見つからないときは id を出さない', () => {
        const label = instanceLabel(node({ instanceOf: '01GONE' }), components);

        expect(label).toBe('見つかりません');
        expect(label).not.toContain('01GONE');
    });

    it('参照していなければ名前は無い', () => {
        expect(instanceLabel(node(), components)).toBeNull();
    });
});

describe('送るものの形', () => {
    /** **`page.document.patch` の command として送る**（独立した口は無い） */
    it('切り離しは patch の command である', () => {
        expect(detachCommand('N1', 7)).toEqual({
            operation: 'component.detach',
            target: 'N1',
            expectedVersion: 7,
        });
    });

    /**
     * **入れ物の type は実体の起点と同じ。**
     *
     * 揃っていないとサーバーが断る（展開したときに置き場所の規則に反する木ができる）。
     * ここを画面の都合で決めると、「押しても何も起きない」に戻る。
     */
    it('参照を置く command は実体の起点の type を使う', () => {
        expect(placeCommand('S1', 'section', '01HEADER', 3)).toEqual({
            operation: 'component.insert',
            parent: 'S1',
            component: { type: 'section', instanceOf: '01HEADER' },
            expectedVersion: 3,
        });
    });
});

describe('影響の言い方', () => {
    /** 問いは「いま公開されている見た目がいくつ変わるか」 */
    it('公開中を主に出す', () => {
        expect(impactSummary(3, 0)).toBe('公開中の 3 ページに反映されます');
    });

    it('下書きは分けて添える', () => {
        expect(impactSummary(3, 9)).toBe(
            '公開中の 3 ページに反映されます（下書きのみ 9 ページ）',
        );
    });

    /** **大きく見せた警告は読まれなくなる。** 公開に出ないなら、そう言う */
    it('公開に出ないならそう言う', () => {
        expect(impactSummary(0, 9)).toBe(
            '公開中のページには、まだ使われていません（下書きのみ 9 ページ）',
        );
        expect(impactSummary(0, 9)).not.toContain('9 ページに反映');
    });

    it('どこにも使われていなければそう言う', () => {
        expect(impactSummary(0, 0)).toBe('まだどのページにも使われていません');
    });
});

/**
 * **置ける場所は「その type を置ける場所」と同じ。**
 *
 * 再利用ブロックのために別の置き場所の規則を持たない（`insertParent` を使い回す）。
 */
describe('置ける場所', () => {
    const specs: Record<string, ComponentSpec> = {
        section: {
            type: 'section',
            label: 'セクション',
            category: 'basic',
            since: 1,
            propsSchema: {},
            styleSchema: [],
            bindable: [],
            accepts: {},
            allowedChildren: ['*'],
            allowedParents: ['page'],
            responsive: [],
            container: true,
        },
    };

    const root: DocumentNode = {
        id: 'ROOT',
        type: 'page',
        props: {},
        children: [],
    };

    it('起点の type を置ける親を返す', () => {
        expect(placementFor(components[0], null, [], root, specs)).toEqual({
            parentId: 'ROOT',
            rootType: 'section',
        });
    });

    /** 起点が分からないものは**選ばせない** */
    it('起点の type が無ければ置けない', () => {
        expect(
            placementFor(
                { ...components[0], rootType: null },
                null,
                [],
                root,
                specs,
            ),
        ).toBeNull();
    });

    /** registry から外れた component の実体も置けない */
    it('起点の component が登録されていなければ置けない', () => {
        expect(
            placementFor(
                { ...components[0], rootType: 'gone' },
                null,
                [],
                root,
                specs,
            ),
        ).toBeNull();
    });
});
