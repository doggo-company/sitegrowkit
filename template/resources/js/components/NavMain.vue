<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { AdminNavGroup } from '@/lib/admin-nav';
import type { NavItem } from '@/types';

/**
 * 管理メニュー（R-7）。
 *
 * `top` は**グループの外**に置く項目（いまはダッシュボードだけ）。
 * ラベルを持たないので、`SidebarGroupLabel` ごと出さない。
 *
 * **中身の無いグループは `buildAdminNav()` の時点で落ちている。**
 * ここで落とすと、落とす判断が画面の中に入って試験できなくなる。
 */
defineProps<{
    top: NavItem[];
    groups: AdminNavGroup[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-if="top.length > 0" class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem v-for="item in top" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.method ? false : isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    :class="item.muted ? 'text-muted-foreground' : undefined"
                >
                    <!--
                        `method` を持つ項目だけ POST にする（WP 一周 2026-09-03）。
                        **GET で書き込ませない** — 持たない項目は今までどおりの link である。
                    -->
                    <Link
                        :href="item.href"
                        :method="item.method"
                        :as="item.method ? 'button' : undefined"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>

    <SidebarGroup
        v-for="group in groups"
        :key="group.label"
        class="px-2 py-0"
        :data-testid="`nav-group-${group.label}`"
    >
        <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.method ? false : isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    :class="item.muted ? 'text-muted-foreground' : undefined"
                >
                    <!--
                        `method` を持つ項目だけ POST にする（WP 一周 2026-09-03）。
                        **GET で書き込ませない** — 持たない項目は今までどおりの link である。
                    -->
                    <Link
                        :href="item.href"
                        :method="item.method"
                        :as="item.method ? 'button' : undefined"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <!--
                            届かなかったものの数（WP 一周 10 周目 2026-09-04）。
                            **0 のときは出さない** — 鳴りっぱなしの印は、そのうち見られなくなる。
                        -->
                        <span
                            v-if="item.badge"
                            class="ml-auto rounded-full bg-destructive px-1.5 py-0.5 text-xs font-medium text-white"
                            data-testid="nav-badge"
                            :aria-label="`${item.badge} 件、確認が必要です`"
                            >{{ item.badge }}</span
                        >
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
