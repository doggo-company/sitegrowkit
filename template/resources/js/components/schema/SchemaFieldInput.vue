<script setup lang="ts">
// Schema 駆動フォームの 1 フィールド。手書きフォームでの二重管理を防ぐ唯一の入力コンポーネント。
import { computed, defineAsyncComponent, inject } from 'vue';
import type { ComputedRef } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/InputError.vue';
import ImagesInput from '@/components/schema/ImagesInput.vue';
import MediaPicker from '@/components/schema/MediaPicker.vue';
import RelationInput from '@/components/schema/RelationInput.vue';
import { normalizePageValue } from '@/lib/page-choice';
import type { PageChoice } from '@/lib/page-choice';
/**
 * 本文の見たまま編集は TipTap ごと別 chunk にする（2026-09-09）。
 * このコンポーネントはサイト設定など本文の無い画面でも使うので、
 * そこまで 400 kB を運ばない。要る画面で初めて読む。
 */
const adminReadOnly = inject<ComputedRef<boolean>>(
    'admin-read-only',
    computed(() => false),
);

const RichtextEditor = defineAsyncComponent(
    () => import('@/components/schema/RichtextEditor.vue'),
);
import type { SchemaField } from '@/types/schema';

const props = withDefaults(
    defineProps<{
        field: SchemaField;
        error?: string;
        /** 既存の画像パス（image フィールドのみ） */
        currentImage?: string | null;
        /**
         * 入れ子の中での位置（`spec_group` / `spec_rows.0`）。
         * error を項目へ結びつけるために使う。トップレベルでは空。
         */
        path?: string;
        /** 入れ子の子ごとの error（`errors['spec_group.weight']` の形） */
        errors?: Record<string, string>;
        /**
         * `page` 型の選択肢（R-5）。**候補はデータであって schema ではない**ので、
         * 呼ぶ側が渡す。渡さないと「候補が分からない」と画面に出す —
         * **空の選択肢を出すと「1 つも無い」と区別が付かない**。
         */
        pageChoices?: PageChoice[] | null;
    }>(),
    {
        error: undefined,
        currentImage: null,
        path: '',
        errors: () => ({}),
        pageChoices: null,
    },
);

const model = defineModel<unknown>();
const fileModel = defineModel<File | null>('file');

const statusLabels: Record<string, string> = {
    draft: '下書き',
    published: '公開',
};

/**
 * 「選ばない」を表す値（`page` 型・R-5）。
 *
 * shadcn / Radix の Select は**空文字を値に取れない**ので、選択肢としては合図の語を使い、
 * 保存する値は `null` に戻す。合図の語がそのまま保存されると、
 * **存在しないページ id として弾かれる**ので、変換はここ 1 か所に閉じる。
 */
const PAGE_NONE = '__none__';

/**
 * Select に見せる値。**いま候補に無い id は「選ばない」として見せる**。
 *
 * 生の id を見せると、候補に無いので **Select は空白**になり、
 * 何が選ばれているのかも、どうすれば直るのかも分からない（監査 指摘 1）。
 * 送る値の側は呼ぶ側が同じ関数で落とす — 見せ方と送る値がずれないため。
 */
const pageModel = computed<string>(() => {
    const id = normalizePageValue(model.value, props.pageChoices ?? null);

    return id === null ? PAGE_NONE : String(id);
});

function setPage(value: string) {
    model.value = value === PAGE_NONE ? null : Number(value);
}

/** 1 行入力で扱う型 → HTML input type（schema の型を画面へ焼き直さない） */
const inlineInputTypes: Record<string, string> = {
    text: 'text',
    slug: 'text',
    email: 'email',
    url: 'url',
    tel: 'tel',
    number: 'number',
};

/** この項目の完全な path（error の引き当てに使う） */
const fieldPath = computed(() =>
    props.path === '' ? props.field.key : `${props.path}.${props.field.key}`,
);

/**
 * DOM の id。
 *
 * **path から作る。** `field.key` 固定にすると repeater の行ごとに同じ id が並び、
 * label が別の行の入力欄を指してしまう（読み上げも誤る）。
 * トップレベルでは path === key なので、既存の id は変わらない。
 */
const inputId = computed(() => fieldPath.value.replace(/\./g, '-'));

/**
 * `richtext` の書式ボタンと本文の編集は `RichtextEditor.vue` が持つ（2026-09-09）。
 * ここは項目の種類で部品を選ぶだけで、HTML の組み立ても TipTap も知らない。
 */
const labelId = computed(() => `${inputId.value}-label`);

/** 子の値。未設定でも必ずオブジェクト / 配列として扱う */
const groupValue = computed<Record<string, unknown>>(() =>
    typeof model.value === 'object' &&
    model.value !== null &&
    !Array.isArray(model.value)
        ? (model.value as Record<string, unknown>)
        : {},
);

const rows = computed<Record<string, unknown>[]>(() =>
    Array.isArray(model.value)
        ? (model.value as Record<string, unknown>[])
        : [],
);

/** 行数の上限。サーバーが正本で、ここは押せるものを正しく見せるためだけ */
const rowLimit = computed(() => props.field.max ?? null);

/**
 * 参照の値。
 *
 * **template で `as A | B` と書かない。** `|` が Vue の filter 構文として
 * 解釈され、lint が落ちる。型の辻褄は script 側で合わせる。
 */
const relationModel = computed<number | number[] | null>(() => {
    if (Array.isArray(model.value)) {
        return model.value as number[];
    }

    return typeof model.value === 'number' ? model.value : null;
});

const imagesModel = computed<number[]>(() =>
    Array.isArray(model.value) ? (model.value as number[]) : [],
);

function setChild(key: string, value: unknown): void {
    model.value = { ...groupValue.value, [key]: value };
}

function setRowChild(index: number, key: string, value: unknown): void {
    model.value = rows.value.map((row, i) =>
        i === index ? { ...row, [key]: value } : row,
    );
}

function addRow(): void {
    if (rowLimit.value !== null && rows.value.length >= rowLimit.value) {
        return;
    }

    model.value = [...rows.value, {}];
}

function removeRow(index: number): void {
    model.value = rows.value.filter((_, i) => i !== index);
}

function duplicateRow(index: number): void {
    if (rowLimit.value !== null && rows.value.length >= rowLimit.value) {
        return;
    }

    // `_id` は複製しない。サーバーが新しい行として採番し直す
    const copy: Record<string, unknown> = { ...rows.value[index] };
    delete copy._id;

    model.value = [
        ...rows.value.slice(0, index + 1),
        copy,
        ...rows.value.slice(index + 1),
    ];
}

function moveRow(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= rows.value.length) {
        return;
    }

    model.value = rows.value.map((row, i) => {
        if (i === index) {
            return rows.value[target];
        }

        if (i === target) {
            return rows.value[index];
        }

        return row;
    });
}

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    fileModel.value = input.files?.[0] ?? null;
}

/** 複数選択（checkbox）は配列で保持する。未設定は空配列として扱う */
function checkedValues(value: unknown): string[] {
    return Array.isArray(value) ? (value as string[]) : [];
}

function toggleChoice(value: unknown, choice: string, checked: boolean) {
    const current = checkedValues(value);

    return checked
        ? [...current.filter((item) => item !== choice), choice]
        : current.filter((item) => item !== choice);
}
</script>

<template>
    <div class="grid gap-2">
        <Label :id="labelId" :for="inputId">
            {{ field.label }}
            <!-- 見出しから作れる URL は、空のままでよい（「必須」の印と案内が食い違っていた。UX 一周 2026-09-28） -->
            <span
                v-if="field.required && !(field.type === 'slug' && field.from)"
                class="text-destructive"
                >*</span
            >
        </Label>

        <Input
            v-if="inlineInputTypes[field.type] !== undefined"
            :id="inputId"
            :type="inlineInputTypes[field.type]"
            :maxlength="
                field.type === 'number' ? undefined : (field.max ?? undefined)
            "
            :model-value="(model as string) ?? ''"
            @update:model-value="model = $event"
        />

        <!-- 本文の見たまま編集 + HTML 切替（R02・2026-09-09）。
             書式ボタンが出す tag は sanitize の allowlist からしか採らない -->
        <RichtextEditor
            v-else-if="field.type === 'richtext'"
            :input-id="inputId"
            :label-id="labelId"
            :disabled="adminReadOnly"
            :required="field.required"
            :invalid="error !== undefined"
            :maxlength="field.max ?? null"
            :model-value="(model as string | null) ?? ''"
            @update:model-value="model = $event"
        />

        <textarea
            v-else-if="field.type === 'textarea'"
            :id="inputId"
            :rows="4"
            :maxlength="field.max ?? undefined"
            class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :value="(model as string) ?? ''"
            @input="model = ($event.target as HTMLTextAreaElement).value"
        />

        <template
            v-else-if="field.type === 'select' || field.type === 'status'"
        >
            <Select
                :model-value="(model as string) ?? undefined"
                @update:model-value="model = $event"
            >
                <SelectTrigger :id="inputId" class="w-56">
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="choice in field.type === 'status'
                            ? ['draft', 'published']
                            : field.choices"
                        :key="choice"
                        :value="choice"
                    >
                        {{
                            field.type === 'status'
                                ? statusLabels[choice]
                                : choice
                        }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <!-- ページを 1 つ指す（R-5）。保存されるのは id だけ -->
        <template v-else-if="field.type === 'page'">
            <p
                v-if="pageChoices === null"
                class="text-sm text-muted-foreground"
            >
                選べるページを読み込めませんでした。画面を開き直してください。
            </p>
            <p
                v-else-if="pageChoices.length === 0"
                class="text-sm text-muted-foreground"
            >
                公開中のページがまだありません。ページを 1
                つ作って公開すると、ここで選べるようになります。
            </p>
            <Select
                v-else
                :model-value="pageModel"
                @update:model-value="setPage($event as string)"
            >
                <SelectTrigger :id="inputId" class="w-72">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="PAGE_NONE">
                        選ばない（これまでどおりのトップページ）
                    </SelectItem>
                    <SelectItem
                        v-for="choice in pageChoices"
                        :key="choice.id"
                        :value="String(choice.id)"
                    >
                        {{ choice.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <!-- ラジオ選択。1 つだけ選ぶ（値は文字列） -->
        <div
            v-else-if="field.type === 'radio'"
            class="flex flex-wrap gap-4"
            role="radiogroup"
            :aria-labelledby="labelId"
        >
            <label
                v-for="choice in field.choices"
                :key="choice"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    type="radio"
                    class="size-4 accent-primary"
                    :name="field.key"
                    :value="choice"
                    :checked="model === choice"
                    @change="model = choice"
                />
                {{ choice }}
            </label>
        </div>

        <!-- 複数選択。値は配列で保持する（core の checkbox = json カラム） -->
        <div v-else-if="field.type === 'checkbox'" class="flex flex-wrap gap-4">
            <label
                v-for="choice in field.choices"
                :key="choice"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    type="checkbox"
                    class="size-4 accent-primary"
                    :value="choice"
                    :checked="checkedValues(model).includes(choice)"
                    @change="
                        model = toggleChoice(
                            model,
                            choice,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                {{ choice }}
            </label>
        </div>

        <Input
            v-else-if="field.type === 'date'"
            :id="inputId"
            type="date"
            class="w-56"
            :model-value="(model as string) ?? ''"
            @update:model-value="model = $event"
        />

        <Input
            v-else-if="field.type === 'datetime'"
            :id="inputId"
            type="datetime-local"
            class="w-56"
            :model-value="(model as string) ?? ''"
            @update:model-value="model = $event"
        />

        <div
            v-else-if="field.type === 'boolean'"
            class="flex items-center gap-2"
        >
            <!--
                箱（16px）だけだと押しにくい。箱のまわりを 44px 四方の押せる範囲にする
                （押すと切り替わる。題の Label と同じ欄を指す。触り心地 2026-11-05 / docs/feel-bar.md）
            -->
            <label
                :for="inputId"
                class="inline-flex min-h-11 min-w-11 cursor-pointer items-center"
            >
                <Checkbox
                    :id="inputId"
                    :model-value="Boolean(model)"
                    @update:model-value="model = $event === true"
                />
            </label>
        </div>

        <MediaPicker
            v-else-if="field.type === 'media'"
            :model-value="(model as number | null) ?? null"
            @update:model-value="model = $event"
        />

        <!--
            file は multipart ではなく **Media の ID を選ぶ**（Phase 9.5-D / D5）。
            だから入れ子でも保存できる（image を入れ子に置けない理由が当たらない）
        -->
        <MediaPicker
            v-else-if="field.type === 'file'"
            kind="file"
            :model-value="(model as number | null) ?? null"
            @update:model-value="model = $event"
        />

        <div v-else-if="field.type === 'image'" class="grid gap-2">
            <img
                v-if="currentImage"
                :src="`/storage/${currentImage}`"
                :alt="field.label"
                class="h-24 w-auto rounded-md border object-cover"
            />
            <Input
                :id="inputId"
                type="file"
                accept=".jpg,.jpeg,.png,.webp,.avif"
                @change="onFileChange"
            />
            <p class="text-xs text-muted-foreground">
                jpg / png / webp / avif（SVG 不可）
            </p>
        </div>

        <!-- group: 子項目をそのまま入れ子で描く（同じコンポーネントが自分を呼ぶ） -->
        <fieldset
            v-else-if="field.type === 'group'"
            class="flex flex-col gap-4 rounded-lg border p-3"
            data-testid="group-input"
        >
            <legend class="px-1 text-xs text-muted-foreground">
                {{ field.label }}
            </legend>
            <SchemaFieldInput
                v-for="child in field.fields ?? []"
                :key="child.key"
                :field="child"
                :path="fieldPath"
                :errors="errors"
                :error="errors[`${fieldPath}.${child.key}`]"
                :model-value="groupValue[child.key]"
                @update:model-value="(v: unknown) => setChild(child.key, v)"
            />
        </fieldset>

        <!-- repeater: 行の追加・複製・削除・並べ替え。すべてボタン = キーボードで操作できる -->
        <div
            v-else-if="field.type === 'repeater'"
            class="flex flex-col gap-3"
            data-testid="repeater-input"
        >
            <fieldset
                v-for="(row, index) in rows"
                :key="(row._id as string) ?? `new-${index}`"
                class="flex flex-col gap-4 rounded-lg border p-3"
                data-testid="repeater-row"
            >
                <legend class="px-1 text-xs text-muted-foreground">
                    {{ field.label }} {{ index + 1 }}
                </legend>

                <SchemaFieldInput
                    v-for="child in field.fields ?? []"
                    :key="child.key"
                    :field="child"
                    :path="`${fieldPath}.${index}`"
                    :errors="errors"
                    :error="errors[`${fieldPath}.${index}.${child.key}`]"
                    :model-value="row[child.key]"
                    @update:model-value="
                        (v: unknown) => setRowChild(index, child.key, v)
                    "
                />

                <div class="flex flex-wrap gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="index === 0"
                        data-testid="repeater-move-up"
                        @click="moveRow(index, -1)"
                    >
                        上へ
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="index === rows.length - 1"
                        data-testid="repeater-move-down"
                        @click="moveRow(index, 1)"
                    >
                        下へ
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-testid="repeater-duplicate"
                        @click="duplicateRow(index)"
                    >
                        複製
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-testid="repeater-remove"
                        @click="removeRow(index)"
                    >
                        削除
                    </Button>
                </div>
            </fieldset>

            <div class="flex items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="rowLimit !== null && rows.length >= rowLimit"
                    data-testid="repeater-add"
                    @click="addRow"
                >
                    行を追加
                </Button>
                <p
                    v-if="rowLimit !== null"
                    class="text-xs text-muted-foreground"
                >
                    {{ rows.length }} / {{ rowLimit }} 行
                </p>
            </div>
        </div>

        <RelationInput
            v-else-if="field.type === 'relation' || field.type === 'relations'"
            :target="field.target ?? ''"
            :multiple="field.type === 'relations'"
            :labelled-by="labelId"
            :model-value="relationModel"
            @update:model-value="
                (v: number | number[] | null | undefined) => (model = v ?? null)
            "
        />

        <ImagesInput
            v-else-if="field.type === 'images'"
            :labelled-by="labelId"
            :model-value="imagesModel"
            @update:model-value="(v: number[] | undefined) => (model = v ?? [])"
        />

        <!--
            型ごとの定型文は、schema が `help` を持っていないときだけ出す。

            🔴 **両方出すと同じ文が 2 行並ぶ**（2026-08-30 実測。「投稿」の
            スラッグ欄で「空のままにするとタイトルから自動生成されます」が 2 回出ていた）。
            `content/schemas/post.json` が同じ文を `help` に持っているためである。
            **案件が書いた説明のほうを優先する** — あちらは直せるが、こちらは直せない。
        -->
        <p
            v-if="field.type === 'richtext' && !field.help"
            class="text-xs text-muted-foreground"
        >
            見出しや太字などの書式を付けられます。
        </p>
        <p
            v-if="field.type === 'slug' && !field.help"
            class="text-xs text-muted-foreground"
        >
            空のままにするとタイトルから自動で作られます
        </p>
        <!-- schema の help をそのまま出す（フォーム側に文言を持たない） -->
        <p v-if="field.help" class="text-xs text-muted-foreground">
            {{ field.help }}
        </p>

        <InputError :message="error" />
    </div>
</template>
