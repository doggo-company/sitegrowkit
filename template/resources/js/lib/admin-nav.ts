/**
 * 管理メニューの並びとグループ分け（R-7）。
 *
 * ## なぜ画面から切り出すか
 *
 * オーナーの実レビューで「メニューがカテゴライズされていない印象」と出た。
 * 実測すると **owner で 20 項目が 1 つのラベル（「管理メニュー」）の下**に並んでいた。
 *
 * 分けること自体は `SidebarGroup` が既にできる。難しいのは**分けたあと**である:
 * 権限によっては**中身が 1 つも無いグループ**ができ、放っておくと
 * **ラベルだけが残る**（「受付」と書いてあるのに下に何も無い）。
 * その判断を画面の中に書くと試験できないので、ここへ出した。
 *
 * ## 「どのグループにも入らない項目」を作らない
 *
 * 項目は `group` を必ず持ち、グループ分けは `GROUPS` の順で `filter` する。
 * つまり **`GROUPS` に無いキーを付けた項目は、黙って消える**。
 * それを試験で赤にする（`admin-nav.test.ts`）— 画面を足した人が
 * 「出ない」に気づけるのは、たいてい誰かに言われたときである。
 */
import {
    Bell,
    Blocks,
    Boxes,
    Briefcase,
    DatabaseBackup,
    FileStack,
    FileText,
    History,
    Image,
    Inbox,
    ListTree,
    MessageSquare,
    Newspaper,
    Replace,
    SearchX,
    Settings2,
    ShieldCheck,
    Signpost,
    SlidersHorizontal,
    Tags,
    Users,
} from '@lucide/vue';
import type { NavItem } from '@/types';

/** サーバーが共有する投稿タイプ（`HandleInertiaRequests::share`） */
export interface SharedContentType {
    id: string;
    label: string;
    href: string;
}

export type AdminNavGroupKey =
    'content' | 'inbox' | 'structure' | 'design' | 'admin';

export interface AdminNavGroup {
    label: string;
    items: NavItem[];
}

/**
 * グループの順序とラベル。**上ほど毎日使う**（オーナー確認済みの並び）。
 *
 * ダッシュボードはここに入れない — グループの外・最上部に単独で置く。
 */
export const ADMIN_NAV_GROUPS: { key: AdminNavGroupKey; label: string }[] = [
    { key: 'content', label: 'コンテンツ' },
    { key: 'inbox', label: '受付' },
    { key: 'structure', label: 'サイト構成' },
    { key: 'design', label: '設計' },
    { key: 'admin', label: '管理' },
];

interface AdminNavEntry extends NavItem {
    group: AdminNavGroupKey;
}

/** 既定の投稿タイプにだけ見慣れたアイコンを当て、それ以外は共通アイコンにする */
const contentTypeIcons: Record<string, NavItem['icon']> = {
    news: Newspaper,
    works: Briefcase,
};

/** 「記事を書けるようにする」1 クリック（WP 一周 2026-09-03 / 効くもの ①） */
export interface AdminNavQuickStart {
    typeId: string;
    label: string;
    url: string;
}

export interface AdminNavInput {
    contentTypes: SharedContentType[];
    /**
     * 書ける入れ物が 1 つも無いときだけサーバーが渡す。
     * WordPress から来た人は**サイドバーの「投稿」を探す**ので、その位置に薄く置く。
     */
    quickStart?: AdminNavQuickStart | null;
    /** サーバーが渡した権限。**表示の出し分けにだけ使う** */
    capabilities: string[];
    screenNavigation?: Record<string, boolean>;
    /**
     * この案件で使う機能（B-39）。**権限とは別の軸**である。
     *
     * 権限は「その人ができるか」、これは「この案件で使うか」。
     * 未設定・未渡しは **使う**（渡し忘れで項目が消えないように）。
     */
    features?: Record<string, boolean>;
    /**
     * 通知メールが届かなかった受信の数（WP 一周 10 周目 2026-09-04）。
     *
     * メールが来ないことは「問い合わせが無い」と区別がつかないので、
     * **開く理由が無い**まま溜まる。すでに目が慣れている場所に赤い数で出す。
     * 0 のときは付けない（鳴りっぱなしの印は、そのうち見られなくなる）。
     */
    undelivered?: number;
}

/**
 * 出す項目を、グループごとに畳んで返す。**中身の無いグループは返さない。**
 */
export function buildAdminNav(input: AdminNavInput): AdminNavGroup[] {
    const entries = adminNavEntries(input);

    return ADMIN_NAV_GROUPS.map((group) => ({
        label: group.label,
        items: entries.filter((entry) => entry.group === group.key),
    })).filter((group) => group.items.length > 0);
}

/**
 * 出す項目の平らな一覧（グループ分けする前）。
 *
 * **試験がここを数える。** `buildAdminNav()` の総数と食い違えば、
 * どこかの項目が「どのグループにも入らずに消えた」ということである。
 */
/**
 * 制作者だけの管理メニューに並べる画面（サイトの作り）。URL も制作者だけが開ける（`site.build`）。
 * 分類（カテゴリ）は店主も日々使うので、ここには入れない。
 */
export const BUILD_ONLY_HREFS = [
    '/admin/redirects',
    '/admin/not-found',
    '/admin/content-types',
    '/admin/field-groups',
    '/admin/reusable-components',
    '/admin/content-replace',
];

export function adminNavEntries(input: AdminNavInput): AdminNavEntry[] {
    const can = (capability: string): boolean =>
        input.capabilities.includes(capability);

    /** この案件で使う機能か。**渡されていなければ使う** */
    const uses = (feature: string): boolean =>
        input.features?.[feature] !== false;

    /** `/contact` が CMS のフォームになっているか。**設定ではなくサイトの状態** */

    const entries: AdminNavEntry[] = [
        // ── コンテンツ ──────────────────────────────
        // 投稿タイプは **実行時の登録簿から**もらう。ニュース / 実績を配列へ焼き込まない
        // （管理画面から追加した投稿タイプも、再起動なしでここへ出る — Phase 9.4）
        // 「投稿」が在るはずの位置。押すと使えるようになる（B-39 の既定は変えていない）
        ...(input.quickStart
            ? [
                  {
                      title: `${input.quickStart.label}を使えるようにする`,
                      href: input.quickStart.url,
                      icon: FileStack,
                      group: 'content',
                      method: 'post',
                      muted: true,
                  } satisfies AdminNavEntry,
              ]
            : []),
        ...input.contentTypes.map((type): AdminNavEntry => ({
            title: type.label,
            href: type.href,
            icon: contentTypeIcons[type.id] ?? FileStack,
            group: 'content',
        })),
        {
            title: 'ページ',
            href: '/admin/pages',
            icon: FileText,
            group: 'content',
        },
        {
            title: 'メディア',
            href: '/admin/media',
            icon: Image,
            group: 'content',
        },
        ...(can('comment.moderate') && uses('comments')
            ? [
                  {
                      title: 'コメント',
                      href: '/admin/comments',
                      icon: MessageSquare,
                      group: 'content' as const,
                  },
              ]
            : []),

        /*
         * ── 受付 ────────────────────────────────
         *
         * **「受付」に並ぶのは 1 つだけである**（R-12 / 受付の一本化）。
         *
         * オーナー決定（2026-08-30）「**二つに分ける意味がないでしょ**」。
         * それまでは `contactUsesForm` が false のとき「お問い合わせ（従来）」を
         * 並べていたが、**生成直後の案件は必ず false だった**（フォームが 0 本なので）。
         * 結果、買った人が最初に見る画面では
         * **空の「フォーム」と、実際に動いている「お問い合わせ（従来）」が並ぶ**という、
         * 名前から中身を推測すると必ず外す形になっていた（2026-08-30 実測）。
         *
         * いまは `ContactFormSeeder` が `/contact` の受付フォームを 1 本入れるので、
         * **窓口はフォームだけ**である。条件分岐ごと畳んだ。
         *
         * ▷ **`/admin/contact` の route は閉じていない**（B-39 裁定と同じ）。
         *   移行していない旧受信が残っている案件では、**フォーム一覧が件数つきで
         *   その入口を出す**（`Index.vue` の `legacyPending`）。メニューから消しただけで
         *   過去の受信が読めなくなる状態を作らない。**次に戻すときの足場でもある。**
         */
        ...(can('contact.read')
            ? [
                  {
                      title: 'フォーム',
                      href: '/admin/forms',
                      icon: Inbox,
                      group: 'inbox' as const,
                      // 届かなかった通知（0 のときは付けない）
                      badge: input.undelivered || undefined,
                  },
              ]
            : []),

        // ── サイト構成 ──────────────────────────────
        {
            // 料理・施術の「メニュー」と紛れないように（UX 一周 2026-09-28）
            title: 'サイトのメニュー',
            href: '/admin/menus',
            icon: ListTree,
            group: 'structure',
        },
        {
            title: '分類管理',
            href: '/admin/taxonomies',
            icon: Tags,
            group: 'structure',
        },
        ...(can('settings.manage')
            ? [
                  {
                      title: 'リダイレクト',
                      href: '/admin/redirects',
                      icon: Signpost,
                      group: 'structure' as const,
                  },
                  {
                      title: '見つからない URL',
                      href: '/admin/not-found',
                      icon: SearchX,
                      group: 'structure' as const,
                  },
              ]
            : []),

        // ── 設計 ────────────────────────────────
        ...(can('content_type.manage')
            ? [
                  {
                      title: '投稿タイプ',
                      href: '/admin/content-types',
                      icon: Boxes,
                      group: 'design' as const,
                  },
                  {
                      title: 'カスタムフィールド',
                      href: '/admin/field-groups',
                      icon: SlidersHorizontal,
                      group: 'design' as const,
                  },
              ]
            : []),
        /*
         * 再利用コンポーネント（R-7 で足した）。
         *
         * 画面と route は前から在るのに、**一覧へ入る導線がどこにも無かった**
         * （個別の編集へはページ編集画面から行ける）。
         *
         * **無条件で出す。** route には capability の門が無く、`component.reusable.list` は
         * 読みとして誰でも通る。ここだけ画面で隠すと、**画面の門が route の門を偽る**ことになる。
         * 厳しくしたいなら policy を締めるのが先である（`docs/BACKLOG.md` B-30）。
         */
        {
            title: '再利用コンポーネント',
            href: '/admin/reusable-components',
            icon: Blocks,
            group: 'design',
        },

        // ── 管理 ────────────────────────────────
        {
            title: 'サイト設定',
            href: '/admin/site',
            icon: Settings2,
            group: 'admin',
        },
        ...(can('settings.manage')
            ? [
                  {
                      title: '公開・保守チェック',
                      href: '/admin/readiness',
                      icon: ShieldCheck,
                      group: 'admin' as const,
                  },
                  {
                      title: 'バックアップ',
                      href: '/admin/backups',
                      icon: DatabaseBackup,
                      group: 'admin' as const,
                  },
              ]
            : []),
        {
            title: '通知設定',
            href: '/admin/notifications',
            icon: Bell,
            group: 'admin',
        },
        /*
         * 本文の一括置換（P1 ⑧ / GUI 化・2026-09-10）。**制作者だけ**が持つ `content.replace`（2026-09-28 まではオーナーも）。
         * 出し分けは補助で、経路は `ReplaceContent` の policy が塞いでいる。
         */
        ...(can('content.replace')
            ? [
                  {
                      title: '一括置換',
                      href: '/admin/content-replace',
                      icon: Replace,
                      group: 'admin' as const,
                  },
              ]
            : []),
        ...(can('user.manage')
            ? [
                  {
                      title: '利用者',
                      href: '/admin/users',
                      icon: Users,
                      group: 'admin' as const,
                  },
              ]
            : []),
        ...(can('activity.read')
            ? [
                  {
                      title: '操作履歴',
                      href: '/admin/activity',
                      icon: History,
                      group: 'admin' as const,
                  },
              ]
            : []),
    ];

    /*
     * 作りの画面は、制作者（`site.build`）の管理メニューにだけ並べる（UX 一周 2026-09-28）。
     * 店主が開いたとき、投稿タイプやカスタムフィールドが並んでいると、何を触ればよいか迷う。
     * URL を直接開いても入れないよう、route にも `site.build` の関所がある（`RequireSiteBuild`）。
     * 一括置換だけは関所ではなく `content.replace` を制作者だけに持たせて閉じている。
     */
    return entries.filter(
        (entry) =>
            input.screenNavigation?.[String(entry.href)] !== false &&
            (can('site.build') ||
                !BUILD_ONLY_HREFS.includes(String(entry.href))) &&
            ((String(entry.href) !== '/admin/site' &&
                String(entry.href) !== '/admin/menus') ||
                can('settings.manage')) &&
            (String(entry.href) !== '/admin/backups' || can('backup.restore')),
    );
}
