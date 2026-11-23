<script setup lang="ts">
/**
 * カスタムフィールド（Field Group）の編集フォーム — Phase 9.5-B / ADR-0016。
 *
 * 作成と編集で **同じ 1 つのフォーム**を使う。2 つ作ると、片方だけ直したときに
 * 「作成では通るのに編集では通らない」がおきる。
 *
 * 送るのはサーバーが受理するキーだけ（`key` / `label` / `locations` / `fields`）。
 * 画面だけの値（行の識別子・改行区切りの選択肢）は送らない。
 * 検証・衝突検査・危険度分類はサーバーが行う。ここでは形を整えるだけ。
 */
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InputError from '@/components/InputError.vue';
import FieldRowEditor from '@/components/field-group/FieldRowEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { addRow, moveRow, removeRow, updateRow } from '@/lib/field-rows';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { FieldGroupDetail, FieldGroupOptions } from '@/types/field-group';
import type { FieldRow } from '@/types/field-group-form';
import {
    countRows,
    emptyFieldRow,
    nextRowId,
    toFieldRow,
    toPayload,
} from '@/types/field-group-form';

const props = defineProps<{
    /** 編集のとき渡す。作成では null */
    group: FieldGroupDetail | null;
    options: FieldGroupOptions;
    action: string;
    method: 'post' | 'patch';
    submitLabel: string;
}>();

interface LocationRow {
    rowId: number;
    kind: string;
    value: string;
}

const form = useForm({
    key: props.group?.key ?? '',
    label: props.group?.label ?? '',
    locations: (props.group?.locations ?? []).map<LocationRow>((location) => ({
        rowId: nextRowId(),
        kind: location.kind,
        value: location.value,
    })),
    // **id / fields / max / target を落とさない**（落とすと編集のたびに子が消える）
    fields: (props.group?.fields ?? []).map(toFieldRow),
});

if (form.fields.length === 0) {
    form.fields = [emptyFieldRow(props.options.fieldTypes[0] ?? 'text')];
}

const hasFormLocation = computed(() =>
    form.locations.some((location) => location.kind === 'form'),
);

/** 入れ子を含めた総数。判定はサーバーが正本で、ここは残りを見せるだけ */
const totalFields = computed(() => countRows(form.fields));

// 配列は **作り直す**（その場で書き換えない）。
// 途中で例外が出ても、半端に並び替わった状態が残らない
/*
 * 行の操作は `lib/field-rows` が持つ（R-8A ②）。
 * **フォームの画面も同じ操作を使う** — 写すと、片方だけ直したときに
 * 同じ操作が 2 通りの振る舞いをする。
 */
const defaultType = (): string => props.options.fieldTypes[0] ?? 'text';

function addField(): void {
    form.fields = addRow(form.fields, defaultType());
}

function updateField(index: number, row: FieldRow): void {
    form.fields = updateRow(form.fields, index, row);
}

function removeField(index: number): void {
    form.fields = removeRow(form.fields, index, defaultType());
}

function moveField(index: number, delta: number): void {
    form.fields = moveRow(form.fields, index, delta);
}

function addLocation(): void {
    form.locations = [
        ...form.locations,
        {
            rowId: nextRowId(),
            kind: 'content_type',
            value: props.options.contentTypes[0]?.value ?? '',
        },
    ];
}

function removeLocation(index: number): void {
    form.locations = form.locations.filter((_, i) => i !== index);
}

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        form.transform((data) => ({
            key: data.key,
            label: data.label,
            locations: (data.locations as LocationRow[]).map((location) => ({
                kind: location.kind,
                value: location.value,
            })),
            fields: (data.fields as FieldRow[]).map(toPayload),
            ...(token ? { confirmation_token: token } : {}),
        }))[props.method](props.action, {
            preserveScroll: true,
            preserveState: true,
        });
    },
);

function submit(): void {
    request();
}
</script>

<template>
    <form class="flex flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <Label for="fg-label">表示名</Label>
                <Input
                    id="fg-label"
                    v-model="form.label"
                    data-testid="field-group-label"
                    placeholder="商品スペック"
                />
                <InputError :message="form.errors.label" />
            </div>
            <div class="flex flex-col gap-2">
                <Label for="fg-key">キー（任意）</Label>
                <Input
                    id="fg-key"
                    v-model="form.key"
                    data-testid="field-group-key"
                    placeholder="空のままで大丈夫です"
                />
                <!--
                    分類（#20）と同じ言い方に揃える（WP 一周 10 周目 2026-09-04 / 効くもの ③）。
                    日本語だけの名前からはキーを作れないので、そこは通し番号になる。
                -->
                <p class="text-xs text-muted-foreground">
                    空のままにすると名前から作ります。日本語だけの名前のときは
                    <code>field_group_1</code> のような通し番号になります。
                </p>
                <p class="text-xs text-muted-foreground">
                    自分で書く場合は、英小文字で始まり、英小文字・数字・アンダースコアが使えます。
                </p>
                <InputError :message="form.errors.key" />
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-medium">割当先</h2>
                    <p class="text-xs text-muted-foreground">
                        公開中の束だけが、割当先の編集画面に項目として現れます。
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="field-group-add-location"
                    @click="addLocation"
                >
                    割当先を追加
                </Button>
            </div>

            <p
                v-if="form.locations.length === 0"
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            >
                割当先がありません。この束はどこにも表示されません。
            </p>

            <div
                v-for="(location, index) in form.locations"
                :key="location.rowId"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-3"
                data-testid="field-group-location-row"
            >
                <div class="flex flex-col gap-1">
                    <Label :for="`fg-loc-kind-${location.rowId}`">種類</Label>
                    <select
                        :id="`fg-loc-kind-${location.rowId}`"
                        v-model="location.kind"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="content_type">投稿タイプ</option>
                        <option value="form">フォーム</option>
                    </select>
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <Label :for="`fg-loc-value-${location.rowId}`"
                        >割当先</Label
                    >
                    <select
                        v-if="location.kind === 'content_type'"
                        :id="`fg-loc-value-${location.rowId}`"
                        v-model="location.value"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="type in options.contentTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>
                    <Input
                        v-else
                        :id="`fg-loc-value-${location.rowId}`"
                        v-model="location.value"
                        placeholder="estimate"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="removeLocation(index)"
                >
                    外す
                </Button>
            </div>

            <p
                v-if="hasFormLocation"
                class="text-xs text-muted-foreground"
                data-testid="field-group-form-notice"
            >
                フォームに割り当てているため、リッチテキストは選べません。公開フォームは常に外部からの入力を受け取るためです。
            </p>

            <InputError :message="form.errors.locations" />
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-medium">項目</h2>
                    <p class="text-xs text-muted-foreground">
                        入れ子は
                        {{ options.limits.depth }}
                        階層まで、項目は入れ子を含めて
                        {{ options.limits.fields }} 個までです（現在
                        {{ totalFields }} 個）。
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="totalFields >= options.limits.fields"
                    data-testid="field-group-add-field"
                    @click="addField"
                >
                    項目を追加
                </Button>
            </div>

            <FieldRowEditor
                v-for="(row, index) in form.fields"
                :key="row.rowId"
                :row="row"
                :options="options"
                :depth="1"
                :has-form="hasFormLocation"
                :path="`fields.${index}`"
                :errors="form.errors as Record<string, string>"
                :can-move-up="index > 0"
                :can-move-down="index < form.fields.length - 1"
                :at-field-limit="totalFields >= options.limits.fields"
                @update="(r: FieldRow) => updateField(index, r)"
                @remove="removeField(index)"
                @move="(d: number) => moveField(index, d)"
            />

            <InputError :message="form.errors.fields" />
        </section>

        <p class="text-xs text-muted-foreground">
            ここで追加した項目の値は、投稿の <code>data</code>
            に保存されます。一覧での絞り込みや並べ替えには使えません。
        </p>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-testid="field-group-submit"
            >
                {{ submitLabel }}
            </Button>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="この内容で保存する"
            @confirm="accept"
            @cancel="dismiss"
        />
    </form>
</template>
