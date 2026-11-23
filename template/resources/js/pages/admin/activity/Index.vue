<script setup lang="ts">
/**
 * 全体の操作履歴 — PHASE WP-BASELINE / STAGE #18A・SPEC §1C.2。
 *
 * CSV は **今の絞り込みをそのまま**書き出す（画面と結果がずれない）。
 */
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityFilters from '@/components/ActivityFilters.vue';
import ActivityTable from '@/components/ActivityTable.vue';
import { Button } from '@/components/ui/button';
import type {
    ActivityEntry,
    ActivityFilterOptions,
    ActivityFilterState,
    PageMeta,
} from '@/types/user';

const props = defineProps<{
    entries: ActivityEntry[];
    meta: PageMeta;
    filter: ActivityFilterState;
    options: ActivityFilterOptions;
}>();

function apply(values: Record<string, string>) {
    router.get('/admin/activity', values, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get(
        '/admin/activity',
        { ...currentQuery.value, page: String(page) },
        { preserveState: true, preserveScroll: true },
    );
}

const currentQuery = computed<Record<string, string>>(() => {
    const entries = Object.entries(props.filter)
        .filter(([, value]) => value !== null && value !== '')
        .map(([key, value]) => [key, String(value)]);

    return Object.fromEntries(entries);
});

const exportUrl = computed(() => {
    const params = new URLSearchParams(currentQuery.value);

    return `/admin/activity/export?${params.toString()}`;
});
</script>

<template>
    <Head title="操作履歴" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">操作履歴</h1>
                <p class="text-sm text-muted-foreground">
                    誰が・いつ・何をしたかの記録です。本文やパスワードなどの内容は記録していません。
                </p>
            </div>
            <Button variant="outline" as-child>
                <a :href="exportUrl" data-testid="activity-export"
                    >CSV で書き出す</a
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
