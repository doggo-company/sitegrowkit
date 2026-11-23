<script setup lang="ts">
/**
 * コメントの一覧（Phase 9.13 / STAGE 26E・SPEC §D.1）。
 *
 * ## 承認待ちを最初に出す
 *
 * 既定の絞り込みは「承認待ち」。**最初に見るべきものを最初に出す。**
 *
 * ## まとめて承認できる
 *
 * 承認待ちが 100 件あるサイトで 1 件ずつ押させるのは、
 * 「既定を承認待ちにした」設計の代償を利用者に払わせることになる。
 * **まとめての物理削除は無い** — そもそも物理削除の口が無い。
 *
 * ## 一覧に本文を積まない
 *
 * 出るのは先頭 80 文字だけ。他人の文章と個人情報を、必要のない画面へ広く展開しない。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface CommentRow {
    id: number;
    content_type: string;
    content_id: number;
    entry_title: string | null;
    status: string;
    status_label: string;
    author_name: string;
    excerpt: string;
    is_reply: boolean;
    is_staff: boolean;
    created_at: string | null;
}

const props = defineProps<{
    comments: CommentRow[];
    meta: {
        status: string;
        search: string;
        page: number;
        lastPage: number;
        total: number;
        pending: number;
    };
    statuses: { value: string; label: string }[];
    canModerate?: boolean;
}>();

const moderatable = computed(() => props.canModerate !== false);
const search = ref(props.meta.search);
const selected = ref<number[]>([]);

// 絞り込みやページを変えたら選択は捨てる（見えていないものを操作させない）
watch(
    () => props.comments,
    () => {
        selected.value = [];
    },
);

const allSelected = computed(
    () =>
        props.comments.length > 0 &&
        selected.value.length === props.comments.length,
);

function toggleAll(checked: boolean | 'indeterminate') {
    selected.value = checked === true ? props.comments.map((c) => c.id) : [];
}

function toggle(id: number, checked: boolean | 'indeterminate') {
    selected.value =
        checked === true
            ? [...selected.value, id]
            : selected.value.filter((one) => one !== id);
}

function filter(status: string) {
    router.get(
        '/admin/comments',
        { status, q: search.value },
        { preserveState: true, replace: true },
    );
}

function apply() {
    filter(props.meta.status);
}

function page(next: number) {
    router.get(
        '/admin/comments',
        { status: props.meta.status, q: props.meta.search, page: next },
        { preserveState: true },
    );
}

function setStatus(status: string) {
    if (selected.value.length === 0) {
        return;
    }

    router.post(
        '/admin/comments/status',
        { ids: selected.value, status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="コメント" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">コメント</h1>
            <p class="text-sm text-muted-foreground">
                承認待ち {{ meta.pending }} 件。承認するまで公開ページには出ません。
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div class="flex gap-1" role="group" aria-label="状態で絞り込む">
                <Button
                    v-for="choice in statuses"
                    :key="choice.value"
                    :variant="meta.status === choice.value ? 'default' : 'outline'"
                    size="sm"
                    :data-testid="`comment-filter-${choice.value}`"
                    @click="filter(choice.value)"
                >
                    {{ choice.label }}
                </Button>
            </div>

            <form class="flex items-end gap-2" @submit.prevent="apply">
                <div class="flex flex-col gap-2">
                    <Label for="q">検索</Label>
                    <Input id="q" v-model="search" data-testid="comment-search" />
                </div>
                <Button type="submit" variant="secondary">検索</Button>
            </form>
        </div>

        <div
            v-if="moderatable && selected.length > 0"
            class="flex items-center gap-2 rounded-lg border bg-muted/40 p-3"
            data-testid="comment-bulk"
        >
            <span class="text-sm">{{ selected.length }} 件を選択中</span>
            <Button size="sm" data-testid="comment-bulk-approve" @click="setStatus('approved')">
                承認する
            </Button>
            <Button size="sm" variant="outline" @click="setStatus('spam')">
                スパムにする
            </Button>
            <Button size="sm" variant="outline" @click="setStatus('trash')">
                ゴミ箱へ
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="w-10 px-4 py-2">
                            <Checkbox
                                v-if="moderatable"
                                :model-value="allSelected"
                                aria-label="すべて選択"
                                data-testid="comment-select-all"
                                @update:model-value="toggleAll"
                            />
                        </th>
                        <th class="px-4 py-2 font-medium">投稿者</th>
                        <th class="px-4 py-2 font-medium">内容</th>
                        <th class="px-4 py-2 font-medium">記事</th>
                        <th class="px-4 py-2 font-medium">状態</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="comment in comments"
                        :key="comment.id"
                        data-testid="comment-row"
                    >
                        <td class="px-4 py-3">
                            <Checkbox
                                v-if="moderatable"
                                :model-value="selected.includes(comment.id)"
                                :aria-label="`${comment.author_name} のコメントを選択`"
                                data-testid="comment-select"
                                @update:model-value="
                                    (checked) => toggle(comment.id, checked)
                                "
                            />
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ comment.author_name }}
                            <Badge v-if="comment.is_staff" variant="secondary" class="ml-1">
                                運営
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                class="underline-offset-4 hover:underline"
                                :href="`/admin/comments/${comment.id}`"
                                >{{ comment.excerpt }}</Link
                            >
                            <span
                                v-if="comment.is_reply"
                                class="ml-1 text-xs text-muted-foreground"
                                >（返信）</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="comment.entry_title">{{ comment.entry_title }}</span>
                            <span
                                v-else
                                class="text-muted-foreground"
                                data-testid="comment-missing-entry"
                                >記事が見つかりません</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    comment.status === 'approved'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ comment.status_label }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="comments.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="comment-empty"
                        >
                            コメントがありません
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
