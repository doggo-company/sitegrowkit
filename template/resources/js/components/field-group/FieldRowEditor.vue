<script setup lang="ts">
import { computed } from 'vue';
import { FIELD_TYPE_LABELS } from '@/lib/field-type-labels';
/**
 * Field Group の項目 1 行（Phase 9.5-C / ADR-0017）。
 *
 * `group` / `repeater` を選ぶと **子の編集欄が同じ形で開く**（自分を呼ぶ）。
 * 深さと件数の上限はサーバーが正本で、ここは押せるものを正しく見せるためだけ。
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FieldGroupOptions } from '@/types/field-group';
import type { FieldRow } from '@/types/field-group-form';
import { emptyFieldRow } from '@/types/field-group-form';

/*
 * **`withDefaults` を外さない**（監査 指摘 22）。
 *
 * Vue は Boolean の prop を**渡さないと `false`** にする（`undefined` ではない）。
 * `showKey?: boolean` を素の `defineProps` で宣言したせいで、
 * **渡していないカスタムフィールドの画面からキーの欄が消えていた** —
 * 「既定は見せる」と書いておきながら、実際は逆だった。
 */
const props = withDefaults(
    defineProps<{
        row: FieldRow;
        options: FieldGroupOptions;
        /** 1 = トップレベル。上限に達したら子を開かせない */
        depth: number;
        /** フォームに割り当てている束か（richtext を出さない） */
        hasForm: boolean;
        /** サーバーの error key に合わせた path（`fields.0.fields.1`） */
        path: string;
        /** 全 error。この行の分は path で引く */
        errors: Record<string, string>;
        canMoveUp: boolean;
        canMoveDown: boolean;
        /** 総数の上限に達しているか（達したら追加させない） */
        atFieldLimit: boolean;
        /**
         * キーの欄を見せるか（R-8A ③）。
         *
         * フォームの画面では**利用者に書かせない** — キーは入力欄の `name` と
         * 受信 JSON のキーにしか出てこず、CSV の見出しも公開ページも表示名から作る。
         * 既定は「見せる」で、カスタムフィールドの画面はこれまでどおり。
         */
        showKey?: boolean;
    }>(),
    { showKey: true },
);

const keyVisible = computed(() => props.showKey);

const emit = defineEmits<{
    (e: 'update', row: FieldRow): void;
    (e: 'remove'): void;
    (e: 'move', delta: number): void;
}>();

const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

const typeLabels: Record<string, string> = FIELD_TYPE_LABELS;

/** その段で選べる型。サーバーの契約と同じ条件で絞る */
const availableTypes = computed(() =>
    props.options.fieldTypes.filter((type) => {
        if (props.hasForm && props.options.formForbiddenTypes.includes(type)) {
            return false;
        }

        // 入れ子に置けない型（image）は 2 段目以降に出さない
        if (props.depth > 1 && props.options.topLevelOnlyTypes.includes(type)) {
            return false;
        }

        // 深さの上限に達したら、子を持つ型はもう選べない
        if (
            props.depth >= props.options.limits.depth &&
            props.options.nestedTypes.includes(type)
        ) {
            return false;
        }

        return true;
    }),
);

const isNested = computed(() =>
    props.options.nestedTypes.includes(props.row.type),
);
const isReference = computed(() =>
    props.options.referenceTypes.includes(props.row.type),
);
const needsChoices = computed(() => CHOICE_TYPES.includes(props.row.type));

/** この行に紐づく error。サーバーが返す key をそのまま引く */
function errorFor(suffix = ''): string | undefined {
    return props.errors[suffix === '' ? props.path : `${props.path}.${suffix}`];
}

const atDepthLimit = computed(() => props.depth >= props.options.limits.depth);

function patch(changes: Partial<FieldRow>): void {
    emit('update', { ...props.row, ...changes });
}

function addChild(): void {
    if (props.atFieldLimit) {
        return;
    }

    patch({
        fields: [
            ...props.row.fields,
            emptyFieldRow(availableTypes.value[0] ?? 'text'),
        ],
    });
}

function updateChild(index: number, child: FieldRow): void {
    patch({
        fields: props.row.fields.map((c, i) => (i === index ? child : c)),
    });
}

function removeChild(index: number): void {
    patch({ fields: props.row.fields.filter((_, i) => i !== index) });
}

function moveChild(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= props.row.fields.length) {
        return;
    }

    patch({
        fields: props.row.fields.map((child, i) => {
            if (i === index) {
                return props.row.fields[target];
            }

            if (i === target) {
                return props.row.fields[index];
            }

            return child;
        }),
    });
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-lg border p-3"
        :data-testid="
            depth === 1 ? 'field-group-field-row' : 'field-group-child-row'
        "
    >
        <!-- 行そのものへ付いた error（型の意味の検証など） -->
        <InputError :message="errorFor()" />
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="flex flex-col gap-1">
                <Label :for="`fg-field-label-${row.rowId}`">表示名</Label>
                <Input
                    :id="`fg-field-label-${row.rowId}`"
                    :model-value="row.label"
                    data-testid="field-group-field-label"
                    @update:model-value="
                        (v: string | number) => patch({ label: String(v) })
                    "
                />
                <InputError :message="errorFor('label')" />
            </div>
            <div v-if="keyVisible" class="flex flex-col gap-1">
                <Label :for="`fg-field-key-${row.rowId}`">キー</Label>
                <Input
                    :id="`fg-field-key-${row.rowId}`"
                    :model-value="row.key"
                    data-testid="field-group-field-key"
                    @update:model-value="
                        (v: string | number) => patch({ key: String(v) })
                    "
                />
                <InputError :message="errorFor('key')" />
            </div>
            <div class="flex flex-col gap-1">
                <Label :for="`fg-field-type-${row.rowId}`">種類</Label>
                <select
                    :id="`fg-field-type-${row.rowId}`"
                    :value="row.type"
                    data-testid="field-group-field-type"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                    @change="
                        (e: Event) =>
                            patch({
                                type: (e.target as HTMLSelectElement).value,
                            })
                    "
                >
                    <option
                        v-for="type in availableTypes"
                        :key="type"
                        :value="type"
                    >
                        {{ typeLabels[type] ?? type }}
                    </option>
                </select>
                <InputError :message="errorFor('type')" />
            </div>
        </div>

        <div v-if="needsChoices" class="flex flex-col gap-1">
            <Label :for="`fg-field-choices-${row.rowId}`"
                >選択肢（1 行 1 件）</Label
            >
            <textarea
                :id="`fg-field-choices-${row.rowId}`"
                :value="row.choicesText"
                rows="3"
                data-testid="field-group-field-choices"
                class="rounded-md border bg-background px-3 py-2 text-sm"
                @input="
                    (e: Event) =>
                        patch({
                            choicesText: (e.target as HTMLTextAreaElement)
                                .value,
                        })
                "
            />
            <InputError :message="errorFor('choices')" />
        </div>

        <div v-if="isReference" class="flex flex-col gap-1">
            <Label :for="`fg-field-target-${row.rowId}`"
                >参照先の投稿タイプ</Label
            >
            <select
                :id="`fg-field-target-${row.rowId}`"
                :value="row.target ?? ''"
                data-testid="field-group-field-target"
                class="h-9 rounded-md border bg-background px-3 text-sm"
                @change="
                    (e: Event) =>
                        patch({ target: (e.target as HTMLSelectElement).value })
                "
            >
                <option value="" disabled>選んでください</option>
                <option
                    v-for="target in options.referenceTargets"
                    :key="target.value"
                    :value="target.value"
                >
                    {{ target.label }}
                </option>
            </select>
            <InputError :message="errorFor('target')" />
        </div>

        <div v-if="row.type === 'repeater'" class="flex flex-col gap-1">
            <Label :for="`fg-field-max-${row.rowId}`">行数の上限</Label>
            <Input
                :id="`fg-field-max-${row.rowId}`"
                type="number"
                :min="1"
                :max="options.limits.repeaterMax"
                :model-value="row.max ?? ''"
                data-testid="field-group-field-max"
                @update:model-value="
                    (v: string | number) =>
                        patch({ max: v === '' ? null : Number(v) })
                "
            />
            <p class="text-xs text-muted-foreground">
                未入力なら {{ options.limits.repeaterDefaultMax }} 行。上限は
                {{ options.limits.repeaterMax }} 行です。
            </p>
            <InputError :message="errorFor('max')" />
        </div>

        <!-- group / repeater の子。同じ行エディタが自分を呼ぶ -->
        <fieldset
            v-if="isNested"
            class="flex flex-col gap-3 rounded-lg border border-dashed p-3"
            data-testid="field-group-children"
        >
            <legend class="px-1 text-xs text-muted-foreground">
                {{ row.label || 'この項目' }}の中身
            </legend>

            <FieldRowEditor
                v-for="(child, index) in row.fields"
                :key="child.rowId"
                :row="child"
                :options="options"
                :depth="depth + 1"
                :has-form="hasForm"
                :path="`${path}.fields.${index}`"
                :errors="errors"
                :can-move-up="index > 0"
                :can-move-down="index < row.fields.length - 1"
                :at-field-limit="atFieldLimit"
                :show-key="keyVisible"
                @update="(c: FieldRow) => updateChild(index, c)"
                @remove="removeChild(index)"
                @move="(d: number) => moveChild(index, d)"
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="atFieldLimit || atDepthLimit"
                data-testid="field-group-add-child"
                @click="addChild"
            >
                中の項目を追加
            </Button>
            <InputError :message="errorFor('fields')" />
        </fieldset>

        <div class="flex flex-wrap items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="row.required"
                    @update:model-value="
                        (v: boolean | 'indeterminate') =>
                            patch({ required: v === true })
                    "
                />
                必須にする
            </label>
            <Input
                :model-value="row.help"
                class="max-w-xs"
                placeholder="説明（任意）"
                @update:model-value="
                    (v: string | number) => patch({ help: String(v) })
                "
            />
            <div class="ml-auto flex gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="!canMoveUp"
                    data-testid="field-group-move-field-up"
                    @click="emit('move', -1)"
                >
                    上へ
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="!canMoveDown"
                    data-testid="field-group-move-field-down"
                    @click="emit('move', 1)"
                >
                    下へ
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-testid="field-group-remove-field"
                    @click="emit('remove')"
                >
                    削除
                </Button>
            </div>
        </div>
    </div>
</template>
