<script setup lang="ts">
/**
 * 受信 1 件（Phase 9.12 / STAGE 25D・SPEC §D.2）。
 *
 * ## 見出しは届いたときのもの
 *
 * 項目を消したり改名したりしても、**そのとき何と書かれていたか**で読める（OG4）。
 *
 * ## 変えられるのは状態だけ
 *
 * 届いた本文は書き換えない。顧客が何と書いて送ったかは、あとから直す記録ではない。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';

interface Submission {
    id: number;
    status: string;
    status_label: string;
    fields: { key: string; label: string; value: unknown }[];
    admin_notified_at: string | null;
    admin_notification_error: string | null;
    autoreply_sent_at: string | null;
    autoreply_error: string | null;
    has_ip: boolean;
    created_at: string | null;
}

const props = defineProps<{
    formKey: string;
    formName: string;
    submission: Submission;
    statuses: { value: string; label: string }[];
    canWrite?: boolean;
}>();

const writable = props.canWrite !== false;
const status = ref(props.submission.status);

/*
 * 受信の削除（R-12 / 2026-08-30）。
 *
 * 🔴 **個人情報なので消せることが要る。**「消してほしい」と言われたときに
 * 応じる手段が管理画面に無かった（route ごと無く、DB を直接触るしかなかった）。
 *
 * **ゴミ箱には入れない**ので確認を挟む。何が消えるかは Application Service が書く
 * （文言をここへ写さない）。
 */
const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        router.post(
            `/admin/forms/${props.formKey}/submissions/${props.submission.id}/delete`,
            { ...(token ? { confirmation_token: token } : {}) },
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function text(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '（未入力）';
    }

    if (typeof value === 'boolean') {
        return value ? 'はい' : 'いいえ';
    }

    return Array.isArray(value) ? value.join('、') : String(value);
}

function updateStatus() {
    router.patch(
        `/admin/forms/${props.formKey}/submissions/${props.submission.id}`,
        { status: status.value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${formName} #${submission.id}`" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">
                {{ formName }} #{{ submission.id }}
            </h1>
            <Button variant="outline" as-child>
                <Link :href="`/admin/forms/${formKey}/submissions`"
                    >一覧へ戻る</Link
                >
            </Button>
        </div>

        <dl
            class="grid grid-cols-[10rem_1fr] gap-x-4 gap-y-3 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">受信日時</dt>
            <dd>
                {{ (submission.created_at ?? '').replace('T', ' ').slice(0, 16) }}
            </dd>
            <template v-for="field in submission.fields" :key="field.key">
                <dt class="text-muted-foreground">{{ field.label }}</dt>
                <dd class="whitespace-pre-wrap" data-testid="submission-value">
                    {{ text(field.value) }}
                </dd>
            </template>
        </dl>

        <div class="rounded-xl border p-4">
            <h2 class="mb-3 text-sm font-medium">通知結果</h2>
            <dl class="grid grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">管理者通知</dt>
                <dd>
                    <Badge v-if="submission.admin_notified_at" variant="secondary"
                        >送信済み</Badge
                    >
                    <span
                        v-else-if="submission.admin_notification_error"
                        class="text-destructive"
                        data-testid="submission-notify-error"
                    >
                        失敗: {{ submission.admin_notification_error }}
                    </span>
                    <span v-else class="text-muted-foreground">未送信</span>
                </dd>
                <dt class="text-muted-foreground">自動返信</dt>
                <dd>
                    <Badge v-if="submission.autoreply_sent_at" variant="secondary"
                        >送信済み</Badge
                    >
                    <span
                        v-else-if="submission.autoreply_error"
                        class="text-destructive"
                    >
                        失敗: {{ submission.autoreply_error }}
                    </span>
                    <span v-else class="text-muted-foreground">送っていません</span>
                </dd>
                <dt class="text-muted-foreground">送信元</dt>
                <dd class="text-muted-foreground">
                    {{
                        submission.has_ip
                            ? '記録あり（IP そのものは保存していません）'
                            : '記録なし'
                    }}
                </dd>
            </dl>
        </div>

        <div class="flex items-end gap-2">
            <div class="flex flex-col gap-2">
                <Label for="status">対応状態</Label>
                <Select
                    v-model="status"
                    :disabled="!writable"
                >
                    <SelectTrigger id="status" data-testid="submission-status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="choice in statuses"
                            :key="choice.value"
                            :value="choice.value"
                        >
                            {{ choice.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <Button
                :disabled="!writable || status === submission.status"
                data-testid="submission-status-save"
                @click="updateStatus"
            >
                変更する
            </Button>
        </div>

        <!--
            消すのは「対応する」とは別の行為なので、離して置く。
            並べると、状態を変えるつもりで押す。
        -->
        <div v-if="writable" class="mt-8 border-t pt-6">
            <p class="text-sm font-medium">この受信を削除する</p>
            <p class="mt-1 text-sm text-muted-foreground">
                お名前・メールアドレス・入力内容がすべて消えます。ゴミ箱には入りません。
                残しておきたい場合は、先に一覧から CSV で書き出してください。
            </p>
            <Button
                variant="outline"
                class="mt-3 text-destructive"
                data-testid="submission-delete"
                @click="request()"
            >
                削除する
            </Button>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>
