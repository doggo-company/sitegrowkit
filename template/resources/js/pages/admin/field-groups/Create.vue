<script setup lang="ts">
/** カスタムフィールドの新規作成 — Phase 9.5-B。 */
import { Head } from '@inertiajs/vue3';
import FieldGroupForm from '@/components/field-group/FieldGroupForm.vue';
import type { FieldGroupOptions } from '@/types/field-group';

defineProps<{ options: FieldGroupOptions }>();
</script>

<template>
    <Head title="カスタムフィールドを追加" />

    <div class="flex flex-col gap-1 p-4 pb-0">
        <h1 class="text-xl font-semibold">カスタムフィールドを追加</h1>
        <p class="text-sm text-muted-foreground">
            項目の束を作り、投稿タイプへ割り当てます。作成すると、すぐに割当先の編集画面に現れます。
        </p>
    </div>

    <FieldGroupForm
        :group="null"
        :options="options"
        action="/admin/field-groups"
        method="post"
        submit-label="作成する"
    />
</template>
