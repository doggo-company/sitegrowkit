import { describe, expect, it } from 'vitest';

import {
    bindingProblem,
    completeBinding,
    fieldChoices,
    sourceChoices,
} from '@/lib/binding';
import type { BindingSources, ComponentSpec } from '@/types/document';

/**
 * データ接続の判定（Phase 9.6 / P0-C5・SPEC §4.9）。
 *
 * ここで見たいのは「何をエラーと呼ぶか」である。**正当なものをエラーと呼ぶと、
 * 「エラー = 直すべきもの」の意味が壊れる**ので、呼ばない側も同じだけ見る。
 */

/** 見出しに相当する spec。accepts はサーバーが解いた結果を模したもの */
const heading: ComponentSpec = {
    type: 'heading',
    label: '見出し',
    category: 'basic',
    since: 1,
    propsSchema: {
        text: { type: 'string', label: 'テキスト' },
        level: { type: 'enum', label: '見出しレベル' },
    },
    styleSchema: [],
    bindable: ['text'],
    accepts: { text: ['text', 'textarea', 'slug'] },
    allowedChildren: [],
    allowedParents: ['section'],
    responsive: [],
    container: false,
};

/** HTML を受け取る prop を持つ spec */
const richtext: ComponentSpec = {
    ...heading,
    type: 'richtext',
    propsSchema: { html: { type: 'string', format: 'html', label: '本文' } },
    bindable: ['html'],
    accepts: { html: ['richtext'] },
};

const sources: BindingSources = {
    entry: [
        {
            type: 'news',
            label: 'お知らせ',
            fields: [
                { key: 'title', label: 'タイトル', type: 'text' },
                { key: 'body', label: '本文', type: 'richtext' },
                { key: 'images', label: '画像', type: 'images' },
            ],
        },
        /*
         * **流せる項目を 1 つも持たない参照元。**
         *
         * これが無いと「使えない参照元を出さない」規則を一度も通らず、
         * 規則を外しても試験が赤くならない（実際に一度そうなった）。
         */
        {
            type: 'gallery',
            label: 'ギャラリー',
            fields: [{ key: 'shots', label: '写真', type: 'images' }],
        },
    ],
    collection: [{ type: 'news', label: 'お知らせ' }],
    page: [
        { key: 'title', label: 'ページ名', type: 'text' },
        { key: 'slug', label: 'スラッグ', type: 'slug' },
    ],
};

describe('接続の候補', () => {
    it('流し先に流せる項目だけを出す', () => {
        const choices = fieldChoices(
            { source: 'entry', type: 'news', prop: 'text' },
            heading,
            sources,
        );

        expect(choices.map((f) => f.key)).toEqual(['title']);
    });

    it('HTML を受け取る項目には richtext だけを出す', () => {
        const choices = fieldChoices(
            { source: 'entry', type: 'news', prop: 'html' },
            richtext,
            sources,
        );

        expect(choices.map((f) => f.key)).toEqual(['body']);
    });

    it('ページの項目も受け入れで絞る', () => {
        expect(
            fieldChoices(
                { source: 'page', prop: 'text' },
                heading,
                sources,
            ).map((f) => f.key),
        ).toEqual(['title', 'slug']);

        expect(
            fieldChoices({ source: 'page', prop: 'html' }, richtext, sources),
        ).toEqual([]);
    });
});

describe('壊れている接続', () => {
    it('型が合わなければエラーにする', () => {
        expect(
            bindingProblem(
                { source: 'entry', type: 'news', field: 'body', prop: 'text' },
                heading,
                sources,
            ),
        ).toContain('流せません');
    });

    it('流し先が無ければエラーにする', () => {
        expect(
            bindingProblem(
                { source: 'entry', type: 'news', field: 'title' },
                heading,
                sources,
            ),
        ).toContain('流し先');
    });

    it('流せない項目を指していればエラーにする', () => {
        expect(
            bindingProblem(
                {
                    source: 'entry',
                    type: 'news',
                    field: 'title',
                    prop: 'level',
                },
                heading,
                sources,
            ),
        ).toContain('level');
    });

    it('参照先に無い項目を指していればエラーにする', () => {
        expect(
            bindingProblem(
                { source: 'entry', type: 'news', field: 'nope', prop: 'text' },
                heading,
                sources,
            ),
        ).toContain('見つかりません');
    });

    it('一覧に流し先が書いてあればエラーにする', () => {
        expect(
            bindingProblem(
                { source: 'collection', type: 'news', prop: 'text' },
                heading,
                sources,
            ),
        ).toContain('一覧');
    });
});

describe('壊れていない接続', () => {
    it('型が合っていればエラーにしない', () => {
        expect(
            bindingProblem(
                { source: 'entry', type: 'news', field: 'title', prop: 'text' },
                heading,
                sources,
            ),
        ).toBeNull();
    });

    it('一覧は流し先が無いのが正しい', () => {
        expect(
            bindingProblem(
                { source: 'collection', type: 'news' },
                heading,
                sources,
            ),
        ).toBeNull();
    });

    /**
     * **設定の接続はエラーではない。**
     *
     * 型が引けないだけで、validator も resolver も通る正当な接続である。
     * UI から新しく作れないことと、在るものが壊れていることは別である。
     */
    it('設定からの接続をエラー扱いしない', () => {
        expect(
            bindingProblem(
                { source: 'settings', field: 'site_name', prop: 'text' },
                heading,
                sources,
            ),
        ).toBeNull();
    });

    /** ただし HTML を受け取る項目へは流せない（§4.9 の非対称・fail closed） */
    it('設定から HTML の項目へはエラーにする', () => {
        expect(
            bindingProblem(
                { source: 'settings', field: 'site_name', prop: 'html' },
                richtext,
                sources,
            ),
        ).toContain('リッチテキスト');
    });

    it('接続そのものが無ければエラーにしない', () => {
        expect(bindingProblem(null, heading, sources)).toBeNull();
    });
});

/**
 * **画面が作る接続は、最初から送れる形でなければならない。**
 *
 * ここは e2e が先に見つけた。項目を欠いた接続を送るとサーバーが断り、
 * 画面には「押したのに何も起きない」だけが残る。判定（与えられた接続が
 * 正しいか）は見ていたが、**組み立て**を見ていなかった。
 */
describe('送れる形に仕上げる', () => {
    it('項目が欠けていれば補う', () => {
        const next = completeBinding(
            { source: 'entry', type: 'news', prop: 'text' },
            heading,
            sources,
        );

        expect(next).toEqual({
            source: 'entry',
            type: 'news',
            prop: 'text',
            field: 'title',
        });
        expect(bindingProblem(next, heading, sources)).toBeNull();
    });

    it('いま指している項目が使えるなら変えない', () => {
        const before = { source: 'page', prop: 'text', field: 'slug' };

        expect(completeBinding(before, heading, sources)).toBe(before);
    });

    it('参照元を変えて使えなくなった項目は差し替える', () => {
        const next = completeBinding(
            { source: 'page', prop: 'text', field: 'body' },
            heading,
            sources,
        );

        expect(next?.field).toBe('title');
    });

    it('流せる項目が無ければ仕上がらない', () => {
        expect(
            completeBinding(
                { source: 'page', prop: 'html' },
                richtext,
                sources,
            ),
        ).toBeNull();
    });
});

describe('参照元の候補', () => {
    it('流せる項目を持つ参照元だけを出す', () => {
        expect(
            sourceChoices('text', heading, sources).map((c) => c.value),
        ).toEqual(['page', 'news']);
    });

    /** ページには richtext の項目が無いので、HTML の流し先では出さない */
    it('流せる項目が無い参照元は出さない', () => {
        expect(
            sourceChoices('html', richtext, sources).map((c) => c.value),
        ).toEqual(['news']);
    });
});
