<script setup lang="ts">
/**
 * Content 編集フォームの分類選択（Phase 9.3B / #16・SPEC §R / §I.6）。
 *
 * | 規則 | 実装 |
 * | --- | --- |
 * | 候補は **active のみ**（AR-3 / HR-1） | サーバーが active だけを返す |
 * | 既存の archived assignment は **選択済み + 「アーカイブ済み」表示で残す**（AS-12） | 候補末尾へ「付与済み」として足す |
 * | archived を **新規に付与できない** | 付与済みでない archived は候補に出ない |
 * | archived を維持したまま active を追加できる（AS-6） | 選択集合をそのまま持ち回る |
 * | archived の解除は可能（AS-4） | チェックを外す / 別の項目を選ぶ |
 * | `multiple` / `required` は Content Type 側の制約（§L / §M） | 宣言をそのまま使う |
 *
 * **黙って解除しない。** 読んだ内容をそのまま書き戻せる形にする（AS-11）。
 */
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { AssignedTerm, TaxonomyOptionGroup } from '@/types/taxonomy';

const props = defineProps<{
    group: TaxonomyOptionGroup;
    /** 既に付与されている項目（archived を含む） */
    assigned: AssignedTerm[];
    error?: string;
}>();

const model = defineModel<string[]>({ default: () => [] });

interface Choice {
    id: string;
    name: string;
    archived: boolean;
    depth: number;
}

/** 祖先をたどった深さ（root = 0）。壊れたデータでも止まるよう上限を置く */
const depthOf = (id: string | null): number => {
    let depth = 0;
    let current = id;

    while (current !== null && depth < 10) {
        const node = props.group.terms.find((term) => term.id === current);

        if (node === undefined || node.parentId === null) {
            break;
        }

        current = node.parentId;
        depth += 1;
    }

    return depth;
};

/** active 候補 + 「付与済みの archived」だけ。未付与の archived は出さない（AR-3） */
const choices = computed<Choice[]>(() => {
    const activeChoices: Choice[] = props.group.terms.map((term) => ({
        id: term.id,
        name: term.name,
        archived: false,
        depth: props.group.hierarchical ? depthOf(term.id) : 0,
    }));

    const archivedAssigned: Choice[] = props.assigned
        .filter(
            (term) =>
                term.archived &&
                !props.group.terms.some((option) => option.id === term.id),
        )
        .map((term) => ({
            id: term.id,
            name: term.name,
            archived: true,
            depth: 0,
        }));

    return [...activeChoices, ...archivedAssigned];
});

const inputId = computed(() => `taxonomy-${props.group.key}`);

function toggle(id: string, checked: boolean) {
    if (props.group.multiple) {
        model.value = checked
            ? [...model.value.filter((value) => value !== id), id]
            : model.value.filter((value) => value !== id);

        return;
    }

    model.value = checked ? [id] : [];
}

function selectSingle(value: string) {
    model.value = value === '' ? [] : [value];
}

const singleValue = computed(() => model.value[0] ?? '');
const label = (choice: Choice) =>
    choice.archived ? `${choice.name}（アーカイブ済み）` : choice.name;
</script>

<template>
    <div class="grid gap-2" :data-testid="`taxonomy-field-${group.key}`">
        <Label :for="inputId">
            {{ group.label }}
            <span v-if="group.required" class="text-destructive">*</span>
        </Label>

        <p v-if="!group.available" class="text-sm text-muted-foreground">
            {{ group.message ?? 'この分類はまだ利用できません。' }}
        </p>

        <template v-else-if="group.multiple">
            <div
                v-for="choice in choices"
                :key="choice.id"
                class="flex items-center gap-2"
                :style="{ paddingLeft: `${choice.depth * 1.25}rem` }"
            >
                <Checkbox
                    :id="`${inputId}-${choice.id}`"
                    :model-value="model.includes(choice.id)"
                    :data-testid="`taxonomy-option-${choice.id}`"
                    @update:model-value="toggle(choice.id, $event === true)"
                />
                <Label
                    :for="`${inputId}-${choice.id}`"
                    class="text-sm font-normal"
                >
                    {{ label(choice) }}
                </Label>
            </div>
            <p
                v-if="choices.length === 0"
                class="text-sm text-muted-foreground"
            >
                選べる項目がありません。分類管理から項目を追加してください。
            </p>
        </template>

        <select
            v-else
            :id="inputId"
            class="h-9 w-full max-w-sm rounded-md border border-input bg-transparent px-3 text-sm"
            :value="singleValue"
            @change="selectSingle(($event.target as HTMLSelectElement).value)"
        >
            <option value="">選択しない</option>
            <option
                v-for="choice in choices"
                :key="choice.id"
                :value="choice.id"
            >
                {{ '　'.repeat(choice.depth) }}{{ label(choice) }}
            </option>
        </select>

        <InputError :message="error" />
    </div>
</template>
