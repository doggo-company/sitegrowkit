<script setup lang="ts">
/**
 * ページの一覧（Phase 9.3B / #19・SPEC §V.2-(1)）。
 *
 * 通常の一覧とゴミ箱は **別の一覧**として切り替える（混ぜて見せない）。
 * 状態は色だけでなく必ず文字で示す。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { PageListItem, PagePublishState } from '@/types/page';
import { useAdminCapability } from '@/composables/useAdminCapability';

/**
 * 同梱の見本ページ（UX 一周 2026-09-27）。
 *
 * 公開前チェックが「見本のまま公開されています。消してください」と言う
 * `/` `/about` `/service` `/contact` はページではないので、**この一覧に出ず、
 * 直す場所が分からなかった。**直し方（サーバーが決める）ごと並べる。
 */
interface SampleAction {
    kind: 'link' | 'view' | 'hide' | 'show';
    label: string;
    href?: string;
    route?: string;
}

interface SamplePage {
    key: string;
    path: string;
    title: string;
    state: 'sample' | 'hidden';
    stateLabel: string;
    note: string;
    actions: SampleAction[];
}

const canWrite = useAdminCapability('content.write');

const props = defineProps<{
    items: PageListItem[];
    /** ゴミ箱の一覧を見ているか */
    trashed: boolean;
    samples?: SamplePage[];
    /** 非公開にする／戻すを押せるか（サイト設定を触れる人だけ） */
    canManageSamples?: boolean;
}>();

/** 送っている最中か（UX 一周 7 周目 R7-2: ダブルクリックで 2 回送り、500 の画面が出た） */
const sendingSample = ref(false);

function toggleSample(action: SampleAction) {
    if (!action.route || sendingSample.value) {
        return;
    }

    sendingSample.value = true;
    router.post(
        `/admin/sample-pages/${action.route}/${action.kind}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sendingSample.value = false;
            },
        },
    );
}

type BadgeVariant = 'default' | 'outline' | 'destructive' | 'secondary';

// 予約中は「公開中」と同じ色にしない（status は published でも、まだ出ていない）
const statusVariants: Record<PagePublishState, BadgeVariant> = {
    published: 'default',
    scheduled: 'outline',
    draft: 'secondary',
    trashed: 'outline',
};

const statusVariant = (state: PagePublishState): BadgeVariant =>
    statusVariants[state] ?? 'outline';

function show(trashed: boolean) {
    router.get('/admin/pages', trashed ? { trashed: 1 } : {}, {
        preserveState: true,
    });
}

function restore(item: PageListItem) {
    router.post(
        `/admin/pages/${item.id}/restore`,
        {},
        { preserveScroll: true },
    );
}

const formatDate = (value: string | null): string =>
    value === null ? '—' : value.replace('T', ' ').slice(0, 16);

const heading = () => (props.trashed ? 'ゴミ箱のページ' : 'ページ');
</script>

<template>
    <Head :title="heading()" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">{{ heading() }}</h1>
            <Button v-if="!trashed && canWrite" as-child>
                <Link href="/admin/pages/create" prefetch>新規作成</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                :variant="trashed ? 'outline' : 'default'"
                size="sm"
                data-testid="pages-filter-active"
                @click="show(false)"
            >
                公開中・下書き
            </Button>
            <Button
                :variant="trashed ? 'default' : 'outline'"
                size="sm"
                data-testid="pages-filter-trashed"
                @click="show(true)"
            >
                ゴミ箱
            </Button>
        </div>

        <section
            v-if="!trashed && (samples ?? []).length > 0"
            id="sample-pages"
            class="scroll-mt-20 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4"
            aria-labelledby="sample-pages-heading"
            data-testid="sample-pages"
        >
            <h2 id="sample-pages-heading" class="font-semibold">
                同梱の見本ページ（{{ (samples ?? []).length }} 件）
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                SiteGrowKit
                に最初から入っている見本です。ページとは別の作りなので、
                ここから差し替えるか非公開にしてください。公開前チェックもここを指します。
            </p>
            <ul class="mt-3 divide-y rounded-lg border bg-background">
                <li
                    v-for="sample in samples"
                    :id="`sample-${sample.key}`"
                    :key="sample.key"
                    class="flex scroll-mt-20 flex-col gap-2 px-4 py-3"
                    :data-testid="`sample-page-${sample.key}`"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ sample.title }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{
                            sample.path
                        }}</span>
                        <Badge
                            :variant="
                                sample.state === 'hidden'
                                    ? 'outline'
                                    : 'secondary'
                            "
                        >
                            {{ sample.stateLabel }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ sample.note }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <template
                            v-for="action in sample.actions"
                            :key="action.label"
                        >
                            <Button
                                v-if="
                                    (action.kind === 'hide' ||
                                        action.kind === 'show') &&
                                    canManageSamples
                                "
                                size="sm"
                                :variant="
                                    action.kind === 'hide'
                                        ? 'destructive'
                                        : 'default'
                                "
                                :disabled="sendingSample"
                                :data-testid="`sample-${action.kind}-${sample.key}`"
                                @click="toggleSample(action)"
                            >
                                {{ action.label }}
                            </Button>
                            <Button
                                v-else-if="action.kind === 'link'"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="action.href ?? '#'">{{
                                    action.label
                                }}</Link>
                            </Button>
                            <Button
                                v-else-if="action.kind === 'view'"
                                size="sm"
                                variant="ghost"
                                as-child
                            >
                                <a
                                    :href="action.href"
                                    target="_blank"
                                    rel="noopener"
                                    >{{ action.label }}</a
                                >
                            </Button>
                        </template>
                    </div>
                    <p
                        v-if="
                            !canManageSamples &&
                            sample.actions.some(
                                (a) => a.kind === 'hide' || a.kind === 'show',
                            )
                        "
                        class="text-xs text-muted-foreground"
                    >
                        非公開にする操作は、サイト設定を変更できる人だけが行えます。
                    </p>
                </li>
            </ul>
        </section>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            タイトル
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            URL
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            管理
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            最終更新
                        </th>
                        <th class="w-28 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                        :data-testid="`page-row-${item.slug}`"
                    >
                        <td class="px-4 py-3">
                            <Link
                                v-if="!trashed"
                                :href="`/admin/pages/${item.id}/edit`"
                                class="font-medium underline-offset-4 hover:underline"
                                data-testid="page-title-link"
                            >
                                {{ item.title }}
                            </Link>
                            <span v-else class="font-medium">{{
                                item.title
                            }}</span>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs break-all text-muted-foreground"
                        >
                            /{{ item.slug }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(item.publishState)">
                                {{ item.statusLabel }}
                            </Badge>
                            <!-- 予約は日時が本体。バッジだけでは「いつ」が分からない -->
                            <span
                                v-if="item.publishState === 'scheduled'"
                                class="mt-1 block text-xs whitespace-nowrap text-muted-foreground"
                                :data-testid="`page-scheduled-at-${item.slug}`"
                            >
                                {{ item.publishedAtLocal }} に公開
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs text-muted-foreground">
                                {{
                                    item.managedBy === 'document'
                                        ? '本文はブロックで管理'
                                        : '本文はコードで管理'
                                }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDate(item.updatedAt) }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                v-if="trashed && canWrite"
                                variant="ghost"
                                size="sm"
                                :data-testid="`page-restore-${item.slug}`"
                                @click="restore(item)"
                            >
                                復元する
                            </Button>
                            <Button v-else variant="ghost" size="sm" as-child>
                                <Link :href="`/admin/pages/${item.id}/edit`"
                                    >編集</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            <template v-if="trashed">
                                ゴミ箱は空です。
                            </template>
                            <template v-else>
                                ページがまだありません。「新規作成」からページを追加してください。
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
