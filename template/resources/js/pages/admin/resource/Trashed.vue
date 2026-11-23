<script setup lang="ts">
/**
 * ゴミ箱にある投稿の画面（Phase 9.8 / STAGE 21E・SPEC §E.1）。
 *
 * **編集フォームを出さない。** URL を直接叩かれても入力欄・保存・公開は見せない。
 * 見せてしまうと「編集できたのに保存で弾かれる」ことになり、何が起きているのか
 * 分からなくなる。代わりに、いまどういう状態か・どう戻すか・履歴はどこかを出す。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import RevisionHistoryDialog from '@/components/RevisionHistoryDialog.vue';
import type { AdminSchema, ContentItem } from '@/types/schema';

const props = defineProps<{
    schema: AdminSchema;
    item: ContentItem;
    routeBase: string;
    canWrite?: boolean;
}>();

const showHistory = ref(false);

/** 版（楽観ロック）。読んだ値をそのまま添える */
function lockVersionOf(item: ContentItem): number | null {
    const value = item.lock_version;

    return typeof value === 'number' ? value : null;
}

const title = String(props.item[props.schema.titleKey ?? 'title'] ?? `ID ${props.item.id}`);

/** 戻すのに確認は要らない（戻すこと自体は何も失わない） */
function restore() {
    router.post(`/admin/${props.routeBase}/${props.item.id}/restore`, {
        lock_version: lockVersionOf(props.item),
    });
}
</script>

<template>
    <Head :title="`${title}（ゴミ箱）`" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">{{ title }}</h1>
            <Button variant="ghost" as-child>
                <Link :href="`/admin/${routeBase}?state=trashed`">ゴミ箱へ戻る</Link>
            </Button>
        </div>

        <div
            class="rounded-xl border p-4 text-sm"
            data-testid="trashed-notice"
            role="status"
        >
            <p class="font-medium">この{{ schema.label }}はゴミ箱にあります。</p>
            <p class="mt-1 text-muted-foreground">
                公開ページには表示されません。内容は残っているので、元に戻せます。
                元に戻すまで編集はできません。
            </p>
        </div>

        <div class="flex gap-2">
            <Button
                v-if="canWrite !== false"
                data-testid="trashed-restore"
                @click="restore"
            >
                元に戻す
            </Button>
            <Button
                variant="secondary"
                data-testid="trashed-history"
                @click="showHistory = true"
            >
                履歴を見る
            </Button>
        </div>

        <p
            v-if="canWrite === false"
            class="text-sm text-muted-foreground"
            data-testid="trashed-readonly"
        >
            この投稿タイプは無効になっているため、閲覧のみ可能です。
        </p>

        <RevisionHistoryDialog
            :item="showHistory ? item : null"
            :route-base="routeBase"
            :label="schema.label"
            :can-write="canWrite"
            @close="showHistory = false"
        />
    </div>
</template>
