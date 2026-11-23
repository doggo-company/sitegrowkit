<script setup lang="ts">
// サイト設定（settings kind）— schema 駆動の単一フォーム
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, useTemplateRef } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import SaveErrorSummary from '@/components/SaveErrorSummary.vue';
import SchemaFieldInput from '@/components/schema/SchemaFieldInput.vue';
import { normalizePageValue } from '@/lib/page-choice';
import { Button } from '@/components/ui/button';
import type { AdminSchema } from '@/types/schema';

const props = defineProps<{
    schema: AdminSchema;
    values: Record<string, unknown>;
    /** トップページに選べるページ（R-5）。候補はデータなので schema には無い */
    frontPageChoices: { id: number; label: string; slug: string }[];
    /** 指定したページが出せなくなっているときの理由（R-5）。出せていれば null */
    frontPageNotice: string | null;
    /** ヘッダーのメニューが空で、既定の導線が出ているときの説明（B-28）。出ていなければ null */
    navigationNotice: string | null;
    /** `/contact` が従来のお問い合わせのままのときの理由（R-12 Stage 1）。フォームなら null */
    contactNotice: string | null;
    /** 管理メニューから外している機能の説明（B-39）。外していなければ null */
    menuNotice: string | null;
}>();

/**
 * 初期値。**`page` 型は、いま候補に無い id を持ち込まない**（監査 指摘 1）。
 *
 * 下書きへ戻したページの id を抱えたまま送ると、規則が断り、
 * **サイト名のような無関係な項目まで保存できなくなる**。
 * 画面は「選ばない」と見せているので、送る値もそれに合わせる。
 */
const form = useForm<Record<string, string | number | boolean | null>>(
    Object.fromEntries(
        props.schema.fields.map((f) => [
            f.key,
            f.type === 'page'
                ? (normalizePageValue(
                      props.values[f.key],
                      props.frontPageChoices,
                  ) ?? '')
                : // 値が無ければ schema の既定（「コメントを管理メニューに出す」は出す側）。
                  // 未設定を空で描くと、サイドバーには出ているのに欄は未チェック、になる
                  ((props.values[f.key] as string | number | boolean | null) ??
                  (f.default as string | number | boolean | null | undefined) ??
                  ''),
        ]),
    ),
);

function submit() {
    form.transform((data) =>
        Object.fromEntries(
            Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]),
        ),
    ).put('/admin/site', {
        preserveScroll: true,
        // 失敗したら、直す項目へ移る（エラーが画面の外に出ていると、何も起きなかったように見える）
        onError: () => nextTick(() => errorSummary.value?.focusFirst()),
        // 保存できたことも、押したボタンの横で言う（上の通知は画面の外にあることがある）
        onSuccess: () => form.defaults(),
    });
}

const errorSummary =
    useTemplateRef<InstanceType<typeof SaveErrorSummary>>('errorSummary');

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="schema.label" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">{{ schema.label }}</h1>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <p
                v-if="frontPageNotice"
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                {{
                    frontPageNotice
                }}（いまはこれまでどおりのトップページを表示しています）。<br />
                このまま保存すると、トップページの指定は外れます。もう一度そのページを公開してから選び直してください。
            </p>

            <p
                v-if="menuNotice"
                class="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground"
            >
                {{ menuNotice }}
            </p>

            <p
                v-if="contactNotice"
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                {{ contactNotice }}
            </p>

            <p
                v-if="navigationNotice"
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                {{ navigationNotice }}
            </p>

            <SchemaFieldInput
                v-for="field in schema.fields"
                :key="field.key"
                :field="field"
                v-model="form[field.key]"
                :error="form.errors[field.key]"
                :page-choices="
                    field.type === 'page' ? frontPageChoices : undefined
                "
            />
            <SaveErrorSummary
                ref="errorSummary"
                :errors="form.errors"
                :fields="schema.fields"
            />
            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">保存</Button>
                <p
                    v-if="form.wasSuccessful && !form.isDirty"
                    role="status"
                    class="text-sm text-emerald-700 dark:text-emerald-300"
                    data-testid="site-saved"
                >
                    保存しました
                </p>
            </div>
        </form>
    </div>
</template>
