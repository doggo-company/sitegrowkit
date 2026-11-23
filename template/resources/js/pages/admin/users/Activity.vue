<script setup lang="ts">
/**
 * 利用者ごとの操作履歴 — PHASE WP-BASELINE / STAGE #18A。
 *
 * 全体履歴と同じ表・同じ絞り込みを使い、実行者だけを固定する。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import ActivityFilters from '@/components/ActivityFilters.vue';
import ActivityTable from '@/components/ActivityTable.vue';
import { Button } from '@/components/ui/button';
import type {
    ActivityEntry,
    ActivityFilterOptions,
    ActivityFilterState,
    AdminUser,
    PageMeta,
} from '@/types/user';

const props = defineProps<{
    user: AdminUser;
    entries: ActivityEntry[];
    meta: PageMeta;
    filter: ActivityFilterState;
    options: ActivityFilterOptions;
}>();

function apply(values: Record<string, string>) {
    router.get(`/admin/users/${props.user.id}/activity`, values, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get(
        `/admin/users/${props.user.id}/activity`,
        { page: String(page) },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${user.name} の操作履歴`" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">
                    {{ user.name }} の操作履歴
                </h1>
                <p class="text-sm text-muted-foreground">
                    この利用者が行った操作だけを表示しています。
                </p>
            </div>
            <Button variant="outline" as-child>
                <Link :href="`/admin/users/${user.id}`"
                    >利用者の詳細へ戻る</Link
                >
            </Button>
        </div>

        <ActivityFilters :filter="filter" :options="options" @apply="apply" />

        <ActivityTable
            :entries="entries"
            :show-network="meta.includesNetworkContext"
        />

        <nav v-if="meta.lastPage > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page <= 1"
                @click="goToPage(meta.page - 1)"
            >
                前へ
            </Button>
            <span class="text-sm text-muted-foreground">
                {{ meta.page }} / {{ meta.lastPage }}（全 {{ meta.total }} 件）
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page >= meta.lastPage"
                @click="goToPage(meta.page + 1)"
            >
                次へ
            </Button>
        </nav>
    </div>
</template>
