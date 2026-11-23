<script setup lang="ts">
/**
 * コメント 1 件（Phase 9.13 / STAGE 26E・SPEC §D.2・§D.5）。
 *
 * ## 変えられるのは状態と、返信を足すことだけ
 *
 * 届いた本文は書き換えない。**他人が書いた文章**を、こちらの都合で直さない。
 *
 * ## その記事の受付設定もここに出す
 *
 * コメントを読んでいるときこそ「この記事は閉じておこう」と思う。
 * 設定を探しに行かせない。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface CommentDetail {
    id: number;
    parent_id: number | null;
    content_type: string;
    content_id: number;
    entry_title: string | null;
    status: string;
    status_label: string;
    author_name: string;
    author_email: string | null;
    author_url: string | null;
    body: string;
    is_staff: boolean;
    has_ip: boolean;
    approved_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    comment: CommentDetail;
    entry: { enabled: boolean; isOpen: boolean | null; acceptsNew: boolean };
    statuses: { value: string; label: string }[];
    canModerate?: boolean;
    canWriteContent?: boolean;
}>();

const moderatable = computed(() => props.canModerate !== false);
const canWriteContent = computed(() => props.canWriteContent !== false);

const reply = useForm({ body: '' });

function setStatus(status: string) {
    router.post(
        '/admin/comments/status',
        { ids: [props.comment.id], status },
        { preserveScroll: true },
    );
}

function submitReply() {
    reply.post(`/admin/comments/${props.comment.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

/** `null` は「型に従う」。`false` を書いて代用しない */
function setEntryOpen(value: boolean | null) {
    router.post(
        `/admin/comments/entry/${props.comment.content_type}/${props.comment.content_id}`,
        value === null ? {} : { is_open: value },
        { preserveScroll: true },
    );
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => reply.isDirty && !reply.processing);
</script>

<template>
    <Head :title="`コメント #${comment.id}`" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">コメント #{{ comment.id }}</h1>
            <Button variant="outline" as-child>
                <Link href="/admin/comments">一覧へ戻る</Link>
            </Button>
        </div>

        <dl
            class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-3 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">投稿日時</dt>
            <dd>
                {{ (comment.created_at ?? '').replace('T', ' ').slice(0, 16) }}
            </dd>
            <dt class="text-muted-foreground">状態</dt>
            <dd>
                <Badge variant="secondary" data-testid="comment-status">{{
                    comment.status_label
                }}</Badge>
                <span
                    v-if="comment.parent_id"
                    class="ml-2 text-muted-foreground"
                    >#{{ comment.parent_id }} への返信</span
                >
            </dd>
            <dt class="text-muted-foreground">記事</dt>
            <dd>
                {{ comment.entry_title ?? '記事が見つかりません' }}
                <span class="text-muted-foreground"
                    >（{{ comment.content_type }} #{{
                        comment.content_id
                    }}）</span
                >
            </dd>
            <dt class="text-muted-foreground">投稿者</dt>
            <dd>{{ comment.author_name }}</dd>
            <dt class="text-muted-foreground">メール</dt>
            <dd data-testid="comment-email">
                <a
                    v-if="comment.author_email"
                    :href="`mailto:${comment.author_email}`"
                    class="underline"
                    >{{ comment.author_email }}</a
                >
                <span v-else class="text-muted-foreground">未入力</span>
                <span class="ml-2 text-xs text-muted-foreground"
                    >公開されません</span
                >
            </dd>
            <dt class="text-muted-foreground">ウェブサイト</dt>
            <dd>{{ comment.author_url ?? '—' }}</dd>
            <dt class="text-muted-foreground">送信元</dt>
            <dd class="text-muted-foreground">
                {{
                    comment.has_ip
                        ? '記録あり（IP そのものは保存していません）'
                        : '記録なし'
                }}
            </dd>
            <dt class="text-muted-foreground">本文</dt>
            <dd class="whitespace-pre-wrap" data-testid="comment-body">
                {{ comment.body }}
            </dd>
        </dl>

        <div v-if="moderatable" class="flex flex-wrap items-center gap-2">
            <Button
                v-for="choice in statuses"
                :key="choice.value"
                :variant="
                    comment.status === choice.value ? 'default' : 'outline'
                "
                size="sm"
                :disabled="comment.status === choice.value"
                :data-testid="`comment-set-${choice.value}`"
                @click="setStatus(choice.value)"
            >
                {{ choice.label }}
            </Button>
        </div>

        <div
            v-if="moderatable && comment.parent_id === null"
            class="rounded-xl border p-4"
        >
            <h2 class="mb-3 text-sm font-medium">返信する</h2>
            <form class="flex flex-col gap-3" @submit.prevent="submitReply">
                <Label for="reply-body" class="sr-only">返信の内容</Label>
                <textarea
                    id="reply-body"
                    v-model="reply.body"
                    rows="4"
                    class="min-h-24 rounded-md border bg-transparent px-3 py-2 text-sm"
                    data-testid="comment-reply-body"
                ></textarea>
                <p v-if="reply.errors.body" class="text-sm text-destructive">
                    {{ reply.errors.body }}
                </p>
                <div>
                    <Button
                        type="submit"
                        :disabled="reply.processing"
                        data-testid="comment-reply-send"
                    >
                        返信を投稿
                    </Button>
                    <span class="ml-2 text-xs text-muted-foreground">
                        返信はすぐに公開されます（書いた人が管理者のため）。
                    </span>
                </div>
            </form>
        </div>

        <div class="rounded-xl border p-4">
            <h2 class="mb-1 text-sm font-medium">この記事のコメント受付</h2>
            <p class="mb-3 text-xs text-muted-foreground">
                <template v-if="!entry.enabled">
                    この投稿タイプではコメント機能が無効です。投稿タイプの設定で有効にしてください。
                </template>
                <template v-else-if="entry.isOpen === null">
                    投稿タイプの設定に従っています（現在:
                    {{ entry.acceptsNew ? '受付中' : '受付停止' }}）。
                </template>
                <template v-else>
                    この記事だけ{{
                        entry.isOpen ? '受け付ける' : '受け付けない'
                    }}設定です。
                </template>
                受付を止めても、承認済みのコメントは表示され続けます。
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="
                        !canWriteContent ||
                        !entry.enabled ||
                        entry.isOpen === true
                    "
                    data-testid="entry-open"
                    @click="setEntryOpen(true)"
                >
                    この記事は受け付ける
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="
                        !canWriteContent ||
                        !entry.enabled ||
                        entry.isOpen === false
                    "
                    data-testid="entry-close"
                    @click="setEntryOpen(false)"
                >
                    この記事は受け付けない
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="
                        !canWriteContent ||
                        !entry.enabled ||
                        entry.isOpen === null
                    "
                    data-testid="entry-inherit"
                    @click="setEntryOpen(null)"
                >
                    投稿タイプの設定に従う
                </Button>
            </div>
        </div>
    </div>
</template>
