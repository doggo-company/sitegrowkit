import { describe, expect, it } from 'vitest';
import {
    adminNavEntries,
    ADMIN_NAV_GROUPS,
    buildAdminNav,
} from '@/lib/admin-nav';
import type { AdminNavInput } from '@/lib/admin-nav';

/**
 * **kit が持つ 3 型**（ニュース / 実績 / 投稿）。
 *
 * 生成したサイトが最初から持つのは **`post` の 1 つだけ**で、
 * `news` / `works` は kit の同梱サンプルである（Phase 9.7 / STAGE 20B）。
 * ここで 3 型を使うのは **kit の環境で数えた数**を留めるためで、
 * 「生成直後のサイト」の数ではない — **数えた場所を偽らない**。
 */
const contentTypes = [
    { id: 'news', label: 'ニュース', href: '/admin/news' },
    { id: 'works', label: '実績', href: '/admin/works' },
    { id: 'post', label: '投稿', href: '/admin/post' },
];

/** 実測した役割ごとの capability（`/dashboard` の props から数えた） */
const ROLES: Record<string, string[]> = {
    owner: [
        'content.read',
        'content.write',
        'content.delete',
        'contact.read',
        'contact.write',
        'settings.manage',
        'content_type.manage',
        'user.manage',
        'backup.restore',
        'activity.read',
        'comment.moderate',
    ],
    administrator: [
        'content.read',
        'content.write',
        'content.delete',
        'contact.read',
        'contact.write',
        'settings.manage',
        'activity.read',
        'comment.moderate',
    ],
    editor: [
        'content.read',
        'content.write',
        'content.delete',
        'comment.moderate',
    ],
    viewer: ['content.read'],
};

/** 制作者（UX 一周 2026-09-28）。オーナーの権限に、作りの画面をメニューに出す権限が付く */
ROLES.developer = [...ROLES.owner, 'content.replace', 'site.build'];

const inputFor = (role: string): AdminNavInput => ({
    contentTypes,
    capabilities: ROLES[role],
});

const labels = (role: string): string[] =>
    buildAdminNav(inputFor(role)).map((group) => group.label);

const itemCount = (role: string): number =>
    buildAdminNav(inputFor(role)).reduce(
        (total, group) => total + group.items.length,
        0,
    );

describe('管理メニューのグループ分け', () => {
    /**
     * **中身の無いグループは、ラベルごと出ない。**
     *
     * 「受付」と書いてあるのに下に何も無い、が 2 役割で起きる。
     * 空でも描いてしまうと、**見出しだけが並ぶ**画面になる。
     */
    it('中身が無いグループはラベルごと出ない', () => {
        // 受付は contact.read が要る / 設計は content_type.manage が要る
        expect(labels('developer')).toEqual([
            'コンテンツ',
            '受付',
            'サイト構成',
            '設計',
            '管理',
        ]);

        // 店主（オーナー・管理者）の管理メニューには、作りの画面（設計）を並べない（UX 一周 2026-09-28）
        expect(labels('owner')).toEqual([
            'コンテンツ',
            '受付',
            'サイト構成',
            '管理',
        ]);
        expect(labels('administrator')).toContain('受付');
        expect(labels('administrator')).not.toContain('設計');

        expect(labels('editor')).not.toContain('受付');
        expect(labels('viewer')).not.toContain('受付');
    });

    /**
     * **作った項目は、全部どこかのグループに出る。**
     *
     * グループ分けは `GROUPS` の順で `filter` するので、
     * **`GROUPS` に無いキーを付けた項目は黙って消える**。
     * 消えたことは画面からは分からない（「その画面が無い」と区別が付かない）。
     */
    it('どのグループにも入らずに消える項目が無い', () => {
        for (const role of Object.keys(ROLES)) {
            expect(
                itemCount(role),
                `${role}: グループへ畳んだら項目が減りました（GROUPS に無い group を付けた項目があります）`,
            ).toBe(adminNavEntries(inputFor(role)).length);
        }
    });

    it('すべての項目の group は GROUPS のいずれかである', () => {
        const known = new Set(ADMIN_NAV_GROUPS.map((group) => group.key));

        for (const role of Object.keys(ROLES)) {
            for (const entry of adminNavEntries(inputFor(role))) {
                expect(known.has(entry.group), `${entry.title}`).toBe(true);
            }
        }
    });

    /**
     * 役割ごとの項目数を留める（実測値）。
     *
     * ダッシュボードは**グループの外**なので、ここには入らない。
     *
     * ▷ **owner 20 → 19 / administrator 17 → 16**（2026-08-30 / R-12 受付の一本化）。
     *   「お問い合わせ（従来）」を畳んだ 1 件分。`contact.read` を持つのはこの 2 役割だけなので、
     *   editor と viewer は変わらない。
     */
    // 公開・保守チェックをsettings.manageの2役割へ追加。編集者・閲覧者には増やさない。
    // 一括置換（content.replace）は owner だけに 1 件増える（2026-09-10）。
    // 作りの画面 7 件（分類・リダイレクト・見つからない URL・投稿タイプ・カスタムフィールド・
    // 再利用コンポーネント・一括置換）は制作者だけ（2026-09-28）。制作者はこれまでのオーナーと同じ 21 件。
    // 分類は店主も日々使うので、全役割のメニューへ戻した（どの役割も 1 件ずつ増える・同日）
    it('役割ごとの項目数が仕様と一致する', () => {
        expect(itemCount('developer')).toBe(21);
        expect(itemCount('owner')).toBe(15);
        expect(itemCount('administrator')).toBe(13);
        expect(itemCount('editor')).toBe(8);
        expect(itemCount('viewer')).toBe(7);
    });

    /**
     * **title は意図して pin していない。**
     *
     * 全部の文字列を留めると、言い回しを直すたびに試験が赤くなって脆い。
     * ▷ そのため **title を書き換える変異は生き残る**。承知のうえである。
     * ▷ これは**等価変異ではない** — 等価は「出力が変わらない」こと、
     *   こちらは「**出力は変わるが、見ないと決めた**」ことである。混ぜて記録しない。
     */
    /**
     * **案件で使う機能という 2 つ目の軸**（B-39）。
     *
     * 権限は「その人ができるか」、これは「この案件で使うか」。
     * 混ぜると「権限が在るのに出ない」を権限のせいだと読み違える。
     */
    describe('案件で使う機能', () => {
        const entriesWith = (features?: Record<string, boolean>): string[] =>
            adminNavEntries({ ...inputFor('owner'), features }).map((entry) =>
                String(entry.href),
            );

        it('渡されていなければ、これまでどおり出る', () => {
            expect(entriesWith()).toContain('/admin/comments');
            expect(entriesWith({})).toContain('/admin/comments');
        });

        it('使わないと言われたら出さない（権限は在っても）', () => {
            expect(entriesWith({ comments: false })).not.toContain(
                '/admin/comments',
            );
        });

        it('ほかの項目は消えない', () => {
            const off = entriesWith({ comments: false });

            expect(off).toContain('/admin/forms');
            expect(off).toContain('/admin/pages');
            expect(off.length).toBe(entriesWith().length - 1);
        });

        /** **知らない機能名で全部消える、をしない**（渡し忘れ・綴り違いに強くする） */
        it('知らない機能名は何も変えない', () => {
            expect(entriesWith({ whatever: false })).toStrictEqual(
                entriesWith(),
            );
        });
    });

    /**
     * **「受付」に並ぶのは 1 つだけ**（R-12 / 受付の一本化）。
     *
     * オーナー決定（2026-08-30）「**二つに分ける意味がないでしょ**」。
     * それまでは「サイトの状態」という 3 つ目の軸（`contactUsesForm`）で
     * 「お問い合わせ（従来）」を出し分けていたが、**生成直後は必ず出る**側だった
     * （フォームが 0 本なので）。**空のほうが新・現役のほうが「従来」**という、
     * 名前から中身を推測すると必ず外す形になっていた。
     *
     * いまは `ContactFormSeeder` が受付フォームを 1 本入れるので、窓口は 1 つである。
     *
     * ▷ **route は閉じていない。** 未移行の旧受信が在る案件には、
     *   フォーム一覧が件数つきで `/admin/contact` の入口を出す。
     */
    describe('受付', () => {
        const inbox = (input = inputFor('owner')): string[] =>
            adminNavEntries(input)
                .filter((entry) => entry.group === 'inbox')
                .map((entry) => String(entry.href));

        it('受付はフォームだけ', () => {
            expect(inbox()).toEqual(['/admin/forms']);
        });

        /** **どの軸を切っても、旧側が生えてこない** */
        it('どんな入力でも従来の項目は生えない', () => {
            for (const role of ['owner', 'editor'] as const) {
                expect(inbox(inputFor(role))).not.toContain('/admin/contact');
            }

            expect(
                inbox({ ...inputFor('owner'), features: { comments: false } }),
            ).not.toContain('/admin/contact');
        });

        /** **窓口ごと消さない**（権限が無いときだけ空になる） */
        it('受付を読めない役割では受付そのものが空', () => {
            const viewer = inputFor('owner');
            const without = {
                ...viewer,
                capabilities: viewer.capabilities.filter(
                    (c) => c !== 'contact.read',
                ),
            };

            expect(inbox(without)).toEqual([]);
        });
    });

    it('同じ href が 2 か所に出ない', () => {
        const hrefs = adminNavEntries(inputFor('owner')).map(
            (entry) => entry.href,
        );

        expect(new Set(hrefs).size).toBe(hrefs.length);
    });
});

describe('サイトのメニューの呼び名', () => {
    /** 料理・施術の「メニュー」と紛れないように（UX 一周 2026-09-28） */
    it('サイドバーでは「サイトのメニュー」と呼ぶ', () => {
        const titles = adminNavEntries(inputFor('owner')).map((e) => e.title);
        expect(titles).toContain('サイトのメニュー');
        expect(titles).not.toContain('メニュー');
    });
});

describe('権限の無い設定への入口', () => {
    it.each(['editor', 'viewer'])(
        '%s は設定とサイトのメニューを案内しない',
        (role) => {
            const hrefs = adminNavEntries(inputFor(role)).map(
                (item) => item.href,
            );
            expect(hrefs).not.toContain('/admin/site');
            expect(hrefs).not.toContain('/admin/menus');
        },
    );
    it.each(Object.keys(ROLES))(
        '%s はサーバーの画面表で隠した入口を案内しない',
        (role) => {
            const entries = adminNavEntries({
                ...inputFor(role),
                screenNavigation: {
                    '/admin/site': false,
                    '/admin/pages': false,
                },
            });
            expect(entries.map((item) => item.href)).not.toContain(
                '/admin/site',
            );
            expect(entries.map((item) => item.href)).not.toContain(
                '/admin/pages',
            );
        },
    );
});
