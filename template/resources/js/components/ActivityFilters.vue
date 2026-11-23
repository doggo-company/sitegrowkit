<script setup lang="ts">
/** 操作履歴の絞り込み（全体・ユーザー別で共通） — STAGE #18A */
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ActivityFilterOptions, ActivityFilterState } from '@/types/user';

const props = defineProps<{
    filter: ActivityFilterState;
    options: ActivityFilterOptions;
}>();

const emit = defineEmits<{
    (e: 'apply', value: Record<string, string>): void;
}>();

const state = ref({
    from: props.filter.from ?? '',
    until: props.filter.until ?? '',
    operation: props.filter.operation ?? '',
    result: props.filter.result ?? '',
    interface: props.filter.interface ?? '',
});
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-3 rounded-xl border p-4"
        @submit.prevent="emit('apply', { ...state })"
    >
        <div class="grid gap-2">
            <Label for="activity-from">開始日</Label>
            <Input id="activity-from" v-model="state.from" type="date" />
        </div>
        <div class="grid gap-2">
            <Label for="activity-until">終了日</Label>
            <Input id="activity-until" v-model="state.until" type="date" />
        </div>
        <div class="grid gap-2">
            <Label for="activity-operation">操作</Label>
            <select
                id="activity-operation"
                v-model="state.operation"
                class="h-9 w-56 rounded-md border border-input bg-transparent px-3 text-sm"
            >
                <option value="">すべて</option>
                <option
                    v-for="option in options.operations"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label for="activity-result">結果</Label>
            <select
                id="activity-result"
                v-model="state.result"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
            >
                <option value="">すべて</option>
                <option
                    v-for="option in options.results"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </div>
        <div class="grid gap-2">
            <Label for="activity-interface">経路</Label>
            <select
                id="activity-interface"
                v-model="state.interface"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
            >
                <option value="">すべて</option>
                <option
                    v-for="option in options.interfaces"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </div>
        <Button type="submit" variant="secondary">絞り込む</Button>
    </form>
</template>
