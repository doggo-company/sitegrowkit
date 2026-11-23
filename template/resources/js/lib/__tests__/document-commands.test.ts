import { describe, expect, it } from 'vitest';

import {
    acceptsChild,
    canDropInto,
    insertParent,
    insertPlan,
    moveDestination,
} from '@/lib/document-commands';
import type { TreeEntry } from '@/lib/document-commands';
import type { ComponentSpec, DocumentNode } from '@/types/document';

/**
 * 画面が組み立てる command（Phase 9.6 / F-19）。
 *
 * **判定ではなく組み立てを見る。** P0-C5 では判定だけを固めて、画面が作る側を
 * 一度も通していなかった（f765cb7）。同じ穴が 5 つの command にも空いていた。
 */

function spec(
    type: string,
    allowedParents: string[],
    allowedChildren: string[] = [],
): ComponentSpec {
    return {
        type,
        label: type,
        category: 'basic',
        since: 1,
        propsSchema: {},
        styleSchema: [],
        bindable: [],
        accepts: {},
        allowedChildren,
        allowedParents,
        responsive: [],
        container: allowedChildren.length > 0,
    };
}

const specs: Record<string, ComponentSpec> = {
    section: spec('section', ['page'], ['*']),
    container: spec('container', ['section', 'container'], ['*']),
    heading: spec('heading', ['section', 'container']),
    // 根の直下にしか置けないもの（親を選び損ねるとどこにも置けない）
    banner: spec('banner', ['page']),
};

function node(
    id: string,
    type: string,
    children: DocumentNode[] = [],
): DocumentNode {
    return { id, type, props: {}, children };
}

/**
 * section > [heading A, container C > [heading B, container D]] という木。
 *
 * **入れ子の container を入れてある。** これが無いと「自分の子孫の中へは
 * 落とせない」規則を一度も通らない — ほかの組み合わせは、その規則より先に
 * 受け入れの判定で落ちるからである（実際に一度、変異が緑のまま通った）。
 */
function tree(): { root: DocumentNode; entries: TreeEntry[] } {
    const containerD = node('D', 'container');
    const headingB = node('B', 'heading');
    const container = node('C', 'container', [headingB, containerD]);
    const headingA = node('A', 'heading');
    const section = node('S', 'section', [headingA, container]);
    const root = node('ROOT', 'page', [section]);

    return {
        root,
        entries: [
            { node: section, parent: root, index: 0 },
            { node: headingA, parent: section, index: 0 },
            { node: container, parent: section, index: 1 },
            { node: headingB, parent: container, index: 0 },
            { node: containerD, parent: container, index: 1 },
        ],
    };
}

const at = (entries: TreeEntry[], id: string): TreeEntry =>
    entries.find((e) => e.node.id === id) as TreeEntry;

describe('受け入れの判定', () => {
    it('宣言された親の中にだけ置ける', () => {
        const { root, entries } = tree();

        expect(acceptsChild(at(entries, 'S').node, specs.heading, specs)).toBe(
            true,
        );
        // heading の allowedParents に page は無い
        expect(acceptsChild(root, specs.heading, specs)).toBe(false);
        expect(acceptsChild(root, specs.section, specs)).toBe(true);
    });
});

/**
 * 空のページの 1 手目（UX 一周 2026-09-27）。
 *
 * 空のページで「見出し」を押しても何も起きず、先にセクションを置く必要が
 * 画面のどこにも書かれていなかった。**入れ物ごと置く段取り**を返せること。
 */
describe('追加の段取り（入れ物ごと置く）', () => {
    const empty = (): { root: DocumentNode; entries: TreeEntry[] } => ({
        root: node('ROOT', 'page'),
        entries: [],
    });

    it('空のページでは、見出しはセクションごと根へ置く', () => {
        const { root, entries } = empty();

        const plan = insertPlan(specs.heading, null, entries, root, specs, [
            specs.section,
        ]);

        expect(plan?.parent.id).toBe('ROOT');
        expect(plan?.wrapper?.type).toBe('section');
    });

    it('直接置けるときは入れ物を足さない', () => {
        const { root, entries } = tree();

        const plan = insertPlan(specs.heading, null, entries, root, specs, [
            specs.section,
        ]);

        expect(plan?.parent.id).toBe('S');
        expect(plan?.wrapper).toBeNull();
    });

    it('セクションそのものは入れ物で包まない', () => {
        const { root, entries } = empty();

        const plan = insertPlan(specs.section, null, entries, root, specs, [
            specs.section,
        ]);

        expect(plan?.parent.id).toBe('ROOT');
        expect(plan?.wrapper).toBeNull();
    });

    it('中へ入れられない入れ物は使わない（無ければ null のまま）', () => {
        const { root, entries } = empty();
        // 子を受けない入れ物しか無い
        const closed = spec('closed', ['page'], []);

        const plan = insertPlan(
            specs.heading,
            null,
            entries,
            root,
            { ...specs, closed },
            [closed],
        );

        expect(plan).toBeNull();
    });
});

describe('追加先', () => {
    /** 押せるボタンは、必ず置ける先を持っている */
    it('選んでいる節点が受け入れるならそこへ', () => {
        const { root, entries } = tree();

        const parent = insertParent(
            specs.heading,
            at(entries, 'S'),
            entries,
            root,
            specs,
        );

        expect(parent?.id).toBe('S');
    });

    it('選んでいる節点が受け入れないなら先祖へ遡る', () => {
        const { root, entries } = tree();

        const parent = insertParent(
            specs.heading,
            at(entries, 'A'),
            entries,
            root,
            specs,
        );

        expect(parent?.id).toBe('S');
    });

    /**
     * **選んだ場所から遠くても、置ける先を見つける。**
     *
     * banner は根の直下にしか置けない。木の奥を選んでいても根へ行き着く。
     */
    it('先祖に無ければ根まで見る', () => {
        const { root, entries } = tree();

        const parent = insertParent(
            specs.banner,
            at(entries, 'B'),
            entries,
            root,
            specs,
        );

        expect(parent?.id).toBe('ROOT');
    });

    /**
     * **どこにも置けないなら null。**
     *
     * ここで場所を捏造すると、`allowedParents` に無い親へ送る command ができる。
     * 送れば validator が断るが、画面には「押したのに何も起きない」だけが残る。
     */
    it('置ける先が無ければ組み立てない', () => {
        const { root, entries } = tree();
        const orphan = spec('orphan', ['nowhere']);

        expect(
            insertParent(orphan, at(entries, 'A'), entries, root, specs),
        ).toBeNull();
    });

    /** 返した親は、必ずその component を受け入れる */
    it('返す親は必ず受け入れる側である', () => {
        const { root, entries } = tree();

        for (const target of [specs.heading, specs.section, specs.banner]) {
            for (const from of [null, at(entries, 'A'), at(entries, 'B')]) {
                const parent = insertParent(target, from, entries, root, specs);

                if (parent !== null) {
                    expect(acceptsChild(parent, target, specs)).toBe(true);
                }
            }
        }
    });
});

describe('動かす先', () => {
    it('上へ動かすと 1 つ前の兄弟の後ろへ入る', () => {
        const { entries } = tree();

        // [A, C] の C を上へ → 先頭（after は null）
        expect(moveDestination(at(entries, 'C'), 'up', entries, specs)).toEqual(
            {
                parent: 'S',
                after: null,
            },
        );
    });

    it('下へ動かすと入れ替わる相手の後ろへ入る', () => {
        const { entries } = tree();

        expect(
            moveDestination(at(entries, 'A'), 'down', entries, specs),
        ).toEqual({
            parent: 'S',
            after: 'C',
        });
    });

    it('端では動かさない', () => {
        const { entries } = tree();

        expect(
            moveDestination(at(entries, 'A'), 'up', entries, specs),
        ).toBeNull();
        expect(
            moveDestination(at(entries, 'C'), 'down', entries, specs),
        ).toBeNull();
    });

    it('直前の兄弟の中へ入れる', () => {
        const { entries } = tree();

        // C を A の中へ…は入れない（heading は子を持たない）
        expect(
            moveDestination(at(entries, 'C'), 'in', entries, specs),
        ).toBeNull();
    });

    it('親の外へ出すと、親の直後に並ぶ', () => {
        const { entries } = tree();

        expect(
            moveDestination(at(entries, 'B'), 'out', entries, specs),
        ).toEqual({
            parent: 'S',
            after: 'C',
        });
    });

    /**
     * **`after` は、必ず行き先の親が持っている兄弟である。**
     *
     * ここを外すと「もう居ない節点の後ろへ」という command ができる。
     * 送っても届かず、画面には何も起きない。
     */
    it('afterは行き先の親の子である', () => {
        const { root, entries } = tree();
        const all = [root, ...entries.map((e) => e.node)];

        for (const entry of entries) {
            for (const direction of ['up', 'down', 'in', 'out'] as const) {
                const to = moveDestination(entry, direction, entries, specs);

                if (to === null || to.after === null) {
                    continue;
                }

                const parent = all.find((n) => n.id === to.parent);
                const children = (parent?.children ?? []).map((c) => c.id);

                expect(children).toContain(to.after);
                // 自分自身の後ろへは並べない
                expect(to.after).not.toBe(entry.node.id);
            }
        }
    });
});

describe('掴んで落とす', () => {
    it('受け入れる親にだけ落とせる', () => {
        const { entries } = tree();

        expect(canDropInto(at(entries, 'A'), at(entries, 'C'), specs)).toBe(
            true,
        );
        // section は container の中には入れない
        expect(canDropInto(at(entries, 'S'), at(entries, 'C'), specs)).toBe(
            false,
        );
    });

    it('自分の中と自分の子孫の中へは落とせない', () => {
        const { entries } = tree();

        expect(canDropInto(at(entries, 'C'), at(entries, 'C'), specs)).toBe(
            false,
        );
        /*
         * **C を、C の子である D の中へは落とせない。**
         *
         * container は container を受け入れるので、**受け入れの判定では止まらない**。
         * ここで止めないと、木が自分自身を含む形になる。
         * （この行が `S → B` だったときは、受け入れの判定が先に落としていたので
         * 子孫の規則を一度も通っておらず、外しても赤くならなかった）
         */
        expect(canDropInto(at(entries, 'C'), at(entries, 'D'), specs)).toBe(
            false,
        );
    });
});
