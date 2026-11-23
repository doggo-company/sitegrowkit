<script setup lang="ts">
// Schema 駆動の作成・編集フォーム（collection kind 共通）
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ExternalLink } from '@lucide/vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { contentState } from '@/lib/content-state';
import PublicTypeNotice from '@/components/PublicTypeNotice.vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import SchemaFieldInput from '@/components/schema/SchemaFieldInput.vue';
import { useAdminTimezone } from '@/composables/useAdminTimezone';
import { useAutosave } from '@/composables/useAutosave';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import { adminApi } from '@/lib/adminApi';
import { toDateOnly, toDateTimeLocal } from '@/lib/admin-datetime';
import SchemaTaxonomyInput from '@/components/schema/SchemaTaxonomyInput.vue';
import { Button } from '@/components/ui/button';
import type { AdminSchema, ContentItem } from '@/types/schema';
import type { AssignedTerm, TaxonomyOptionGroup } from '@/types/taxonomy';

const props = withDefaults(
    defineProps<{
        schema: AdminSchema;
        item: ContentItem | null;
        routeBase: string;
        /** 公開ページ URL。下書き等で公開できない場合は null（F-UX3） */
        publicUrl?: string | null;
        /** 投稿タイプ全体が非公開なら、記事だけ公開しても外には出ない。 */
        publicTypeDisabled?: boolean;
        /** プレビュー URL。公開ページを持たない型では null（Phase 9.15 / 28A） */
        previewUrl?: string | null;
        /**
         * 手で保存したあとに積まれた自動保存（Phase 9.15 / 28C）。
         * 無ければ null。**知らせるだけで、勝手には戻さない**
         */
        pendingAutosave?: {
            revision: number;
            created_at: string | null;
        } | null;
        /** 分類の選択候補（active のみ）。宣言が無い schema では空 */
        taxonomyOptions?: TaxonomyOptionGroup[];
    }>(),
    {
        publicUrl: null,
        publicTypeDisabled: false,
        previewUrl: null,
        pendingAutosave: null,
        taxonomyOptions: () => [],
    },
);

const timezone = useAdminTimezone();
const page = usePage();

const isEdit = computed(() => props.item !== null);

/** 分類は通常 field ではないので、値も `taxonomies` という別キーで運ぶ（§K.1） */
type TaxonomySelection = Record<string, string[]>;

/**
 * 入れ子の値（Phase 9.5-C）。
 *
 * `group` はオブジェクト、`repeater` は行の list、`relations` / `images` は ID の list。
 * scalar だけでは表せないので、再帰する JSON の形を型にする。
 */
type JsonScalar = string | number | boolean | null;

/**
 * 入れ子は **1 段だけ型で表す**。深さは server（ADR-0017 の上限 3）が守る。
 * 完全な再帰型にすると useForm の推論が発散する（TS2589）。
 */
type JsonNested =
    | JsonScalar
    | JsonScalar[]
    | Record<string, JsonScalar | JsonScalar[]>
    | Record<string, JsonScalar | JsonScalar[]>[];

type FormValue = JsonNested | File | TaxonomySelection;

const hasTaxonomies = computed(() => props.schema.taxonomies.length > 0);

/** 既に付与されている項目（archived を含む）。**黙って落とさない**（AS-11 / AS-12） */
function assignedTerms(key: string): AssignedTerm[] {
    return props.item?.taxonomies?.[key]?.terms ?? [];
}

/** 初期選択は read response のとおり。archived も選択済みのまま残す（AS-3 / AS-12） */
function initialSelection(): TaxonomySelection {
    return Object.fromEntries(
        props.schema.taxonomies.map((taxonomy) => [
            taxonomy.key,
            assignedTerms(taxonomy.key).map((term) => term.id),
        ]),
    );
}

function initialValue(key: string, type: string): FormValue {
    const raw = props.item?.[key];

    if (raw === null || raw === undefined) {
        // 新規記事は下書きから始める。保存するだけで公開されたり、状態未選択で止まったりしない。
        if (type === 'status' && !isEdit.value) {
            return 'draft';
        }

        if (type === 'boolean') {
            return false;
        }

        // 入れ子と複数値は **空でも形を保つ**（後から push できるようにする）
        if (type === 'group') {
            return {};
        }

        if (type === 'repeater' || type === 'relations' || type === 'images') {
            return [];
        }

        return type === 'media' || type === 'relation' ? null : '';
    }

    /*
     * 🔴 **欄に入れる値は、そのまま送り返される。**（WP 一周 5 周目 2026-09-03）
     *
     * ここは以前 `String(raw).slice(0, 16)` だった。サーバーは ISO8601（UTC）で
     * 流すので、`2026-09-03T10:29` が欄に入る。サーバーは時間帯を持たない文字を
     * **サーバーの時間帯の時刻**として読み直すため、**何も触らずに保存を押すだけで
     * 公開日時が 9 時間過去へ動く**。押すたびに繰り返し戻る。
     *
     * だから欄へはサーバーの時間帯で割った値を入れる。往復して動かないことは
     * `admin-datetime.test.ts` が固定している。
     */
    if (type === 'datetime') {
        return toDateTimeLocal(raw, timezone.value);
    }

    if (type === 'date') {
        return toDateOnly(raw, timezone.value);
    }

    return raw as FormValue;
}

const form = useForm<Record<string, FormValue>>(
    Object.fromEntries([
        ...props.schema.fields.map((f) => [f.key, initialValue(f.key, f.type)]),
        ...props.schema.fields
            .filter((f) => f.type === 'image')
            .map((f) => [`${f.key}_file`, null]),
        // 宣言が無い schema では **キーごと送らない**（= assignment に一切触れない）。
        // 既存フォームの挙動を変えないための境界（§K.2）
        ...(hasTaxonomies.value
            ? ([['taxonomies', initialSelection()]] as [string, FormValue][])
            : []),
    ]),
);

// 保存していない変更を、サイドバーなどで移って黙って捨てない（UX 一周 12 周目 R12-2）
useLeaveGuard(() => form.isDirty && !form.processing);


/** SchemaTaxonomyInput の v-model 用（型を絞って渡す） */
function selectionFor(key: string): string[] {
    const selection = form.taxonomies as TaxonomySelection | undefined;

    return selection?.[key] ?? [];
}

function setSelection(key: string, value: string[]) {
    const selection = form.taxonomies as TaxonomySelection | undefined;

    if (selection !== undefined) {
        selection[key] = value;
    }
}

/**
 * 空文字は null として送る（nullable バリデーションと揃える）。
 * `taxonomies` はオブジェクトなのでこの写像を素通りする。
 *
 * `taxonomies_managed` は **multipart 対策の印**。ファイル添付時の送信は FormData になり、
 * 空配列がキーごと消えてしまうため、「このフォームは分類を管理していた」ことだけを
 * scalar で伝える。宣言済み key の補完はサーバー側の adapter が行う。
 */
function payload(data: Record<string, FormValue>): Record<string, unknown> {
    const normalized: Record<string, unknown> = Object.fromEntries(
        Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]),
    );

    if (hasTaxonomies.value) {
        normalized.taxonomies_managed = '1';
    }

    return normalized;
}

function submit() {
    if (isEdit.value) {
        // ファイルを含むため常に POST + _method=put（multipart PUT は PHP が解釈しない）
        form.transform((data) => ({ ...payload(data), _method: 'put' })).post(
            `/admin/${props.routeBase}/${props.item!.id}`,
        );
    } else {
        form.transform(payload).post(`/admin/${props.routeBase}`);
    }
}

/**
 * 自動保存（Phase 9.15 / STAGE 28B・28C・SPEC §C.1）。
 *
 * **いつ送るかの判断は `useAutosave` が持つ。** ここは「送る中身」と
 * 「いま送ってよいか」だけを渡す。判断を画面に置くと、
 * **開かないと確かめられない**ものになる。
 */
const autosave = useAutosave({
    enabled: () => isEdit.value && !form.processing,
    save: async () => {
        const result = await adminApi.send(
            'POST',
            `/admin/${props.routeBase}/${props.item!.id}/autosave`,
            payload(form.data()),
        );

        return result.status === 'success';
    },
});

watch(
    () => form.data(),
    () => autosave.markDirty(),
    { deep: true },
);

/**
 * 自動保存からの復帰（Phase 9.15 / STAGE 28C・SPEC §C.3）。
 *
 * **勝手に復元しない。** 開いた瞬間に中身が変わるのは、編集する人にとって
 * **事故と区別が付かない**。
 *
 * 「無視する」は**版を消さない** — 次に開いたときも出る。
 * 消えるのは間引きのときだけである。
 */
const noticeDismissed = ref(false);

const showAutosaveNotice = computed(
    () => props.pendingAutosave !== null && !noticeDismissed.value,
);

/**
 * **復元は確認を通す。**
 *
 * `content.revision.restore` は `requireAlways()` なので、**必ず確認を求める**。
 * token を付けずに送ると何も起きないまま静かに戻ってくる
 * （e2e で実際にそうなっていた）。既存の履歴画面と同じ経路にする。
 */
const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        router.post(
            `/admin/${props.routeBase}/${props.item!.id}/revisions/${props.pendingAutosave!.revision}/restore`,
            {
                ...(token ? { confirmation_token: token } : {}),
                lock_version:
                    typeof props.item?.lock_version === 'number'
                        ? props.item.lock_version
                        : null,
            },
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

function restoreAutosave() {
    request();
}

// 複製は保存済みの内容が対象。未保存の入力を置き去りにしない。
const duplicating = ref(false);
const duplicateError = ref('');
const canDuplicate = computed(
    () =>
        isEdit.value &&
        props.schema.fields.some((field) => field.type === 'status') &&
        ((
            page.props.auth as { capabilities?: string[] } | undefined
        )?.capabilities?.includes('content.write') ??
            false),
);
function duplicateSaved() {
    if (!canDuplicate.value || form.isDirty || duplicating.value) {
        return;
    }

    duplicating.value = true;
    duplicateError.value = '';
    router.post(
        `/admin/${props.routeBase}/${props.item!.id}/duplicate`,
        {},
        {
            preserveState: 'errors',
            onError: (errors) => {
                duplicateError.value = Object.values(errors).join(' ');
            },
            onFinish: () => {
                duplicating.value = false;
            },
        },
    );
}

const mainFields = computed(() =>
    props.schema.fields.filter((f) => f.group === null),
);
// 本文のある投稿では、執筆と公開設定を分けてPCの幅を活かす。
// 独自の入力項目も省略せず、スマホでは本文から順に1列で表示する。
const articleLayout = computed(() =>
    mainFields.value.some((field) => field.type === 'richtext'),
);
const settingsKeys = [
    'status',
    'published_at',
    'slug',
    'main_image_id',
    'excerpt',
];
const fieldSections = computed(() => {
    if (!articleLayout.value) {
        return [{ key: 'all', fields: mainFields.value, settings: true }];
    }

    const writing = mainFields.value.filter(
        (field) => !settingsKeys.includes(field.key),
    );
    const leading = writing.filter(
        (field) => field.key === 'title' || field.type === 'richtext',
    );
    const rest = writing.filter((field) => !leading.includes(field));

    return [
        { key: 'writing', fields: [...leading, ...rest], settings: false },
        {
            key: 'settings',
            fields: settingsKeys.flatMap((key) =>
                mainFields.value.filter((field) => field.key === key),
            ),
            settings: true,
        },
    ];
});
const seoFields = computed(() =>
    props.schema.fields.filter((f) => f.group === 'seo'),
);

/**
 * 1 行の欄で Enter を押しても保存しない（UX 一周 10 周目 R10-1: タイトルを打って Enter を押すと、
 * 本文を書く前に下書きとして保存されて一覧へ戻った）。保存は「保存」ボタンで。
 * 日本語入力の変換を確定する Enter（isComposing）はもともと送らないので触らない。
 */
function ignoreEnterInField(event: KeyboardEvent): void {
    const target = event.target;

    if (
        target instanceof HTMLInputElement &&
        !['submit', 'button', 'checkbox', 'radio'].includes(target.type)
    ) {
        event.preventDefault();
    }
}
</script>

<template>
    <Head :title="`${schema.label} — ${isEdit ? '編集' : '新規作成'}`" />

    <div
        class="flex w-full flex-col gap-6 p-4"
        :class="articleLayout ? 'max-w-7xl' : 'max-w-2xl'"
    >
        <PublicTypeNotice
            v-if="publicTypeDisabled"
            :schema-id="schema.id"
            :label="schema.label"
        />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">
                {{ schema.label }}{{ isEdit ? 'を編集' : 'を新規作成' }}
            </h1>
            <Button
                v-if="canDuplicate"
                type="button"
                variant="outline"
                size="sm"
                :disabled="form.isDirty || duplicating"
                :title="
                    form.isDirty
                        ? '変更を保存してから複製してください'
                        : '保存済みの内容を新しい下書きへコピーします'
                "
                @click="duplicateSaved"
                >{{ duplicating ? '複製中…' : '下書きへ複製' }}</Button
            >
            <!-- 保存済みの内容を公開と同じ見た目で確かめる（Phase 9.15 / 28A）。
                 公開前でも押せる。描く相手がいない型では previewUrl が null -->
            <Button v-if="previewUrl" variant="outline" size="sm" as-child>
                <a
                    :href="previewUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="form-preview-link"
                >
                    プレビュー
                    <ExternalLink class="size-3.5" aria-hidden="true" />
                    <span class="sr-only">（新しいタブで開きます）</span>
                </a>
            </Button>
            <!-- 公開ページを持つときだけ出す（無効なリンクを出さない） -->
            <Button v-if="publicUrl" variant="outline" size="sm" as-child>
                <a
                    :href="publicUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="form-public-link"
                >
                    公開ページを見る
                    <ExternalLink class="size-3.5" aria-hidden="true" />
                    <span class="sr-only">（新しいタブで開きます）</span>
                </a>
            </Button>
        </div>

        <p v-if="duplicateError" role="alert" class="text-sm text-red-700">
            複製できませんでした: {{ duplicateError }}
        </p>
        <!-- 手で保存したあとの自動保存があることを知らせる。**戻すかどうかは人が決める** -->
        <div
            v-if="showAutosaveNotice"
            class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-800 dark:bg-amber-950"
            data-testid="autosave-notice"
        >
            <p>
                保存していない自動保存があります（版
                {{ pendingAutosave!.revision }}）。復元すると、いまの内容は
                履歴に残したうえで置き換わります。
            </p>
            <div class="flex gap-2">
                <Button size="sm" type="button" @click="restoreAutosave">
                    復元する
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    type="button"
                    @click="noticeDismissed = true"
                >
                    無視する
                </Button>
            </div>
        </div>

        <form
            class="grid items-start gap-6"
            :class="articleLayout ? 'xl:grid-cols-[minmax(0,1fr)_300px]' : ''"
            @submit.prevent="submit"
            @keydown.enter="ignoreEnterInField"
        >
            <section
                v-for="section in fieldSections"
                :key="section.key"
                class="flex min-w-0 flex-col gap-5"
                :class="
                    articleLayout && section.settings
                        ? 'rounded-xl border bg-muted/20 p-4'
                        : ''
                "
                :aria-label="
                    articleLayout
                        ? section.settings
                            ? '公開と表示の設定'
                            : '記事の内容'
                        : '入力内容'
                "
            >
                <div
                    v-if="articleLayout && section.settings"
                    class="flex flex-wrap items-center justify-between gap-3"
                >
                    <h2 class="text-sm font-semibold">公開と表示の設定</h2>
                    <Button type="submit" :disabled="form.processing"
                        >保存</Button
                    >
                </div>
                <!-- 公開済み記事のURLを変える前に、旧URLを控えられるよう案内する。 -->
                <p
                    v-if="
                        section.settings &&
                        isEdit &&
                        publicUrl &&
                        schema.fields.some((field) => field.key === 'slug')
                    "
                    class="text-xs leading-relaxed text-muted-foreground"
                    data-testid="article-url-change-notice"
                >
                    URLが変わる場合、記事単体の変更では自動転送されません。
                    変更前のURL：<code class="break-all">{{ publicUrl }}</code
                    >。
                    旧URLを控え、保存後に「リダイレクト」で新URLへの転送を設定してください。
                    権限がない場合はサイト管理者に依頼してください。
                </p>
                <SchemaFieldInput
                    v-for="field in section.fields"
                    :key="field.key"
                    :field="field"
                    v-model="form[field.key]"
                    v-model:file="form[`${field.key}_file`] as File | null"
                    :error="
                        form.errors[field.key] ??
                        form.errors[`${field.key}_file`]
                    "
                    :errors="form.errors"
                    :current-image="
                        field.type === 'image'
                            ? ((item?.[field.key] as string) ?? null)
                            : null
                    "
                />

                <!-- 予約の言葉と、いつ出るか（7 周目 R7-3: 「予約」という言葉も説明も無かった） -->
                <p
                    v-if="
                        section.settings &&
                        schema.fields.some((f) => f.key === 'published_at') &&
                        schema.fields.some((f) => f.type === 'status')
                    "
                    class="text-xs text-muted-foreground"
                    data-testid="schedule-help"
                >
                    <template
                        v-if="
                            contentState(form.status, form.published_at) ===
                            'scheduled'
                        "
                        >予約: 公開日時（{{
                            String(form.published_at).replace('T', ' ')
                        }}）になるまで、公開サイトには出ません。</template
                    >
                    <template v-else
                        >公開日時を先の日時にして「公開」で保存すると、その日時まで公開サイトに出ない「予約」になります。</template
                    >
                </p>

                <!--
                分類は field ではないので **別ブロック**として出す（§J.1）。
                宣言が無い schema では 1 つも描画されない（既存フォームは非回帰）
            -->
                <template v-if="section.settings">
                    <SchemaTaxonomyInput
                        v-for="group in taxonomyOptions"
                        :key="group.key"
                        :group="group"
                        :assigned="assignedTerms(group.key)"
                        :model-value="selectionFor(group.key)"
                        :error="
                            form.errors[`taxonomies.${group.key}`] ??
                            form.errors.taxonomies
                        "
                        @update:model-value="setSelection(group.key, $event)"
                    />

                    <details
                        v-if="seoFields.length"
                        class="rounded-xl border p-4"
                    >
                        <summary class="cursor-pointer text-sm font-medium">
                            SEO 設定
                        </summary>
                        <div class="mt-4 flex flex-col gap-5">
                            <SchemaFieldInput
                                v-for="field in seoFields"
                                :key="field.key"
                                :field="field"
                                v-model="form[field.key]"
                                :error="form.errors[field.key]"
                                :errors="form.errors"
                            />
                        </div>
                    </details>

                    <div class="flex gap-3">
                        <Button
                            v-if="!articleLayout"
                            type="submit"
                            :disabled="form.processing"
                            >保存</Button
                        >
                        <Button variant="outline" as-child>
                            <Link :href="`/admin/${routeBase}`"
                                >キャンセル</Link
                            >
                        </Button>
                    </div>
                </template>
            </section>
        </form>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="自動保存の内容へ戻す"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>
