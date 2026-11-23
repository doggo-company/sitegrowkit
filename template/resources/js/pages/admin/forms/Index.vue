<script setup lang="ts">
/**
 * フォームの一覧（Phase 9.12 / STAGE 25D・SPEC §D.1）。
 *
 * ## 「なぜ公開できないか」をその場に出す
 *
 * 項目の束が下書きのまま・割り当てが違う・項目が 0 — どれも
 * 「公開ボタンを押したら断られる」で気づかせない。一覧の時点で理由を出す。
 *
 * ## 受信が残っているフォームには削除の導線を出さない
 *
 * 押したら断る作りにしない。先に受信を片付けるか「受付終了」にする、と書く。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';

interface FormRow {
    id: number;
    key: string;
    name: string;
    description: string | null;
    status: string;
    status_label: string;
    field_problem: string | null;
    submissions: number;
}

const props = defineProps<{
    forms: FormRow[];
    canManage?: boolean;
    /**
     * まだフォームへ移していない旧お問い合わせの件数（R-12 / 受付の一本化）。
     *
     * 「受付」のメニューはフォーム 1 つになった。**消していないものを読めなくしない**ため、
     * 残っている案件にだけ入口を出す。移せば 0 になり、この表示も消える。
     */
    legacyPending?: number;
}>();

const manageable = props.canManage !== false;
const pending = ref<FormRow | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const form = pending.value;

        if (!form) {
            return;
        }

        router.post(
            `/admin/forms/${form.key}/delete`,
            { ...(token ? { confirmation_token: token } : {}) },
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function remove(form: FormRow) {
    pending.value = form;
    request();
}
</script>

<template>
    <Head title="フォーム" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">フォーム</h1>
                <p class="text-sm text-muted-foreground">
                    公開サイトに置く入力フォームです。入力項目はカスタムフィールドの束で編集します。
                </p>
            </div>
            <Button v-if="manageable" as-child data-testid="form-create">
                <Link href="/admin/forms/new">新しいフォーム</Link>
            </Button>
        </div>

        <div
            v-if="(props.legacyPending ?? 0) > 0"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
            data-testid="legacy-pending"
        >
            <!-- 画面からできることだけを言う（UX 一周 6 周目 R6-5: ターミナルのコマンドを打てと出ていた） -->
            <p class="font-medium">
                フォームに切り替える前の /contact で受け取ったお問い合わせが
                {{ props.legacyPending }} 件あります。
            </p>
            <p class="mt-1 text-muted-foreground">
                中身はそのまま残っています。
                <a class="underline underline-offset-4" href="/admin/contact"
                    >これまでのお問い合わせを見る</a
                >
                から読めます。
            </p>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">名前</th>
                        <th class="px-4 py-2 font-medium">公開 URL</th>
                        <th class="px-4 py-2 font-medium">状態</th>
                        <th class="px-4 py-2 font-medium">受信</th>
                        <th class="px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="form in forms"
                        :key="form.id"
                        data-testid="form-row"
                    >
                        <td class="px-4 py-3">
                            <Link
                                class="font-medium underline-offset-4 hover:underline"
                                :href="`/admin/forms/${form.key}`"
                                >{{ form.name }}</Link
                            >
                            <p
                                v-if="form.field_problem"
                                class="mt-1 text-xs text-destructive"
                                data-testid="form-problem"
                            >
                                {{ form.field_problem }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            /forms/{{ form.key }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    form.status === 'published'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ form.status_label }}</Badge
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                class="underline-offset-4 hover:underline"
                                :href="`/admin/forms/${form.key}/submissions`"
                                data-testid="form-submissions-link"
                                >{{ form.submissions }} 件</Link
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="manageable && form.submissions === 0"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                data-testid="form-delete"
                                @click="remove(form)"
                            >
                                削除
                            </Button>
                            <span
                                v-else-if="manageable"
                                class="text-xs text-muted-foreground"
                                data-testid="form-undeletable"
                            >
                                受信があるため削除できません
                            </span>
                        </td>
                    </tr>
                    <tr v-if="forms.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="form-empty"
                        >
                            フォームがありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmationDialog
        :confirmation="confirmation"
        @confirm="accept"
        @cancel="dismiss"
    />
</template>
