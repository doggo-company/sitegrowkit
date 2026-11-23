<script setup lang="ts">
// 問い合わせ詳細 — 内容閲覧 + 対応状態の変更のみ
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Submission {
    id: number;
    name: string;
    email: string;
    company: string | null;
    subject: string;
    message: string;
    status: string;
    status_label: string;
    notification_state: string;
    admin_notified_at: string | null;
    admin_notification_error: string | null;
    autoreply_sent_at: string | null;
    autoreply_error: string | null;
    created_at: string;
}

const props = defineProps<{
    item: Submission;
    statuses: { value: string; label: string }[];
}>();

const subjectLabels: Record<string, string> = {
    estimate: 'お見積もり',
    consultation: 'ご相談',
    other: 'その他',
};

const status = ref(props.item.status);

function updateStatus() {
    router.patch(
        `/admin/contact/${props.item.id}/status`,
        { status: status.value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`お問い合わせ #${item.id}`" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">お問い合わせ #{{ item.id }}</h1>
            <Button variant="outline" as-child>
                <Link href="/admin/contact">一覧へ戻る</Link>
            </Button>
        </div>

        <dl
            class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-3 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">受信日時</dt>
            <dd>{{ item.created_at.replace('T', ' ').slice(0, 16) }}</dd>
            <dt class="text-muted-foreground">お名前</dt>
            <dd>{{ item.name }}</dd>
            <dt class="text-muted-foreground">メール</dt>
            <dd>
                <a :href="`mailto:${item.email}`" class="underline">{{
                    item.email
                }}</a>
            </dd>
            <dt class="text-muted-foreground">会社名</dt>
            <dd>{{ item.company ?? '—' }}</dd>
            <dt class="text-muted-foreground">件名</dt>
            <dd>{{ subjectLabels[item.subject] ?? item.subject }}</dd>
            <dt class="text-muted-foreground">内容</dt>
            <dd class="whitespace-pre-wrap">{{ item.message }}</dd>
        </dl>

        <div class="rounded-xl border p-4">
            <h2 class="mb-3 text-sm font-medium">通知結果</h2>
            <dl class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">管理者通知</dt>
                <dd>
                    <Badge v-if="item.admin_notified_at" variant="secondary">
                        送信済み
                        {{
                            item.admin_notified_at
                                .replace('T', ' ')
                                .slice(0, 16)
                        }}
                    </Badge>
                    <span
                        v-else-if="item.admin_notification_error"
                        class="text-destructive"
                    >
                        失敗: {{ item.admin_notification_error }}
                    </span>
                    <span v-else class="text-muted-foreground">未送信</span>
                </dd>
                <dt class="text-muted-foreground">自動返信</dt>
                <dd>
                    <Badge v-if="item.autoreply_sent_at" variant="secondary">
                        送信済み
                        {{
                            item.autoreply_sent_at
                                .replace('T', ' ')
                                .slice(0, 16)
                        }}
                    </Badge>
                    <span
                        v-else-if="item.autoreply_error"
                        class="text-destructive"
                    >
                        失敗: {{ item.autoreply_error }}
                    </span>
                    <span v-else class="text-muted-foreground">未送信</span>
                </dd>
            </dl>
        </div>

        <div class="flex items-end gap-3">
            <div class="grid gap-2">
                <span class="text-sm font-medium">対応状態</span>
                <Select v-model="status">
                    <SelectTrigger class="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="s in statuses"
                            :key="s.value"
                            :value="s.value"
                        >
                            {{ s.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <Button @click="updateStatus">更新</Button>
        </div>
    </div>
</template>
