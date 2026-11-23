import { describe, expect, it } from 'vitest';

import { withFirstHeadingLevel } from '@/lib/document-commands';
import type { ComponentSpec, DocumentNode } from '@/types/document';

/**
 * ページで最初の見出しは 1 段目で置く（UX 一周 2026-09-28）。
 *
 * 既定の段は 2 なので、空のページで見出しを置いた直後に「要修正」が出ていた。
 */
function spec(type: string, extra: Partial<ComponentSpec> = {}): ComponentSpec {
    return {
        type,
        label: type,
        category: 'basic',
        since: 1,
        propsSchema: {},
        styleSchema: [],
        bindable: [],
        accepts: {},
        allowedChildren: [],
        allowedParents: ['section'],
        responsive: [],
        container: false,
        ...extra,
    };
}

const heading = spec('heading', {
    propsSchema: {
        text: { type: 'string' },
        level: { type: 'enum', values: [1, 2, 3, 4], default: 2 },
    },
    a11y: { level: 'level', name: 'text' },
});
const text = spec('text', { propsSchema: { text: { type: 'string' } } });
const section = spec('section', {
    allowedParents: ['page'],
    allowedChildren: ['*'],
    container: true,
});
const specs = { heading, text, section };

const block = { type: 'heading', props: { text: '見出し', level: 2 } };

function page(children: DocumentNode[]): DocumentNode {
    return { id: 'root', type: 'page', children };
}

describe('withFirstHeadingLevel', () => {
    it('空のページに置く見出しは 1 段目になる', () => {
        expect(
            withFirstHeadingLevel(block, heading, page([]), specs).props.level,
        ).toBe(1);
    });

    it('見出しの無いセクションだけがあっても 1 段目になる', () => {
        const root = page([
            {
                id: 's',
                type: 'section',
                children: [{ id: 't', type: 'text', props: {} }],
            },
        ]);

        expect(
            withFirstHeadingLevel(block, heading, root, specs).props.level,
        ).toBe(1);
    });

    it('もう見出しがあるなら既定の段のまま', () => {
        const root = page([
            {
                id: 's',
                type: 'section',
                children: [{ id: 'h', type: 'heading', props: { level: 1 } }],
            },
        ]);

        expect(
            withFirstHeadingLevel(block, heading, root, specs).props.level,
        ).toBe(2);
    });

    it('見出しでないブロックは変えない', () => {
        const t = { type: 'text', props: { text: 'テキスト' } };

        expect(withFirstHeadingLevel(t, text, page([]), specs)).toBe(t);
    });

    it('渡した値を書き換えない', () => {
        withFirstHeadingLevel(block, heading, page([]), specs);

        expect(block.props.level).toBe(2);
    });

    it('1 段目を選べない見出しには 1 を入れない', () => {
        const small = spec('small', {
            propsSchema: {
                level: { type: 'enum', values: [3, 4], default: 3 },
            },
            a11y: { level: 'level' },
        });
        const b = { type: 'small', props: { level: 3 } };

        expect(
            withFirstHeadingLevel(b, small, page([]), { ...specs, small }).props
                .level,
        ).toBe(3);
    });
});
