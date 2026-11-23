import { describe, expect, it } from 'vitest';

import { followTitle, targetLabel } from '@/lib/menu-title';

const choices = {
    pages: [
        { id: 1, label: 'パンのご案内' },
        { id: 2, label: '私たちについて' },
    ],
    contentTypes: [{ id: 'post', label: 'お知らせ' }],
    routes: [
        { name: 'menu', label: 'メニュー' },
        { name: 'contact', label: 'お問い合わせ' },
    ],
    terms: [{ id: 't1', label: '季節のパン' }],
};

const item = (over: Record<string, unknown>) => ({
    link_type: 'route',
    link_route: null,
    page_id: null,
    content_type: null,
    taxonomy_term_id: null,
    ...over,
});

describe('メニューのリンク先を替えたときの表示名（UX 一周 2 周目 M7）', () => {
    it('前のリンク先の名前のままなら、新しいリンク先の名前になる', () => {
        // 2 周目: 「組み込みページ／メニュー」→「ページ／パンのご案内」で `メニュー` のままだった
        const before = targetLabel(item({ link_route: 'menu' }), choices);
        const after = targetLabel(
            item({ link_type: 'page', page_id: 1 }),
            choices,
        );

        expect(before).toBe('メニュー');
        // 13 周目 R13-4: 名前を自動で出す種類では、名前を書き込まずに空にする（自動で新しい名前が出る）
        expect(followTitle('メニュー', before, after, true)).toBe('');
    });

    it('名前を自動で出す種類では、2 回替えても新しいリンク先の名前に替わる（UX 一周 13 周目 R13-4）', () => {
        // 13 周目: 組み込み→「先生の紹介」で表示名に「先生の紹介」が書き込まれ、
        // そのあと「講師紹介」へ替えても「先生の紹介」のまま公開フッターに出た
        const first = followTitle('コースと料金', 'コースと料金', '先生の紹介', true);
        expect(first).toBe('');
        expect(followTitle(first, '先生の紹介', '講師紹介', true)).toBe('');
        // ゴミ箱へ移したページは候補に無い（前の名前が引けない）ときも同じ
        expect(followTitle(first, null, '講師紹介', true)).toBe('');
    });

    it('名前を書く種類（組み込みページ）へ替えたら、新しい名前を入れる', () => {
        expect(followTitle('パンのご案内', 'パンのご案内', 'お問い合わせ', false)).toBe(
            'お問い合わせ',
        );
    });

    it('自分で書き直した表示名は残す', () => {
        expect(
            followTitle('お店のこと', 'メニュー', 'パンのご案内', true),
        ).toBe('お店のこと');
    });

    it('空のままなら、名前を自動で出す種類では空のまま・必須の種類では新しい名前', () => {
        expect(followTitle('', 'メニュー', 'パンのご案内', true)).toBe('');
        expect(followTitle('', null, 'お問い合わせ', false)).toBe(
            'お問い合わせ',
        );
    });

    it('名前の無いリンク先（URL 直接）へ替えたら、必須なので前の文字を残す', () => {
        expect(followTitle('メニュー', 'メニュー', null, false)).toBe(
            'メニュー',
        );
    });

    it('リンク先の名前を種類ごとに引く', () => {
        expect(
            targetLabel(
                item({ link_type: 'content_index', content_type: 'post' }),
                choices,
            ),
        ).toBe('お知らせ');
        expect(
            targetLabel(
                item({ link_type: 'term', taxonomy_term_id: 't1' }),
                choices,
            ),
        ).toBe('季節のパン');
        expect(targetLabel(item({ link_type: 'custom' }), choices)).toBeNull();
    });
});
