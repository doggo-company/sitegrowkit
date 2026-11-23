<script setup lang="ts">
/**
 * 投稿タイプの新規作成 — Phase 9.4 / STAGE #17A・SPEC §1B / §1C。
 *
 * ## いきなり適用しない
 *
 * 送信すると、まずサーバーが検証して **計画（作られる schema・テーブル・URL）**
 * を返す。この時点でファイルも DB も変わっていない。利用者が確認して
 * はじめて適用される（ConfirmationGate は削除操作と同じもの）。
 *
 * ## 任意コードを受け取らない
 *
 * この画面が送るのは ID・表示名・フィールド定義という**構造化された値だけ**。
 * SQL・PHP・シェルを入力する欄は存在しない。検証はサーバー側が authoritative で、
 * ここでの入力補助は「入力しやすさ」のためだけにある。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type {
    BuilderFieldDraft,
    ContentTypeBuilderOptions,
    ContentTypePlan,
} from '@/types/content-type';

const props = defineProps<{ options: ContentTypeBuilderOptions }>();

/**
 * フォーム上の 1 フィールド。choices は入力しやすいよう文字列で持つ。
 *
 * `clientId` は **画面だけの識別子**（送信しない）。行の `:key` を index や
 * field key にすると、空キーの新規行・重複キー・並べ替え・削除のときに
 * Vue が別の行の DOM とチェック状態を使い回してしまう。
 */
interface FieldRow extends BuilderFieldDraft {
    choicesText: string;
    clientId: string;
}

let clientIdSeq = 0;

function nextClientId(): string {
    clientIdSeq += 1;

    return `field-${clientIdSeq}`;
}

function toRow(field: BuilderFieldDraft): FieldRow {
    return {
        ...field,
        choicesText: field.choices.join('\n'),
        clientId: nextClientId(),
    };
}

// 一覧列 / 検索対象の初期値も **サーバーの標準提案**を使う。
// 空で始めると「一覧にタイトルしか出ない・検索が効かない」状態から始まってしまう
const form = useForm({
    id: '',
    label: '',
    fields: props.options.defaultFields.map(toRow),
    list: [...props.options.defaultList],
    search: [...props.options.defaultSearch],
});

const plan = ref<ContentTypePlan | null>(null);

function typeOption(value: string) {
    return props.options.fieldTypes.find((type) => type.value === value);
}

function blankField(): FieldRow {
    return {
        key: '',
        type: 'text',
        label: '',
        required: false,
        max: null,
        choices: [],
        choicesText: '',
        help: '',
        searchable: false,
        filterable: false,
        sortable: false,
        clientId: nextClientId(),
    };
}

/**
 * フィールドキーを変更したら、一覧列 / 検索対象の選択も追従させる。
 *
 * 追従しないと古いキーが残り、サーバー側で「存在しないフィールド」として
 * 弾かれる（黙って落とさない代わりに、ここで直せるようにしておく）。
 */
function renameFieldKey(index: number, nextKey: string) {
    const previous = form.fields[index].key;

    form.fields[index].key = nextKey;

    if (previous === nextKey) {
        return;
    }

    const follow = (list: string[]) =>
        list.map((key) => (key === previous ? nextKey : key));

    form.list = follow(form.list);
    form.search = follow(form.search);
}

function addField() {
    form.fields = [...form.fields, blankField()];
}

function removeField(index: number) {
    const removed = form.fields[index];
    form.fields = form.fields.filter((_, i) => i !== index);
    form.list = form.list.filter((key) => key !== removed.key);
    form.search = form.search.filter((key) => key !== removed.key);
}

function moveField(index: number, direction: -1 | 1) {
    const target = index + direction;

    if (target < 0 || target >= form.fields.length) {
        return;
    }

    const fields = [...form.fields];
    [fields[index], fields[target]] = [fields[target], fields[index]];
    form.fields = fields;
}

function toggleIn(list: string[], key: string, checked: boolean): string[] {
    return checked
        ? [...list.filter((item) => item !== key), key]
        : list.filter((item) => item !== key);
}

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        form.transform((data) => ({
            ...data,
            // clientId と choicesText は画面だけの値なので送らない
            fields: (data.fields as FieldRow[]).map((field) => ({
                key: field.key,
                type: field.type,
                label: field.label,
                required: field.required,
                max: field.max,
                help: field.help,
                // 検索対象の指定は下の「検索対象にする項目」だけが正。
                // 同じ意味の操作を 2 か所に置かない
                searchable: (data.search as string[]).includes(field.key),
                filterable: field.filterable,
                sortable: field.sortable,
                choices: field.choicesText
                    .split('\n')
                    .map((choice) => choice.trim())
                    .filter((choice) => choice !== ''),
            })),
            ...(token ? { confirmation_token: token } : {}),
        })).post('/admin/content-types', {
            preserveScroll: true,
            preserveState: true,
        });
    },
);

function submit() {
    plan.value = null;
    request();
}

// 計画は confirmation と同じ flash で届く（confirmation は composable が拾う）
onMounted(() => {
    const stop = router.on('flash', (event) => {
        const flashed = (event as CustomEvent).detail?.flash?.plan as
            ContentTypePlan | undefined;

        if (flashed) {
            plan.value = flashed;
        }
    });

    onUnmounted(stop);
});

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="投稿タイプを追加" />

    <form class="flex flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">投稿タイプを追加</h1>
            <p class="text-sm text-muted-foreground">
                入力内容を確認してから作成します。確認するまでファイルもデータベースも変更されません。
            </p>
        </div>

        <div class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="label"
                    >表示名 <span class="text-destructive">*</span></Label
                >
                <Input
                    id="label"
                    v-model="form.label"
                    maxlength="40"
                    placeholder="例：よくある質問"
                />
                <InputError :message="form.errors.label" />
            </div>

            <div class="grid gap-2">
                <Label for="id"
                    >投稿タイプ ID
                    <span class="text-destructive">*</span></Label
                >
                <Input
                    id="id"
                    v-model="form.id"
                    maxlength="40"
                    placeholder="例：faq"
                    class="font-mono"
                />
                <p class="text-xs text-muted-foreground">
                    英小文字で始まり、英小文字・数字・アンダースコアのみ（2〜40
                    文字）。管理画面の URL
                    とテーブル名になり、あとから変更できません。
                </p>
                <InputError :message="form.errors.id" />
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-base font-semibold">フィールド構成</h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addField"
                >
                    フィールドを追加
                </Button>
            </div>
            <InputError :message="form.errors.fields" />

            <div
                v-for="(field, index) in form.fields"
                :key="field.clientId"
                class="grid gap-3 rounded-xl border p-4"
                data-testid="builder-field"
            >
                <div class="flex items-center justify-between gap-2">
                    <Badge variant="secondary">{{ index + 1 }}</Badge>
                    <div class="flex gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="index === 0"
                            :aria-label="`${field.label || 'フィールド'}を上へ移動`"
                            @click="moveField(index, -1)"
                        >
                            ↑
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="index === form.fields.length - 1"
                            :aria-label="`${field.label || 'フィールド'}を下へ移動`"
                            @click="moveField(index, 1)"
                        >
                            ↓
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="`${field.label || 'フィールド'}を削除`"
                            @click="removeField(index)"
                        >
                            削除
                        </Button>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label :for="`field-label-${index}`">表示名</Label>
                        <Input
                            :id="`field-label-${index}`"
                            v-model="field.label"
                            maxlength="60"
                        />
                        <InputError
                            :message="form.errors[`fields.${index}.label`]"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`field-key-${index}`"
                            >フィールドキー</Label
                        >
                        <Input
                            :id="`field-key-${index}`"
                            maxlength="40"
                            class="font-mono"
                            :model-value="field.key"
                            @update:model-value="
                                renameFieldKey(index, String($event))
                            "
                        />
                        <InputError
                            :message="form.errors[`fields.${index}.key`]"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`field-type-${index}`">種類</Label>
                        <select
                            :id="`field-type-${index}`"
                            v-model="field.type"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="type in options.fieldTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors[`fields.${index}.type`]"
                        />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-if="typeOption(field.type)?.needsChoices"
                        class="grid gap-2"
                    >
                        <Label :for="`field-choices-${index}`"
                            >選択肢（1 行に 1 つ）</Label
                        >
                        <textarea
                            :id="`field-choices-${index}`"
                            v-model="field.choicesText"
                            rows="3"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError
                            :message="form.errors[`fields.${index}.choices`]"
                        />
                    </div>

                    <div
                        v-if="typeOption(field.type)?.supportsMax"
                        class="grid gap-2"
                    >
                        <Label :for="`field-max-${index}`">文字数上限</Label>
                        <Input
                            :id="`field-max-${index}`"
                            type="number"
                            min="1"
                            :max="typeOption(field.type)?.maxLimit"
                            class="w-32"
                            :model-value="field.max ?? ''"
                            @update:model-value="
                                field.max =
                                    $event === '' || $event === null
                                        ? null
                                        : Number($event)
                            "
                        />
                        <InputError
                            :message="form.errors[`fields.${index}.max`]"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`field-help-${index}`">補足説明</Label>
                        <Input
                            :id="`field-help-${index}`"
                            v-model="field.help"
                            maxlength="200"
                        />
                        <InputError
                            :message="form.errors[`fields.${index}.help`]"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="field.required"
                            @update:model-value="
                                field.required = $event === true
                            "
                        />
                        必須
                    </label>
                    <label
                        v-if="typeOption(field.type)?.supportsIndex"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :model-value="field.filterable"
                            @update:model-value="
                                field.filterable = $event === true
                            "
                        />
                        絞り込み
                    </label>
                    <label
                        v-if="typeOption(field.type)?.supportsIndex"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :model-value="field.sortable"
                            @update:model-value="
                                field.sortable = $event === true
                            "
                        />
                        並び替え
                    </label>
                </div>
            </div>
        </section>

        <section class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">一覧に表示する項目</legend>
                <label
                    v-for="field in form.fields"
                    :key="`list-${field.clientId}`"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.list.includes(field.key)"
                        @update:model-value="
                            form.list = toggleIn(
                                form.list,
                                field.key,
                                $event === true,
                            )
                        "
                    />
                    {{ field.label || field.key }}
                </label>
                <InputError :message="form.errors.list" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">検索対象にする項目</legend>
                <label
                    v-for="field in form.fields.filter(
                        (item) => typeOption(item.type)?.supportsSearchable,
                    )"
                    :key="`search-${field.clientId}`"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.search.includes(field.key)"
                        @update:model-value="
                            form.search = toggleIn(
                                form.search,
                                field.key,
                                $event === true,
                            )
                        "
                    />
                    {{ field.label || field.key }}
                </label>
                <InputError :message="form.errors.search" />
            </fieldset>
        </section>

        <!-- 適用前の計画。サーバーが決めた内容をそのまま出す -->
        <section
            v-if="plan"
            class="grid gap-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
            data-testid="content-type-plan"
        >
            <h2 class="text-base font-semibold">この内容で作成します</h2>
            <dl class="grid gap-1 text-sm sm:grid-cols-2">
                <div>
                    <dt class="inline text-muted-foreground">表示名：</dt>
                    <dd class="inline">{{ plan.label }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">ID：</dt>
                    <dd class="inline font-mono">{{ plan.id }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">テーブル：</dt>
                    <dd class="inline font-mono">{{ plan.table }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">管理画面：</dt>
                    <dd class="inline font-mono">{{ plan.adminUrl }}</dd>
                </div>
            </dl>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th
                                class="px-2 py-1 font-medium text-muted-foreground"
                            >
                                項目
                            </th>
                            <th
                                class="px-2 py-1 font-medium text-muted-foreground"
                            >
                                キー
                            </th>
                            <th
                                class="px-2 py-1 font-medium text-muted-foreground"
                            >
                                種類
                            </th>
                            <th
                                class="px-2 py-1 font-medium text-muted-foreground"
                            >
                                必須
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="planField in plan.fields"
                            :key="planField.key"
                            class="border-b last:border-0"
                        >
                            <td class="px-2 py-1">{{ planField.label }}</td>
                            <td class="px-2 py-1 font-mono text-xs">
                                {{ planField.key }}
                            </td>
                            <td class="px-2 py-1">{{ planField.typeLabel }}</td>
                            <td class="px-2 py-1">
                                {{ planField.required ? 'はい' : 'いいえ' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                <li v-for="(note, i) in plan.notes" :key="i">{{ note }}</li>
                <li>
                    生成されるファイル：{{ plan.files.schema }} /
                    {{ plan.files.model }} / {{ plan.files.migration }}
                </li>
            </ul>
        </section>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                内容を確認する
            </Button>
            <Button variant="secondary" as-child>
                <Link href="/admin/content-types">キャンセル</Link>
            </Button>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="この内容で作成する"
            @confirm="accept"
            @cancel="dismiss"
        />
    </form>
</template>
