import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /**
     * 押すと状態が変わる項目だけ `post` にする（WP 一周 2026-09-03）。
     * 既定は link のまま。**GET で書き込ませない**ため、method を持たない項目は今までどおり。
     */
    method?: 'post';
    /** まだ使えるようになっていない案内。薄く出して、並んでいる本物と見分けられるようにする */
    muted?: boolean;
    /**
     * 目を引かせたい数（WP 一周 10 周目 2026-09-04）。
     * **0 のときは出さない** — 鳴りっぱなしの印は、そのうち見られなくなる。
     */
    badge?: number;
};
