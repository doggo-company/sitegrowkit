<script setup lang="ts">
/**
 * カスタムフィールドの編集 — Phase 9.5-B。
 *
 * 危険な変更（項目の削除・改名・型変更・選択肢の削除）は、サーバーが
 * 確認を要求してから適用する。**入力済みの値は削除されない**。
 */
import { Head } from '@inertiajs/vue3';
import FieldGroupForm from '@/components/field-group/FieldGroupForm.vue';
import { Badge } from '@/components/ui/badge';
import type { FieldGroupDetail, FieldGroupOptions } from '@/types/field-group';

const props = defineProps<{
    group: FieldGroupDetail;
    options: FieldGroupOptions;
}>();

const action = `/admin/field-groups/${props.group.id}`;
</script>

<template>
    <Head :title="`${group.label} — カスタムフィールド`" />

    <div class="flex flex-col gap-1 p-4 pb-0">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">{{ group.label }}</h1>
            <Badge :variant="group.active ? 'default' : 'secondary'">
                {{ group.status === 'archived' ? 'アーカイブ' : '公開' }}
            </Badge>
        </div>
        <p class="text-sm text-muted-foreground">
            項目を外しても、入力済みの値は削除されません。束へ戻せば再び表示されます。
        </p>
    </div>

    <FieldGroupForm
        :group="group"
        :options="options"
        :action="action"
        method="patch"
        submit-label="保存する"
    />
</template>
