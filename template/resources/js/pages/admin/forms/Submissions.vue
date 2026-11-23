<script setup lang="ts">
/**
 * 受信の一覧（Phase 9.12 / STAGE 25D・SPEC §D.2）。
 *
 * ## 検索が弱いことを画面に書く
 *
 * 入力値は JSON で持っている（OG3）。だから検索は**全文一致**しかできない。
 * できないことを黙っていると、利用者は「壊れている」と受け取る。
 *
 * ## 一覧に本文を積まない
 *
 * 出るのは最初の項目の頭だけ。個人情報を、必要のない画面へ広く展開しない。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface SubmissionRow {
    id: number;
    status: string;
    status_label: string;
    summary: string | null;
    notified: boolean;
    notify_error: boolean;
    created_at: string | null;
}

const props = defineProps<{
    formKey: string;
    formName: string;
    submissions: SubmissionRow[];
    meta: {
        total: number;
        page: number;
        lastPage: number;
        search: string;
        /** `failed` なら「届かなかったもの」だけを出している */
        delivery?: 'all' | 'failed';
    };
    statuses: { value: string; label: string }[];
    canWrite?: boolean;
}>();

const search = ref(props.meta.search);

/**
 * 届かなかったものだけを出しているか（WP 一周 10 周目 2026-09-04）。
 *
 * サイドバーの赤い数から来た人が、**どれか**をすぐ見られるようにする。
 */
const onlyFailed = computed(() => props.meta.delivery === 'failed');

function query(
    extra: Record<string, string | number> = {},
): Record<string, string | number> {
    return {
        q: props.meta.search,
        ...(onlyFailed.value ? { delivery: 'failed' } : {}),
        ...extra,
    };
}

function apply() {
    router.get(
        `/admin/forms/${props.formKey}/submissions`,
        query({ q: search.value }),
        { preserveState: true, replace: true },
    );
}

function toggleFailed() {
    router.get(
        `/admin/forms/${props.formKey}/submissions`,
        {
            q: props.meta.search,
            ...(onlyFailed.value ? {} : { delivery: 'failed' }),
        },
        { preserveState: true, replace: true },
    );
}

/** もう一度送る。送れたらサイドバーの数も減る */
function resend(id: number) {
    router.post(
        `/admin/forms/${props.formKey}/submissions/${id}/resend`,
        {},
        { preserveScroll: true },
    );
}

function page(next: number) {
    router.get(
        `/admin/forms/${props.formKey}/submissions`,
        query({ page: next }),
        { preserveState: true },
    );
}
</script>

<template>
    <Head :title="`${formName} の受信`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ formName }} の受信</h1>
                <p class="text-sm text-muted-foreground">
                    {{ meta.total }} 件
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" as-child data-testid="submission-export">
                    <a :href="`/admin/forms/${formKey}/submissions/export`"
                        >CSV で書き出す</a
                    >
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="`/admin/forms/${formKey}`">フォームの設定</Link>
                </Button>
            </div>
        </div>

        <form class="flex items-end gap-2" @submit.prevent="apply">
            <div class="flex flex-col gap-2">
                <Label for="q">検索</Label>
                <Input id="q" v-model="search" data-testid="submission-search" />
            </div>
            <Button type="submit" variant="secondary">検索</Button>
            <!--
                届かなかったものだけを出す（WP 一周 10 周目 2026-09-04）。
                サイドバーの赤い数から来た人が、どれかをすぐ見られるように。
            -->
            <Button
                type="button"
                :variant="onlyFailed ? 'default' : 'outline'"
                data-testid="submission-delivery-filter"
                @click="toggleFailed"
            >
                {{ onlyFailed ? 'すべて見る' : '届かなかったものだけ' }}
            </Button>
            <p class="pb-2 text-xs text-muted-foreground">
                入力内容の全文一致で探します（項目ごとの絞り込みはできません）。
            </p>
        </form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">受信日時</th>
                        <th class="px-4 py-2 font-medium">内容</th>
                        <th class="px-4 py-2 font-medium">状態</th>
                        <th class="px-4 py-2 font-medium">通知</th>
                        <th class="px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in submissions"
                        :key="row.id"
                        data-testid="submission-row"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            <Link
                                class="underline underline-offset-4"
                                :href="`/admin/forms/${formKey}/submissions/${row.id}`"
                                >{{
                                    (row.created_at ?? '')
                                        .replace('T', ' ')
                                        .slice(0, 16)
                                }}</Link
                            >
                        </td>
                        <!--
                            中身へ入る口は日付だけで、下線も hover まで出なかった。
                            「どこを押せば読める？」で止まる（UX 一周 第 19 周）。内容の列も開く口にし、下線を常に出す
                        -->
                        <td class="px-4 py-3">
                            <Link
                                class="underline underline-offset-4"
                                :href="`/admin/forms/${formKey}/submissions/${row.id}`"
                                data-testid="submission-open"
                                >{{ row.summary ?? '（内容なし）' }}</Link
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary">{{
                                row.status_label
                            }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="row.notify_error"
                                class="text-destructive"
                                data-testid="submission-notify-error"
                                >失敗</span
                            >
                            <span
                                v-else-if="row.notified"
                                class="text-muted-foreground"
                                >送信済み</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <!--
                                届かなかったことに気づいても、送り直せなければ意味が無い
                                （WP 一周 10 周目 2026-09-04）。
                            -->
                            <Button
                                v-if="row.notify_error && canWrite"
                                variant="outline"
                                size="sm"
                                data-testid="submission-resend"
                                @click="resend(row.id)"
                            >
                                もう一度送る
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="submissions.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="submission-empty"
                        >
                            {{
                                onlyFailed
                                    ? '届かなかった通知はありません'
                                    : '受信がありません'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta.lastPage > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page <= 1"
                @click="page(meta.page - 1)"
                >前へ</Button
            >
            <span class="text-sm text-muted-foreground"
                >{{ meta.page }} / {{ meta.lastPage }}</span
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page >= meta.lastPage"
                @click="page(meta.page + 1)"
                >次へ</Button
            >
        </div>
    </div>
</template>
