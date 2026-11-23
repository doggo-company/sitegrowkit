<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { buildAdminNav } from '@/lib/admin-nav';
import type { AdminNavQuickStart, SharedContentType } from '@/lib/admin-nav';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

/*
 * 並びとグループ分けは `lib/admin-nav` が持つ（R-7）。
 *
 * ここに書くと**中身の無いグループを落とす判断**が画面の中に入り、試験できない。
 * 実測では `contact.read` の無い役割で「受付」が、`content_type.manage` の無い役割で
 * 「設計」が空になる — **ラベルだけが残る**のを止める必要がある。
 */
const page = usePage();

const contentTypes = computed<SharedContentType[]>(
    () =>
        (page.props.nav as { contentTypes?: SharedContentType[] } | undefined)
            ?.contentTypes ?? [],
);

/**
 * サーバーが渡した権限。**表示の出し分けにだけ使う。**
 * 実際の可否はサーバー側の `RoleOperationPolicy` が決めるので、
 * ここを書き換えても操作できるようにはならない。
 *
 * ▷ その 2 つが食い違っている箇所がある（`docs/BACKLOG.md` B-30）。
 *   直すのは「出どころを 1 つにする」本修理で、R-7 では並べ替えだけを行う。
 */
/**
 * この案件で使う機能（B-39）。**権限とは別の軸**で、渡されていなければ使う扱い。
 */
const features = computed<Record<string, boolean>>(
    () =>
        (page.props.nav as { features?: Record<string, boolean> } | undefined)
            ?.features ?? {},
);

const capabilities = computed<string[]>(
    () =>
        (page.props.auth as { capabilities?: string[] } | undefined)
            ?.capabilities ?? [],
);

/** グループの外・最上部に単独で置く（オーナー確認済みの構造） */
const topNavItems = computed<NavItem[]>(() => [
    {
        title: 'ダッシュボード',
        href: dashboard(),
        icon: LayoutGrid,
    },
]);

/** 「投稿」が在るはずの位置に置く 1 クリック（WP 一周 2026-09-03） */
const quickStart = computed<AdminNavQuickStart | null>(
    () =>
        (
            page.props.nav as
                { quickStart?: AdminNavQuickStart | null } | undefined
        )?.quickStart ?? null,
);

/** 通知メールが届かなかった受信の数（WP 一周 10 周目 2026-09-04） */
const undelivered = computed<number>(() => {
    const value = (page.props.nav as { undelivered?: unknown } | undefined)
        ?.undelivered;

    return typeof value === 'number' && value > 0 ? value : 0;
});

/** 最後の自動バックアップが失敗した・止まっている（1 か 0） */
const backupAlert = computed<number>(() => {
    const value = (page.props.nav as { backupAlert?: unknown } | undefined)
        ?.backupAlert;

    return typeof value === 'number' && value > 0 ? 1 : 0;
});

const navGroups = computed(() =>
    buildAdminNav({
        contentTypes: contentTypes.value,
        quickStart: quickStart.value,
        capabilities: capabilities.value,
        screenNavigation: (
            page.props.adminScreen as
                { navigation?: Record<string, boolean> } | undefined
        )?.navigation,
        features: features.value,
        undelivered: undelivered.value,
        backupAlert: backupAlert.value,
    }),
);

// 利用者向け admin に upstream starter（Laravel/Vue starter kit）へのリンクは置かない。
// 有効な customer-facing リンク先ができたら NavFooter ごと復活させる。
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :top="topNavItems" :groups="navGroups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
