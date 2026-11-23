<script setup lang="ts">
/**
 * フォームの作成と編集（Phase 9.12 / STAGE 25D・SPEC §D.1）。
 *
 * ## 識別子はあとから変えられない
 *
 * 公開 URL に出るので、変えると**配ってしまった URL が死ぬ**。
 * 作るときだけ入力させ、編集では読み取り専用にして理由を書く。
 *
 * ## 自動返信の宛先は選ばせる（OG6）
 *
 * 名前から推測しない。`email` 型の項目だけを選択肢に出す。
 * 選べる項目が無いなら、その旨を書いて自動返信を諦めさせる。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import FieldRowEditor from '@/components/field-group/FieldRowEditor.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import {
    customerConfirmation,
    labelsByKey,
    noStoredValues,
} from '@/lib/field-confirmation';
import { assignFieldKeys } from '@/lib/field-key';
import { slugifyFormKey } from '@/lib/form-key';
import { notifyLines } from '@/lib/notify-recipients';
import { fieldPickerNote } from '@/lib/form-field-picker';
import { addRow, moveRow, removeRow, updateRow } from '@/lib/field-rows';
import type { FieldGroupDetail, FieldGroupOptions } from '@/types/field-group';
import type { FieldRow } from '@/types/field-group-form';
import {
    countRows,
    emptyFieldRow,
    toFieldRow,
    toPayload,
} from '@/types/field-group-form';

interface FormField {
    key: string;
    type: string;
    label: string;
    required: boolean;
}

interface FormRecord {
    key: string;
    name: string;
    description: string | null;
    field_group_id: string | null;
    field_problem: string | null;
    status: string;
    confirm_step: boolean;
    submit_label: string;
    complete_message: string | null;
    notify_to: string | null;
    autoreply_field: string | null;
    autoreply_subject: string | null;
    autoreply_body: string | null;
    autoreply_signature: string | null;
    mail_from_name: string | null;
    reply_to: string | null;
    submissions: number;
    lock_version: number;
}

const props = defineProps<{
    form: FormRecord | null;
    /**
     * 新規のときに埋めておく値（公開前チェック・見本ページ一覧から来たとき）。
     * `/contact` を切り替えるには識別子が `contact` でなければならないので、
     * **打たせずに入れておく**（UX 一周 2026-09-27）。
     */
    preset?: { key: string; name: string } | null;
    fields: FormField[];
    fieldGroups: {
        id: string;
        label: string;
        status: string;
        fields: number;
    }[];
    statuses: { value: string; label: string }[];
    /** 通知先の欄が空のときに届く先（ダッシュボードの 4 歩目と同じ値・UX 一周 15 周目 G2） */
    fallbackNotifyTo?: string;
    canManage?: boolean;
    /**
     * 入力項目をこの画面の中で編集するための材料（R-8A ②）。
     *
     * `editable` が false のときは**他でも使われている項目**なので、
     * ここでは書き換えない（読み取りで見せる）。
     */
    fieldEditor?: {
        group: FieldGroupDetail;
        options: FieldGroupOptions;
        editable: boolean;
        /**
         * 共有している項目を、このフォームだけのものに切り離せるか（B-31）。
         *
         * `editable` の裏返しではない。**定義が読めない束は切り離せない**ので、
         * 「編集できない」と「切り離せる」は別々にサーバーが決める。
         */
        detachable: boolean;
    } | null;
}>();

/*
 * **`computed` にする。** 作成 → 編集は同じページコンポーネントなので、
 * Inertia は遷移してもインスタンスを作り直さない。ここを定数にすると、
 * 作ったあとの画面が「新規」のままになり、識別子が編集可能に見えてしまう。
 */
const isNew = computed(() => props.form === null);
const manageable = computed(() => props.canManage !== false);

// 「未設定」を空文字で持つと Select が値なしと区別できないので番兵を使う
const NONE = '__none__';

const form = useForm({
    key: props.form?.key ?? props.preset?.key ?? '',
    name: props.form?.name ?? props.preset?.name ?? '',
    description: props.form?.description ?? '',
    field_group_id: props.form?.field_group_id ?? NONE,
    status: props.form?.status ?? 'draft',
    confirm_step: props.form?.confirm_step ?? true,
    submit_label: props.form?.submit_label ?? '送信する',
    complete_message: props.form?.complete_message ?? '',
    notify_to: notifyLines(props.form?.notify_to),
    autoreply_field: props.form?.autoreply_field ?? NONE,
    autoreply_subject: props.form?.autoreply_subject ?? '',
    autoreply_body: props.form?.autoreply_body ?? '',
    autoreply_signature: props.form?.autoreply_signature ?? '',
    mail_from_name: props.form?.mail_from_name ?? '',
    reply_to: props.form?.reply_to ?? '',
    lock_version: props.form?.lock_version ?? 0,
});

/**
 * 新規のときだけ、名前から識別子の下書きを入れる（R-11 ①）。
 *
 * **人が触ったら二度と書き換えない。** 下書きを上書きし続けると、
 * せっかく直した URL が名前を直すたびに戻ってしまう。
 * 日本語の名前からは作れないので空のままになり、**保存時にサーバが通し番号へ落とす**。
 */
// 埋めてきた識別子は、名前を直しても書き換えない（触ったものとして扱う）
const keyTouched = ref(Boolean(props.preset?.key));

watch(
    () => form.name,
    (name) => {
        if (!isNew.value || keyTouched.value) {
            return;
        }

        form.key = slugifyFormKey(name);
    },
);

const emailFields = computed(() =>
    props.fields.filter((field) => field.type === 'email'),
);

// 同じ理由で、遷移してきた props を form へ流し込み直す
watch(
    () => props.form?.key,
    () => {
        form.defaults({
            key: props.form?.key ?? '',
            name: props.form?.name ?? '',
            description: props.form?.description ?? '',
            field_group_id: props.form?.field_group_id ?? NONE,
            status: props.form?.status ?? 'draft',
            confirm_step: props.form?.confirm_step ?? true,
            submit_label: props.form?.submit_label ?? '送信する',
            complete_message: props.form?.complete_message ?? '',
            notify_to: notifyLines(props.form?.notify_to),
            autoreply_field: props.form?.autoreply_field ?? NONE,
            autoreply_subject: props.form?.autoreply_subject ?? '',
            autoreply_body: props.form?.autoreply_body ?? '',
            autoreply_signature: props.form?.autoreply_signature ?? '',
            mail_from_name: props.form?.mail_from_name ?? '',
            reply_to: props.form?.reply_to ?? '',
            lock_version: props.form?.lock_version ?? 0,
        });
        form.reset();
    },
);

/*
 * ── 入力項目（R-8A ②） ───────────────────────────────
 *
 * 項目は Field Group が持つので、保存先はフォーム本体とは**別の操作**である。
 * だから**この区画だけの保存**を持たせる（`docs/BACKLOG.md` R-8A の判断 (R)）。
 * 1 つのボタンで両方を送ると、片方が確認待ちで止まったときに
 * **「フォーム名は保存された、項目はされていない」**という半端な状態が通常運転で起きる。
 */
/*
 * **`?.` で受ける。** 渡し忘れた画面では `undefined` が来るので、
 * `!== null` だけで守ると**その場で落ちて白い画面になる**（hotfix）。
 * 守り方を 1 つにして、「null は守るが undefined は守らない」を残さない。
 */
const fieldsEditable = computed(
    () => manageable.value && props.fieldEditor?.editable === true,
);

/** 入力項目の区画をこの画面に出すか（出すなら保存ボタンはその下に 1 つだけ置く） */
const showFieldsSection = computed(
    () => fieldsEditable.value && editorOptions.value !== null,
);

/*
 * ── 共有している項目の切り離し（B-31） ───────────────────
 *
 * **黙って複製しない**（オーナー判断 2026-08-24）。ここは要求を出すだけで、
 * 何が起きるか・何が変わらないかの文言はサーバーが返す。
 * 元の束と、それを指している他のフォームは 1 バイトも変わらない。
 */
const canDetachFields = computed(
    () => manageable.value && props.fieldEditor?.detachable === true,
);

const {
    confirmation: detachConfirmation,
    request: requestDetach,
    accept: acceptDetach,
    dismiss: dismissDetach,
} = useOperationConfirmation((token) => {
    router.post(
        `/admin/forms/${props.form?.key ?? ''}/fields/detach`,
        token ? { confirmation_token: token } : {},
        { preserveScroll: true },
    );
});

const editorOptions = computed<FieldGroupOptions | null>(
    () => props.fieldEditor?.options ?? null,
);

const fieldsForm = useForm<{ fields: FieldRow[] }>({
    fields: (props.fieldEditor?.group.fields ?? []).map(toFieldRow),
});

// 保存していない変更を、サイドバーなどで移って黙って捨てない（UX 一周 12 周目 R12-2）
useLeaveGuard(
    () => form.isDirty || (fieldsEditable.value && fieldsForm.isDirty),
);

const defaultFieldType = (): string =>
    editorOptions.value?.fieldTypes[0] ?? 'text';

// 1 つも無いと送っても必ず弾かれるので、空欄を 1 行置いておく
if (fieldsEditable.value && fieldsForm.fields.length === 0) {
    fieldsForm.fields = [emptyFieldRow(defaultFieldType())];
}

const totalFields = computed(() => countRows(fieldsForm.fields));

watch(
    () => props.fieldEditor?.group.id,
    () => {
        fieldsForm.defaults({
            fields: (props.fieldEditor?.group.fields ?? []).map(toFieldRow),
        });
        fieldsForm.reset();
    },
);

/** その行の要素（FieldRowEditor の外枠。`data-row-id` で引く） */
function rowElement(rowId: string | number): HTMLElement | null {
    return window.document.querySelector<HTMLElement>(
        `[data-row-id="${CSS.escape(String(rowId))}"]`,
    );
}

/** 行の中の、その行自身のボタン（入れ子の行のボタンより後ろにある） */
function ownControl(row: HTMLElement, testId: string): HTMLElement | null {
    const all = row.querySelectorAll<HTMLElement>(`[data-testid="${testId}"]`);

    return all.length > 0 ? all[all.length - 1]! : null;
}

/*
 * 足した欄へ連れて行く（UX 一周 15 周目 T8: 新しい欄は画面の外に足され、フォーカスはボタンに残っていた）。
 * 動きを減らす設定では、すべらせずに移る。
 */
async function addField(): Promise<void> {
    fieldsForm.fields = addRow(fieldsForm.fields, defaultFieldType());
    await nextTick();

    const added = fieldsForm.fields[fieldsForm.fields.length - 1];
    const row = added ? rowElement(added.rowId) : null;

    if (!row) {
        return;
    }

    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)')
        .matches;
    row.scrollIntoView({
        block: 'center',
        behavior: smooth ? 'smooth' : 'auto',
    });
    row.querySelector<HTMLElement>(
        '[data-testid="field-group-field-label"]',
    )?.focus({ preventScroll: true });
}

function updateField(index: number, row: FieldRow): void {
    fieldsForm.fields = updateRow(fieldsForm.fields, index, row);
}

function removeField(index: number): void {
    fieldsForm.fields = removeRow(fieldsForm.fields, index, defaultFieldType());
}

/*
 * 動かした行のボタンにフォーカスを残す（UX 一周 15 周目 T4: 「上へ」を押すとフォーカスが BODY に戻り、
 * 続けて押せなかった・キーボードの人は場所を見失った）。端まで来て押せなくなったら、反対向きのボタンへ。
 */
async function moveField(index: number, delta: number): Promise<void> {
    const rowId = fieldsForm.fields[index]?.rowId;
    fieldsForm.fields = moveRow(fieldsForm.fields, index, delta);

    if (!rowId) {
        return;
    }

    await nextTick();
    const row = rowElement(rowId);

    if (!row) {
        return;
    }

    const same = ownControl(
        row,
        delta < 0 ? 'field-group-move-field-up' : 'field-group-move-field-down',
    );
    const other = ownControl(
        row,
        delta < 0 ? 'field-group-move-field-down' : 'field-group-move-field-up',
    );
    const target = same && !(same as HTMLButtonElement).disabled ? same : other;
    target?.focus();
}

/**
 * 項目の保存。
 *
 * **束の識別子・表示名・割当先はそのまま返す。** この画面はそれらを見せないが、
 * 送らないと「消した」ことになる。利用者が触っていないものを、
 * 画面に出ていないという理由で捨てない。
 */
const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const group = props.fieldEditor?.group;

        if (!group) {
            return;
        }

        fieldsForm
            .transform((data) => ({
                key: group.key,
                label: group.label,
                locations: group.locations.map((location) => ({
                    kind: location.kind,
                    value: location.value,
                })),
                /*
                 * **キーは利用者に書かせない**（R-8A ③）。新しい行にだけ付ける —
                 * 保存済みの行に付け直すと、受信済みのデータが別の項目のものに見える。
                 */
                fields: assignFieldKeys(data.fields as FieldRow[]).map(
                    toPayload,
                ),
                /*
                 * **保存したらこの画面へ帰る**（監査 指摘 18）。
                 * 既定の戻り先はカスタムフィールドの一覧で、
                 * **利用者に見せないと決めた画面**である。
                 */
                redirect_to: `/admin/forms/${props.form?.key ?? ''}`,
                ...(token ? { confirmation_token: token } : {}),
            }))
            .patch(`/admin/field-groups/${group.id}`, {
                preserveScroll: true,
                preserveState: true,
            });
    },
);

function submitFields(): void {
    request();
}

/**
 * 確認ダイアログの文言を、この画面の言葉へ言い換える（監査 指摘 16）。
 *
 * **サーバーの契約は変えない。** 変えるのは見せ方だけである。
 * 消す操作の確認文は画面のどこよりも読まれるので、
 * ここに「カスタムフィールド」「束」が出ていては、他を言い換えても意味が無い。
 */
const shownConfirmation = computed(() =>
    customerConfirmation(confirmation.value, {
        /*
         * **消した項目も名前で呼ぶ**（監査 指摘 20）。
         * 消した行は画面から消えているので、現在の行だけを見ると
         * **一番説明が要る項目だけがキーのまま出る**。保存済み側も併せて引く。
         */
        ...labelsByKey(props.fieldEditor?.group.fields ?? []),
        /*
         * 🔴 **送るときと同じキーを付けてから引く**（5 周目 2026-09-03）。
         *
         * キーは送信の直前に `assignFieldKeys` が付ける。画面の行はそれまで
         * `key: ''` なので、そのまま渡すと `labelsByKey` が
         * **「キーが空」として飛ばす** — 結果、いま足したばかりの
         * **一番説明が要る項目**だけが `field_3` のまま確認に出ていた。
         *
         * `assignFieldKeys` は純粋な関数なので、送信側と必ず同じ答えになる。
         */
        ...labelsByKey(assignFieldKeys(fieldsForm.fields as FieldRow[])),
    }),
);

/**
 * 受信が 0 件で、項目がこのフォームにしか付いていない（UX 一周 3 周目 F2）。
 * 確認は出すが、赤いボタンにはしない。0 件であることを一言添える。
 */
const nothingStored = computed(() =>
    noStoredValues(props.form?.submissions, props.fieldEditor?.group.locations),
);

/** 入力項目の欄に添える一言。3 通りの言い分けは `lib/form-field-picker` が持つ */
const fieldPickerNoteText = computed(() =>
    !manageable.value
        ? '入力項目も読むだけで表示しています。'
        : fieldPickerNote({
              isNew: isNew.value,
              hasFieldEditor: Boolean(props.fieldEditor),
          }),
);

/**
 * **押し忘れを画面に出す。**
 *
 * 区画ごとに保存するので、片方を直して押さないまま離れられる。
 * 離脱を止める仕組みはこの管理画面に無いので、**気づける形**だけ置く。
 */
const unsavedNotice = computed<string | null>(() => {
    const parts: string[] = [];

    if (form.isDirty) {
        parts.push('フォームの設定');
    }

    if (fieldsEditable.value && fieldsForm.isDirty) {
        parts.push('入力項目');
    }

    return parts.length === 0
        ? null
        : `${parts.join(' と ')}に、まだ保存していない変更があります。`;
});

/**
 * 保存ボタンは 1 つ（UX 一周 3 周目 M10）。
 *
 * 以前は「フォームの設定を保存」と「入力項目を保存」の 2 つがあり、どちらを押せば何が保存されるのか
 * 迷われた。保存先が別の操作であることは変えない（片方が確認待ちで止まることがある）ので、
 * **順に送る**: 設定 → 項目。項目の確認ダイアログは最後に出るので、設定の保存が確認に巻き込まれない。
 */
function saveAll(): void {
    if (!manageable.value) {
        return;
    }

    const fieldsPending = fieldsEditable.value && fieldsForm.isDirty;

    if (isNew.value || !fieldsPending) {
        submit();

        return;
    }

    if (!form.isDirty) {
        submitFields();

        return;
    }

    submit(() => submitFields());
}

function submit(after: (() => void) | null = null) {
    const payload = form.transform((data) => ({
        ...data,
        field_group_id:
            data.field_group_id === NONE ? null : data.field_group_id,
        autoreply_field:
            data.autoreply_field === NONE ? null : data.autoreply_field,
    }));

    if (isNew.value) {
        payload.post('/admin/forms', { preserveScroll: true });

        return;
    }

    payload.patch(`/admin/forms/${props.form?.key}`, {
        preserveScroll: true,
        // 続けて項目も送るときは、画面の打ちかけ（項目の欄）を残す
        preserveState: after !== null,
        onSuccess: () => after?.(),
    });
}
</script>

<template>
    <Head :title="isNew ? '新しいフォーム' : form.name" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">
                {{ isNew ? '新しいフォーム' : form.name }}
            </h1>
            <Button variant="outline" as-child>
                <Link href="/admin/forms">一覧へ戻る</Link>
            </Button>
        </div>

        <p
            v-if="unsavedNotice"
            class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            data-testid="form-unsaved-notice"
        >
            {{ unsavedNotice }}
        </p>

        <form class="flex flex-col gap-6" @submit.prevent="saveAll">
            <fieldset
                :disabled="!manageable"
                class="flex min-w-0 flex-col gap-6"
            >
                <div class="flex flex-col gap-2">
                    <Label for="name">名前</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        data-testid="form-name"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="key">識別子</Label>
                    <Input
                        id="key"
                        v-model="form.key"
                        :disabled="!isNew"
                        data-testid="form-key"
                        @input="keyTouched = true"
                    />
                    <p class="text-xs text-muted-foreground">
                        公開 URL は
                        <code
                            >/forms/{{
                                form.key || '（保存時に決まります）'
                            }}</code
                        >
                        です。
                        <template v-if="isNew && form.key === ''"
                            >空のままなら、名前が英字でないときは
                            <code>/forms/form-1</code> のような番号の URL
                            になります（保存したあとは変えられません）。</template
                        >
                        <template v-if="isNew"
                            >識別子を <code>contact</code> にすると、<code
                                >/contact</code
                            >
                            でもこのフォームが表示されます。</template
                        >
                        <template v-if="!isNew"
                            >配ってしまった URL
                            が死ぬため、あとから変更できません。</template
                        >
                    </p>
                    <p v-if="form.errors.key" class="text-sm text-destructive">
                        {{ form.errors.key }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="description">説明</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="2"
                        class="min-h-16 rounded-md border bg-transparent px-3 py-2 text-sm"
                    ></textarea>
                </div>

                <!--
                項目をこの画面で編集できるときは、**どの束を使うかを選ばせない**（R-8A ②）。
                「束」は利用者に見せる概念ではないし、選び直せると
                自分のフォーム専用の項目を捨ててしまえる。
            -->
                <div v-if="!fieldsEditable" class="flex flex-col gap-2">
                    <Label for="field_group_id">入力項目</Label>
                    <Select v-model="form.field_group_id">
                        <SelectTrigger
                            id="field_group_id"
                            data-testid="form-field-group"
                        >
                            <SelectValue placeholder="選択してください" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">未設定</SelectItem>
                            <SelectItem
                                v-for="group in fieldGroups"
                                :key="group.id"
                                :value="group.id"
                            >
                                {{ group.label }}（{{ group.fields }} 項目）
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ fieldPickerNoteText }}
                    </p>
                    <p
                        v-if="props.form?.field_problem"
                        class="text-sm text-destructive"
                        data-testid="form-field-problem"
                    >
                        {{ props.form.field_problem }}
                    </p>
                    <p
                        v-if="form.errors.field_group_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.field_group_id }}
                    </p>
                </div>

                <div
                    v-if="!fieldsEditable && fields.length > 0"
                    class="rounded-lg border p-4"
                >
                    <h2 class="mb-2 text-sm font-medium">いま出る項目</h2>
                    <ul class="flex flex-wrap gap-2">
                        <li v-for="field in fields" :key="field.key">
                            <Badge variant="secondary"
                                >{{ field.label
                                }}<span v-if="field.required"> *</span></Badge
                            >
                        </li>
                    </ul>
                </div>

                <!--
                共有している項目を、このフォームだけのものにする（B-31）。

                新しく作ったフォームは最初から専用の束を持つので、この案内は出ない。
                **取り残されるのは「既に在るフォーム」だけ**なので、その場に出口を置く。
            -->
                <div
                    v-if="canDetachFields"
                    class="flex flex-col gap-2 rounded-lg border p-4"
                    data-testid="form-fields-detach-section"
                >
                    <h2 class="text-sm font-medium">
                        項目を、このフォームだけのものにする
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        いまの項目をそのまま写して、このフォーム専用にします。写したあとは、
                        この画面の中で項目を追加・変更できます。
                        <strong class="font-medium"
                            >ほかで使われているところは、いままでどおりのままです。</strong
                        >
                    </p>
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!manageable"
                            data-testid="form-fields-detach"
                            @click="requestDetach"
                        >
                            このフォームだけの項目にする
                        </Button>
                    </div>

                    <ConfirmationDialog
                        :confirmation="detachConfirmation"
                        confirm-label="複製する"
                        @confirm="acceptDetach"
                        @cancel="dismissDetach"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="status">状態</Label>
                    <Select v-model="form.status">
                        <SelectTrigger id="status" data-testid="form-status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        「下書き」は公開サイトから見えません。「受付終了」は画面は出ますが送信できません。
                    </p>
                    <p
                        v-if="form.errors.status"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.status }}
                    </p>
                    <!--
                    🔴 **公開できない理由は、状態の隣に必ず出す**（R-12 の実測 2026-08-30）。

                    この理由は `field_group_id` に紐づいて返るが、その表示枠は
                    「項目をこの画面で編集できないとき」だけ描かれる区画の中に在った。
                    自分の束を持つフォーム（管理画面で作った普通のフォーム）では
                    その区画ごと消えるので、**押しても何も起きない**ように見えていた。
                    どちらの構成でも読める場所は、状態のすぐ下である。
                -->
                    <p
                        v-if="form.errors.field_group_id"
                        class="text-sm text-destructive"
                        data-testid="form-publish-error"
                    >
                        {{ form.errors.field_group_id }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox
                        id="confirm_step"
                        v-model="form.confirm_step"
                        data-testid="form-confirm-step"
                    />
                    <Label for="confirm_step">確認画面を挟む</Label>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="submit_label">送信ボタンの文言</Label>
                    <Input id="submit_label" v-model="form.submit_label" />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="complete_message">完了画面の文言</Label>
                    <textarea
                        id="complete_message"
                        v-model="form.complete_message"
                        rows="2"
                        class="min-h-16 rounded-md border bg-transparent px-3 py-2 text-sm"
                    ></textarea>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="notify_to">通知先メールアドレス</Label>
                    <textarea
                        id="notify_to"
                        v-model="form.notify_to"
                        rows="3"
                        :placeholder="
                            fallbackNotifyTo
                                ? `空なら ${fallbackNotifyTo} に届きます`
                                : '空ならサイト設定の宛先を使います'
                        "
                        class="min-h-16 rounded-md border bg-transparent px-3 py-2 text-sm"
                    ></textarea>
                    <p class="text-xs text-muted-foreground">
                        1 行に 1 つ。複数書くと全員へ届きます。
                    </p>
                    <!-- 空のときに届く先を、ダッシュボードの 4 歩目と同じ値で出す（UX 一周 15 周目 G2） -->
                    <p
                        v-if="
                            fallbackNotifyTo &&
                            !String(form.notify_to ?? '').trim()
                        "
                        class="text-sm"
                        data-testid="form-notify-fallback"
                    >
                        いまは空なので、<strong>{{ fallbackNotifyTo }}</strong>
                        に届きます（サイト設定の「問い合わせ通知先メール」。そこも空なら、作ったときの管理者の宛先）。
                        <template v-if="fallbackNotifyTo.includes('example')">
                            これは見本の宛先で、実際には誰にも届きません。ここに受け取るアドレスを書いてください。
                        </template>
                    </p>
                    <p
                        v-if="form.errors.notify_to"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.notify_to }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="autoreply_field">自動返信の宛先</Label>
                    <Select v-model="form.autoreply_field">
                        <SelectTrigger
                            id="autoreply_field"
                            data-testid="form-autoreply-field"
                        >
                            <SelectValue placeholder="送らない" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">送らない</SelectItem>
                            <SelectItem
                                v-for="field in emailFields"
                                :key="field.key"
                                :value="field.key"
                            >
                                {{ field.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="emailFields.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        メールアドレスの項目がないため、自動返信は送れません。
                    </p>
                    <p
                        v-if="form.errors.autoreply_field"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.autoreply_field }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="autoreply_subject">自動返信の件名</Label>
                    <Input
                        id="autoreply_subject"
                        v-model="form.autoreply_subject"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="autoreply_body">自動返信の本文</Label>
                    <textarea
                        id="autoreply_body"
                        v-model="form.autoreply_body"
                        rows="5"
                        class="min-h-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                    ></textarea>
                    <p class="text-xs text-muted-foreground">
                        本文のあとに、届いた入力内容が自動で付きます。
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="autoreply_signature">自動返信の署名</Label>
                    <textarea
                        id="autoreply_signature"
                        v-model="form.autoreply_signature"
                        rows="4"
                        class="min-h-24 rounded-md border bg-transparent px-3 py-2 text-sm"
                    ></textarea>
                    <p class="text-xs text-muted-foreground">
                        メールのいちばん最後に付きます。空のままならサイト名だけが入ります。
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="mail_from_name">自動返信の差出人名</Label>
                    <Input
                        id="mail_from_name"
                        v-model="form.mail_from_name"
                        placeholder="空ならサイト全体の設定を使います"
                    />
                    <p class="text-xs text-muted-foreground">
                        メールアドレスは変わりません。変えると迷惑メールとして扱われやすくなるためです。
                    </p>
                    <p
                        v-if="form.errors.mail_from_name"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.mail_from_name }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="reply_to">自動返信への返信先</Label>
                    <Input
                        id="reply_to"
                        v-model="form.reply_to"
                        placeholder="空ならサイト全体の設定を使います"
                    />
                    <p class="text-xs text-muted-foreground">
                        受け取った人が返信したときの宛先です。1 つだけ書けます。
                    </p>
                    <p
                        v-if="form.errors.reply_to"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.reply_to }}
                    </p>
                </div>
            </fieldset>

            <div v-if="!showFieldsSection" class="flex items-center gap-3">
                <Button
                    v-if="manageable"
                    type="submit"
                    :disabled="form.processing"
                    data-testid="form-save"
                >
                    保存する
                </Button>
                <Button v-if="!isNew" variant="outline" as-child>
                    <Link :href="`/admin/forms/${props.form?.key}/submissions`"
                        >受信を見る（{{ props.form?.submissions }} 件）</Link
                    >
                </Button>
            </div>
        </form>

        <!--
            入力項目（R-8A ②）。**別の保存**を持つ（判断 (R)）。
            項目は Field Group が持っていて、保存先がフォーム本体とは別の操作だからである。
            1 つのボタンで両方送ると、片方が確認待ちで止まったときに
            「名前は保存された、項目はされていない」が通常運転で起きる。
        -->
        <form
            v-if="fieldsEditable && editorOptions"
            class="flex flex-col gap-4 rounded-lg border p-4"
            data-testid="form-fields-section"
            @submit.prevent="saveAll"
        >
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-medium">入力項目</h2>
                    <p class="text-xs text-muted-foreground">
                        訪問者に入力してもらう欄です。上から順に表示されます。
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="
                        !manageable ||
                        totalFields >= editorOptions.limits.fields
                    "
                    data-testid="form-add-field"
                    @click="addField"
                >
                    項目を追加
                </Button>
            </div>

            <FieldRowEditor
                v-for="(row, index) in fieldsForm.fields"
                :key="row.rowId"
                :data-row-id="row.rowId"
                :row="row"
                :options="editorOptions"
                :depth="1"
                :has-form="true"
                :path="`fields.${index}`"
                :errors="fieldsForm.errors as Record<string, string>"
                :can-move-up="index > 0"
                :can-move-down="index < fieldsForm.fields.length - 1"
                :at-field-limit="totalFields >= editorOptions.limits.fields"
                :show-key="false"
                @update="(r: FieldRow) => updateField(index, r)"
                @remove="removeField(index)"
                @move="(d: number) => moveField(index, d)"
            />

            <InputError :message="fieldsForm.errors.fields" />

            <ConfirmationDialog
                :confirmation="shownConfirmation"
                confirm-label="この内容で保存する"
                :tone="nothingStored ? 'default' : 'destructive'"
                :note="
                    nothingStored
                        ? 'このフォームの受信はまだ 0 件なので、影響を受けるデータはありません。'
                        : null
                "
                @confirm="accept"
                @cancel="dismiss"
            />
        </form>

        <!-- 保存ボタンは 1 つ。設定と入力項目を順に保存する（UX 一周 3 周目 M10） -->
        <div v-if="showFieldsSection" class="flex items-center gap-3">
            <Button
                type="button"
                :disabled="
                    !manageable || form.processing || fieldsForm.processing
                "
                data-testid="form-save"
                @click="saveAll"
            >
                保存する
            </Button>
            <Button variant="outline" as-child>
                <Link :href="`/admin/forms/${props.form?.key}/submissions`"
                    >受信を見る（{{ props.form?.submissions }} 件）</Link
                >
            </Button>
            <span class="text-xs text-muted-foreground">
                フォームの設定と入力項目を、まとめて保存します。
            </span>
        </div>
    </div>
</template>
