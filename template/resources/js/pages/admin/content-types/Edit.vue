<script setup lang="ts">
/**
 * 投稿タイプの設定 — Phase 9.7 / STAGE 20C。
 *
 * **ここで変えられるのは「見え方」と「どこに出すか」だけ**である。
 * 内部 ID・保存先テーブル・項目の追加や削除はこの画面から触れない。
 *
 * - 内部 ID はテーブル名・クラス名・管理画面 URL の導出元なので**変えられない**
 * - 項目（カスタムフィールド）は **Field Group の仕事**。ここに 2 つ目の項目編集 UI を作らない
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref, useTemplateRef, watch } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import SaveErrorSummary from '@/components/SaveErrorSummary.vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type {
    ContentTypeEditable,
    ContentTypeEditOptions,
} from '@/types/content-type';

const props = defineProps<{
    contentType: ContentTypeEditable;
    options: ContentTypeEditOptions;
}>();

const settings = props.contentType.settings;

function text(key: string): string {
    const value = settings.displayOverrides[key];

    return typeof value === 'string' ? value : '';
}

function choice(key: string, fallback: string): string {
    const value = settings.registration[key];

    return typeof value === 'string' && value !== '' ? value : fallback;
}

function flag(key: string, fallback = false): boolean {
    const value = settings.registration[key];

    return typeof value === 'boolean' ? value : fallback;
}

const form = useForm({
    lock_version: settings.lockVersion,
    display_overrides: {
        label: text('label'),
        plural_label: text('plural_label'),
        description: text('description'),
        icon: text('icon'),
        show_in_menu: settings.displayOverrides.show_in_menu !== false,
    } as Record<string, string | boolean>,
    registration: {
        public: flag('public'),
        publicly_queryable: flag('publicly_queryable'),
        has_archive: flag('has_archive'),
        exclude_from_search: flag('exclude_from_search'),
        api_enabled: flag('api_enabled'),
        // URL の構造（P1 ③）。書かなければ既定（入口 + slug）
        permalink: choice('permalink', 'base_slug'),
    } as Record<string, string | boolean>,
    public_slug: settings.publicSlug ?? '',
});

/*
 * 「公開サイトに出す」を入れたとき、公開スラッグが空なら薄く見えている `blog` を入れる
 * （UX 一周 2026-09-28）。欄に `blog` が見えているのに空扱いで
 * 「公開スラッグが必要です」になり、何を直せばよいか分からなかった。
 */
watch(
    () => form.registration.public,
    (on) => {
        if (on === true && form.public_slug.trim() === '') {
            form.public_slug = 'blog';
        }
    },
);

const errorSummary =
    useTemplateRef<InstanceType<typeof SaveErrorSummary>>('errorSummary');

/** 保存できなかったときに名指しする欄（入力欄の id と揃える） */
const errorFields = [
    { key: 'public_slug', label: '公開スラッグ', inputId: 'public-slug' },
    { key: 'registration', label: '公開' },
    { key: 'display_overrides', label: '表示' },
];

const registrationLabels: Record<string, string> = {
    public: '公開サイトに出す',
    publicly_queryable: '個別ページの URL を持つ',
    has_archive: '一覧ページの URL を持つ',
    exclude_from_search: 'サイト内検索の対象から外す',
    api_enabled: 'API から読めるようにする',
};

const registrationHelp: Record<string, string> = {
    public: '公開スラッグが必要です。無効にした投稿タイプは公開されません。',
    publicly_queryable: '例: /blog/hello',
    has_archive: '例: /blog',
    exclude_from_search: '公開サイトの検索結果に出さなくなります。',
    api_enabled: '管理画面からの読み書きには影響しません。',
};

/**
 * コメントの利用可否（Phase 9.7 / STAGE 20E）。
 *
 * **保存ボタンのついでに切り替わってはいけない。** 切り替えは別の operation で、
 * 必ず確認を通る。
 *
 * この節は表示・登録の `<form>` の**中**に置いてあるので、
 * 切り替えボタンには **`type="button"` が要る**。共通の `Button` は
 * `as="button"` を渡すだけで `type` を与えないため、付け忘れると HTML 既定の
 * `submit` になり、**1 クリックで「コメントの確認要求」と「設定の保存」が同時に飛ぶ**。
 */
const pendingComments = ref<boolean | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const enabled = pendingComments.value;

        if (enabled === null) {
            return;
        }

        router.post(
            `/admin/content-types/${props.contentType.id}/comments`,
            {
                comments_enabled: enabled,
                lock_version: settings.lockVersion,
                ...(token ? { confirmation_token: token } : {}),
            },
            {
                preserveScroll: true,
                // 1 回目の往復も成功した visit なので、**token を付けたときだけ**片付ける
                preserveState: true,
                onSuccess: () => {
                    if (token !== null) {
                        pendingComments.value = null;
                    }
                },
            },
        );
    },
);

function toggleComments(enabled: boolean): void {
    pendingComments.value = enabled;
    request();
}

function cancelComments(): void {
    pendingComments.value = null;
    dismiss();
}

function submit(): void {
    /*
     * 空文字は「未設定」として送る（保存された空文字を作らない）。
     * サーバー側の allowlist が最終判定を持つので、ここでは形だけ整える。
     */
    form.transform((data) => ({
        ...data,
        display_overrides: Object.fromEntries(
            Object.entries(data.display_overrides).filter(
                ([, value]) => value !== '',
            ),
        ),
        public_slug: data.public_slug === '' ? null : data.public_slug,
    })).patch(`/admin/content-types/${props.contentType.id}`, {
        preserveScroll: true,
        // 失敗したら、保存ボタンの横で言い、直す欄へ移る（サイト設定と同じ）
        onError: () => nextTick(() => errorSummary.value?.focusFirst()),
    });
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="`${contentType.label} の設定`" />

    <form class="flex flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">
                {{ contentType.label }} の設定
            </h1>
            <p class="text-sm text-muted-foreground">
                表示のしかたと、公開サイトへの出しかたを変えられます。項目の追加・変更はカスタムフィールドで行います。
            </p>
        </div>

        <!-- 変えられないもの。理由まで書く（「なぜ編集できないのか」を画面で説明する） -->
        <section class="rounded-xl border p-4">
            <h2 class="text-sm font-semibold">変えられないもの</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">内部 ID</dt>
                    <dd class="font-mono" data-testid="content-type-id">
                        {{ contentType.id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">保存先テーブル</dt>
                    <dd class="font-mono">{{ contentType.table }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">
                内部 ID は保存先テーブル・管理画面の
                URL・内部のクラス名をすべて決めています。変えると既存の投稿の所在が分からなくなるため、変更できません。名前を変えたいときは、公開スラッグか表示名を変えてください。
            </p>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border p-4">
            <h2 class="text-sm font-semibold">表示</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="display-label">表示名</Label>
                    <Input
                        id="display-label"
                        v-model="form.display_overrides.label as string"
                        maxlength="60"
                        :placeholder="contentType.label"
                        data-testid="content-type-label"
                    />
                    <p class="text-xs text-muted-foreground">
                        空のままにすると「{{ contentType.label }}」を使います。
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="display-plural">複数形の表示名</Label>
                    <Input
                        id="display-plural"
                        v-model="form.display_overrides.plural_label as string"
                        maxlength="60"
                    />
                </div>

                <div class="flex flex-col gap-1.5 sm:col-span-2">
                    <Label for="display-description">説明</Label>
                    <Input
                        id="display-description"
                        v-model="form.display_overrides.description as string"
                        maxlength="200"
                    />
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="display-icon">アイコン</Label>
                    <select
                        id="display-icon"
                        v-model="form.display_overrides.icon"
                        class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        data-testid="content-type-icon"
                    >
                        <option value="">未設定</option>
                        <option
                            v-for="icon in options.icons"
                            :key="icon"
                            :value="icon"
                        >
                            {{ icon }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        用意されたアイコンから選びます。
                    </p>
                </div>

                <div class="flex items-center gap-2 sm:col-span-2">
                    <input
                        id="display-menu"
                        v-model="form.display_overrides.show_in_menu"
                        type="checkbox"
                        class="size-4"
                        data-testid="content-type-show-in-menu"
                    />
                    <Label for="display-menu">管理メニューに出す</Label>
                </div>
            </div>

            <p
                v-if="form.errors.display_overrides"
                class="text-sm text-destructive"
                data-testid="content-type-display-error"
            >
                {{ form.errors.display_overrides }}
            </p>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border p-4">
            <h2 class="text-sm font-semibold">公開</h2>

            <div class="flex flex-col gap-1.5">
                <Label for="public-slug">公開スラッグ</Label>
                <Input
                    id="public-slug"
                    v-model="form.public_slug"
                    maxlength="60"
                    placeholder="blog"
                    data-testid="content-type-public-slug"
                />
                <p class="text-xs text-muted-foreground">
                    公開ページの URL
                    に使います（英小文字・数字・ハイフン）。変更しても、以前の
                    URL からは自動で転送されます。
                </p>
                <div class="flex flex-col gap-1.5">
                    <Label for="permalink">記事の URL の形</Label>
                    <select
                        id="permalink"
                        v-model="form.registration.permalink"
                        class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        data-testid="content-type-permalink"
                    >
                        <option
                            v-for="item in options.permalinks"
                            :key="item.value"
                            :value="item.value"
                        >
                            {{ item.label }} — 例: {{ item.example }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        既に公開している記事がある場合、入口の付け替えなら旧 URL
                        から自動で転送されます。
                        入口より後ろの形を変える場合は、旧 URL
                        を引き継げないため保存できません。
                    </p>
                </div>

                <p
                    v-if="form.errors.public_slug"
                    class="text-sm text-destructive"
                    data-testid="content-type-slug-error"
                >
                    {{ form.errors.public_slug }}
                </p>
            </div>

            <div class="flex flex-col gap-3">
                <div
                    v-for="key in Object.keys(registrationLabels)"
                    :key="key"
                    class="flex items-start gap-2"
                >
                    <input
                        :id="`registration-${key}`"
                        v-model="form.registration[key]"
                        type="checkbox"
                        class="mt-1 size-4"
                        :data-testid="`content-type-registration-${key}`"
                    />
                    <div class="flex flex-col">
                        <Label :for="`registration-${key}`">{{
                            registrationLabels[key]
                        }}</Label>
                        <span class="text-xs text-muted-foreground">{{
                            registrationHelp[key]
                        }}</span>
                    </div>
                </div>
            </div>

            <p
                v-if="form.errors.registration"
                class="text-sm text-destructive"
                data-testid="content-type-registration-error"
            >
                {{ form.errors.registration }}
            </p>
        </section>

        <!-- コメントは「型が使ってよいか」だけ。受け付ける仕組みは後続の更新 -->
        <section class="flex flex-col gap-3 rounded-xl border p-4">
            <h2 class="text-sm font-semibold">コメント</h2>

            <p class="text-sm" data-testid="content-type-comments-state">
                この投稿タイプでコメント機能を
                <strong>{{
                    contentType.commentsEnabled ? '使えます' : '使いません'
                }}</strong>
            </p>

            <p class="text-xs text-muted-foreground">
                使えるようにすると、<strong>公開ページの記事に投稿欄が出て、その場で受け付けが始まります</strong>。
                届いたコメントは承認するまで公開ページには出ません（管理メニューの「コメント」に並びます）。
                使わない設定に戻しても、書き込まれたコメントは削除されません。
            </p>

            <div>
                <Button
                    v-if="contentType.commentsEnabled"
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-testid="content-type-comments-disable"
                    @click="toggleComments(false)"
                >
                    コメントを使わないようにする
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="content-type-comments-enable"
                    @click="toggleComments(true)"
                >
                    コメントを使えるようにする
                </Button>
            </div>
        </section>

        <!-- 基本項目は読み取り専用。項目の編集はカスタムフィールドへ送り出す -->
        <section class="flex flex-col gap-3 rounded-xl border p-4">
            <h2 class="text-sm font-semibold">この投稿タイプの項目</h2>

            <div>
                <h3 class="text-xs font-medium text-muted-foreground">
                    基本項目（変更できません）
                </h3>
                <ul class="mt-2 flex flex-wrap gap-2">
                    <li
                        v-for="field in contentType.baseFields"
                        :key="field.key"
                        data-testid="content-type-base-field"
                    >
                        <Badge variant="outline">
                            {{ field.label }}
                            <span class="ml-1 font-mono text-[10px]">{{
                                field.key
                            }}</span>
                        </Badge>
                    </li>
                </ul>
            </div>

            <div>
                <h3 class="text-xs font-medium text-muted-foreground">
                    割り当てられているカスタムフィールド
                </h3>
                <p
                    v-if="contentType.fieldGroups.length === 0"
                    class="mt-2 text-sm text-muted-foreground"
                    data-testid="content-type-field-groups-empty"
                >
                    まだ割り当てられていません。
                </p>
                <ul v-else class="mt-2 flex flex-col gap-2">
                    <li
                        v-for="group in contentType.fieldGroups"
                        :key="group.id"
                        class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                        data-testid="content-type-field-group"
                    >
                        <span>
                            {{ group.label }}
                            <span class="text-xs text-muted-foreground"
                                >（{{ group.fieldCount }} 項目）</span
                            >
                        </span>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="group.editUrl">この束を編集</Link>
                        </Button>
                    </li>
                </ul>
            </div>

            <p class="text-xs text-muted-foreground">
                項目の追加・並べ替え・表示名の変更は
                <Link
                    :href="contentType.fieldGroupsUrl"
                    class="underline"
                    data-testid="content-type-field-groups-link"
                    >カスタムフィールド</Link
                >
                で行います。
            </p>
        </section>

        <p
            v-if="form.errors.lock_version"
            class="text-sm text-destructive"
            data-testid="content-type-conflict"
        >
            {{ form.errors.lock_version }}
        </p>

        <div class="flex items-center gap-2">
            <SaveErrorSummary
                ref="errorSummary"
                :errors="form.errors"
                :fields="errorFields"
            />
            <Button type="submit" :disabled="form.processing">保存する</Button>
            <Button variant="ghost" as-child>
                <Link href="/admin/content-types">やめる</Link>
            </Button>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="変更する"
            @confirm="accept"
            @cancel="cancelComments"
        />
    </form>
</template>
