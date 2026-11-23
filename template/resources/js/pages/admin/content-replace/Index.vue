<script setup lang="ts">
/**
 * 本文の一括置換（P1 ⑧ / GUI 化・2026-09-10）。**Owner だけの画面。**
 *
 * ## 予告 → 確認 → 実行 → 結果
 *
 * 1. 予告（`/preview`）: **1 文字も変えない。** 件数と最大 10 件の before / after を出す
 * 2. 確認: サーバーが返した文面（`ConfirmationDialog`）を見せる。token は予告に付いてくる
 * 3. 実行（`/apply`）: 予告の token を `X-Confirmation` で返す。**予告と実行の間に
 *    対象が増えた・入れ替わった・本文が変わった**なら、サーバーが再確認を返す
 * 4. 結果: 何件変わったか
 *
 * ## 入力を直したら予告は捨てる
 *
 * token は type / field / from / to / 対象行 / 元の値に縛られている。どれかを直したら
 * 古い予告は**画面でも無効**にする（サーバーも弾くが、画面が「押せる」と言わない）。
 *
 * ## 本文は必ずエスケープして出す
 *
 * 抜粋は richtext の HTML を含み得る。`{{ }}` だけで出し、`v-html` は使わない。
 *
 * ## これは正規表現の機能ではない
 *
 * 文字どおりの置換だけ。そう名乗る。
 */
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { adminApi } from '@/lib/adminApi';
import type { OperationConfirmation } from '@/types/operation';

interface TargetField {
    key: string;
    label: string;
    type: string;
}

interface Target {
    id: string;
    label: string;
    fields: TargetField[];
}

interface BackupStatus {
    ok: boolean;
    reason: 'missing' | 'stale' | 'unreadable' | 'broken' | null;
    problems: string[];
    url: string;
}

interface Sample {
    id: number;
    title: string | null;
    before: string;
    after: string;
    occurrences: number;
}

/** サーバーの `ReplaceContent::previewData()` と同じ形 */
interface Preview {
    matched: number;
    total: number;
    samples: Sample[];
    confirmation: OperationConfirmation | null;
}

interface Applied {
    changed: number;
    matched: number;
    total: number;
}

const props = defineProps<{
    targets: Target[];
    backup: BackupStatus;
    maxRows: number;
    sampleLimit: number;
}>();

const form = ref({
    type: props.targets[0]?.id ?? '',
    field: props.targets[0]?.fields[0]?.key ?? '',
    from: '',
    to: '',
});

const fields = computed<TargetField[]>(
    () => props.targets.find((t) => t.id === form.value.type)?.fields ?? [],
);

const preview = ref<Preview | null>(null);
const applied = ref<Applied | null>(null);
const busy = ref<'preview' | 'apply' | null>(null);
const error = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
/** 予告のあとに対象が変わって、確認をやり直したとき */
const reconfirm = ref(false);
const confirmation = ref<OperationConfirmation | null>(null);

const backupMessage = computed<string>(() => {
    switch (props.backup.reason) {
        case 'missing':
            return 'backup がありません。置き換える前に backup を取ってください。';
        case 'stale':
            return '24 時間以内の backup がありません。いまの状態から取り直してください。';
        case 'unreadable':
            return 'backup の取得時刻が読めません。取り直してください。';
        case 'broken':
            return '復元できる backup がありません。取り直してください。';
        default:
            return '';
    }
});

const canPreview = computed(
    () =>
        props.backup.ok &&
        form.value.type !== '' &&
        form.value.field !== '' &&
        form.value.from !== '' &&
        busy.value === null,
);

/** 型を変えたら、その型の最初の項目へ */
watch(
    () => form.value.type,
    () => {
        if (!fields.value.some((f) => f.key === form.value.field)) {
            form.value.field = fields.value[0]?.key ?? '';
        }
    },
);

/** 入力を直したら、古い予告と token は捨てる（画面でも無効にする） */
watch(
    form,
    () => {
        preview.value = null;
        applied.value = null;
        reconfirm.value = false;
        fieldErrors.value = {};
    },
    { deep: true },
);

function fail(message: string | null, fallback: string): void {
    error.value = message ?? fallback;
}

async function runPreview(): Promise<void> {
    busy.value = 'preview';
    error.value = '';
    fieldErrors.value = {};
    applied.value = null;

    const result = await adminApi.send<Preview>(
        'POST',
        '/admin/content-replace/preview',
        { ...form.value },
    );

    busy.value = null;

    switch (result.status) {
        case 'success':
            preview.value = result.data;

            return;
        case 'invalid':
            fieldErrors.value = result.errors;
            fail(result.message, '入力を確認してください。');

            return;
        case 'conflict':
            fail(result.message, '予告を出せませんでした。');

            return;
        case 'confirmation':
            // 予告は確認を返さない設計。来たら想定外として止める
            fail(null, '予告の応答が想定と違います。画面を開き直してください。');

            return;
        case 'unreachable':
            fail(null, '通信できませんでした。接続を確認してください。');

            return;
        default:
            fail(result.message, '予告を出せませんでした。');
    }
}

/** 「置き換える」を押した。**サーバーが出した文面**で確認を開く */
function askConfirmation(): void {
    if (preview.value?.confirmation) {
        confirmation.value = preview.value.confirmation;
    }
}

async function runApply(): Promise<void> {
    const token = confirmation.value?.token ?? null;
    confirmation.value = null;

    if (!token || !preview.value) {
        return;
    }

    busy.value = 'apply';
    error.value = '';
    reconfirm.value = false;

    const result = await adminApi.send<Applied>(
        'POST',
        '/admin/content-replace/apply',
        { ...form.value },
        { confirmation: token },
    );

    busy.value = null;

    switch (result.status) {
        case 'success':
            applied.value = result.data;
            preview.value = null;

            return;
        case 'confirmation':
            /*
             * 予告と実行の間に対象が変わった（件数・行・本文・版のどれか）。
             * 古い予告は捨て、**いまの対象で予告をやり直してから**もう一度確認する。
             */
            preview.value = null;
            reconfirm.value = true;
            await runPreview();

            return;
        case 'conflict':
            preview.value = null;
            fail(result.message, '置き換えを取り消しました。予告からやり直してください。');

            return;
        case 'invalid':
            fieldErrors.value = result.errors;
            fail(result.message, '入力を確認してください。');

            return;
        case 'unreachable':
            fail(
                null,
                '通信できませんでした。置き換わったかどうかは、一覧か操作履歴で確認してください。',
            );

            return;
        default:
            fail(result.message, '置き換えられませんでした。');
    }
}
</script>

<template>
    <Head title="一括置換" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">一括置換</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                記事の文章の中の文字列を、文字どおりに探して別の文字列へ置き換えます。正規表現は使えません。
                まず対象と前後の抜粋を確認し、内容を見てから実行します。
            </p>
        </div>

        <!-- backup が無ければ、始めさせない -->
        <div
            v-if="!backup.ok"
            role="alert"
            data-testid="replace-backup-blocker"
            class="rounded-lg border border-destructive p-4 text-sm"
        >
            <p class="font-medium text-destructive">{{ backupMessage }}</p>
            <ul
                v-if="backup.problems.length > 0"
                class="mt-2 list-disc space-y-1 pl-5 text-muted-foreground"
            >
                <li v-for="(problem, i) in backup.problems" :key="i">
                    {{ problem }}
                </li>
            </ul>
            <p class="mt-3">
                <a
                    :href="backup.url"
                    class="text-primary underline underline-offset-4"
                    >バックアップ画面へ</a
                >
            </p>
        </div>

        <section
            class="space-y-4 rounded-xl border p-5"
            aria-labelledby="replace-form-heading"
        >
            <h2 id="replace-form-heading" class="font-semibold">条件</h2>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="replace-type">投稿タイプ</Label>
                    <select
                        id="replace-type"
                        v-model="form.type"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="targets.length === 0"
                    >
                        <option
                            v-for="target in targets"
                            :key="target.id"
                            :value="target.id"
                        >
                            {{ target.label }}
                        </option>
                    </select>
                    <p
                        v-if="fieldErrors.type"
                        class="text-xs text-destructive"
                    >
                        {{ fieldErrors.type[0] }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="replace-field">項目</Label>
                    <select
                        id="replace-field"
                        v-model="form.field"
                        class="h-9 rounded-md border px-2 text-sm"
                        :disabled="fields.length === 0"
                    >
                        <option
                            v-for="field in fields"
                            :key="field.key"
                            :value="field.key"
                        >
                            {{ field.label }}
                        </option>
                    </select>
                    <p
                        v-if="fieldErrors.field"
                        class="text-xs text-destructive"
                    >
                        {{ fieldErrors.field[0] }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="replace-from">置き換える前の文字列</Label>
                    <Input
                        id="replace-from"
                        v-model="form.from"
                        data-testid="replace-from"
                        autocomplete="off"
                    />
                    <p
                        v-if="fieldErrors.from"
                        class="text-xs text-destructive"
                    >
                        {{ fieldErrors.from[0] }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="replace-to">置き換えたあとの文字列</Label>
                    <Input
                        id="replace-to"
                        v-model="form.to"
                        data-testid="replace-to"
                        autocomplete="off"
                    />
                    <p class="text-xs text-muted-foreground">
                        空にすると、見つかった文字列を消します（必須項目が空になる場合は保存できません）。
                    </p>
                    <p
                        v-if="fieldErrors.to"
                        class="text-xs text-destructive"
                    >
                        {{ fieldErrors.to[0] }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    data-testid="replace-preview-button"
                    :disabled="!canPreview"
                    @click="runPreview"
                >
                    {{
                        busy === 'preview'
                            ? '探しています…'
                            : '対象を確認する'
                    }}
                </Button>
                <p class="text-xs text-muted-foreground">
                    1 回に置き換えられるのは {{ maxRows }} 件までです。
                </p>
            </div>
        </section>

        <p
            v-if="error"
            role="alert"
            data-testid="replace-error"
            class="rounded-lg border border-destructive p-4 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <p
            v-if="reconfirm"
            role="status"
            data-testid="replace-reconfirm"
            class="rounded-lg border p-4 text-sm"
        >
            予告のあとに対象が変わったため、置き換えていません。いまの対象を確認してから、もう一度実行してください。
        </p>

        <section
            v-if="preview"
            aria-live="polite"
            data-testid="replace-preview"
            class="space-y-4 rounded-xl border p-5"
        >
            <h2 class="font-semibold">
                <span data-testid="replace-matched">{{ preview.matched }}</span>
                件が対象です
            </h2>

            <p v-if="preview.matched === 0" class="text-sm text-muted-foreground">
                この文字列を含む記事はありません。
            </p>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    先頭 {{ Math.min(preview.samples.length, sampleLimit) }} 件の前後を示します。
                    HTML の項目は保存時に安全化されるため、表示と保存結果が一部異なることがあります。
                </p>

                <!-- 本文は {{ }} だけで出す（v-html は使わない） -->
                <ul class="space-y-3">
                    <li
                        v-for="sample in preview.samples"
                        :key="sample.id"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <p class="mb-2 font-medium">
                            #{{ sample.id }}
                            <span v-if="sample.title" class="ml-1">{{ sample.title }}</span>
                            <span class="ml-2 text-xs text-muted-foreground"
                                >{{ sample.occurrences }} 箇所</span
                            >
                        </p>
                        <dl class="grid gap-2 md:grid-cols-2">
                            <div>
                                <dt class="text-xs text-muted-foreground">前</dt>
                                <dd class="break-words whitespace-pre-wrap font-mono text-xs">{{ sample.before }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">後</dt>
                                <dd class="break-words whitespace-pre-wrap font-mono text-xs">{{ sample.after }}</dd>
                            </div>
                        </dl>
                    </li>
                </ul>

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        variant="destructive"
                        data-testid="replace-apply-button"
                        :disabled="busy !== null || !preview.confirmation"
                        @click="askConfirmation"
                    >
                        {{
                            busy === 'apply'
                                ? '置き換えています…'
                                : `${preview.matched} 件を置き換える`
                        }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        元の文字列は残りません。版と backup からのみ戻せます。
                    </p>
                </div>
            </template>
        </section>

        <section
            v-if="applied"
            aria-live="polite"
            data-testid="replace-result"
            class="space-y-2 rounded-xl border p-5"
        >
            <h2 class="font-semibold">
                <span data-testid="replace-changed">{{ applied.changed }}</span>
                件を置き換えました
            </h2>
            <p class="text-sm text-muted-foreground">
                置き換える前と後の内容は各記事の版に残っています。開いたままの編集画面は、保存時に競合として止まります。
            </p>
        </section>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="置き換える"
            @confirm="runApply"
            @cancel="confirmation = null"
        />
    </div>
</template>
