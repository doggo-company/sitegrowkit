<script setup lang="ts">
/**
 * ページの設定と lifecycle（Phase 9.3B / #19・SPEC §V.2 / §X / §Y / §Z）。
 *
 * この画面が引き受ける仕事は **設定 → 下書き確認 → 公開 → ゴミ箱 / 復元**まで。
 * 本文ブロックの編集（Visual Editor）はここでは扱わない。
 *
 * 状態は色だけに頼らず、必ず文字で示す。
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useClipboard } from '@vueuse/core';
import { Copy, ExternalLink } from '@lucide/vue';
import FrontPagePanel from '@/components/page-editor/FrontPagePanel.vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { PageDetail, PageEditorSummary } from '@/types/page';

const props = defineProps<{
    page: PageDetail;
    previewUrl: string;
    /** 公開中の document ページだけ。開けない状態では null */
    publicUrl: string | null;
    /** 構成サマリ・公開可否・依頼文。すべてサーバーが算出した読み取り専用の値 */
    summary: PageEditorSummary;
    /** 公開済みで、まだトップに選んでいないページ */
    frontPageOffer?: boolean;
    frontPageSelected?: boolean;
}>();

/** 予約中は「公開予約」。文言も状態もサーバー（`Page::publishState()`）が決める */
const statusLabel = computed(() => props.page.publishStateLabel);
const isScheduled = computed(() => props.page.scheduled);
/** いま公開サイトに出ているか（予約中は status が published でも出ていない） */
const isLive = computed(() => props.page.publishState === 'published');

/** 予約中の説明。1 本の文字列にして、template の折り返しで文の途中に空白が入らないようにする */
const scheduledNotice = computed(
    () =>
        `${props.page.publishedAtLocal}（${props.page.timezone}）に公開されます。` +
        'それまで公開ページ・メニュー・sitemap には出ません。' +
        'この時刻になると自動で公開されます。',
);

const inertia = usePage();
const pageErrors = computed(
    () => (inertia.props.errors ?? {}) as Record<string, string>,
);

/** 競合（既にゴミ箱にある等）は form の項目ではなく画面全体のエラーとして返る */
const conflict = computed(() => pageErrors.value.conflict);

/**
 * 公開の失敗理由（例: 本文が空）。
 *
 * 公開は `router.post` で送るため `form.errors` には入らない。
 * ここで拾わないと **ボタンを押した利用者に何も見えない**ので、明示的に出す。
 */
const publishError = computed(() => pageErrors.value.root);
/** 公開日時が読めなかったとき（Application 層の validation error） */
const publishAtError = computed(() => pageErrors.value.publishedAt);

/**
 * 公開日時（`datetime-local`）。**空欄はすぐ公開**。
 *
 * 予約中のページを開いたときは、その予約日時を初期値にする — 日時を変えて
 * もう一度「予約する」と押せば、予約の変更になる（別の操作は無い）。
 * 値はサイトの timezone の壁時計で、サーバーがそのまま同じ timezone で読む。
 */
const publishAt = ref(
    props.page.scheduled && props.page.publishedAtLocal
        ? props.page.publishedAtLocal.replace(' ', 'T')
        : '',
);
const willSchedule = computed(() => publishAt.value !== '');

/**
 * 公開中なのに、直した内容がまだ公開に出ていないか。
 *
 * 編集は下書きの版に入り、公開の版は「公開する」を押すまで変わらない。
 * **ここを言わないと、直したのに反映されない画面になる**（UX 一周 第 15 周）。
 * 下書きの版と公開の版が同じなら、直していない。
 */
const draftAhead = computed(
    () =>
        isLive.value &&
        props.page.draftVersionId !== null &&
        props.page.draftVersionId !== props.page.publishedVersionId,
);

/** 公開ボタンの文言。公開中に押すのは「直した内容を公開に出す」操作である */
const publishLabel = computed(() => {
    if (willSchedule.value) {
        return 'この日時に公開する';
    }

    return draftAhead.value ? '直した内容を公開する' : '公開する';
});

/**
 * 公開中で、直した内容も予約の日時も無い。押しても何も変わらないので押せなくし、理由を添える
 * （公開したばかりの画面に黒い「公開する」が残ると、もう一度押すべきかで迷う）。
 */
const nothingNew = computed(
    () => isLive.value && !draftAhead.value && !willSchedule.value,
);

const isDocument = computed(() => props.page.managedBy === 'document');
const isTrashed = computed(() => props.page.status === 'trashed');

/**
 * 公開できるか。**判定はサーバー**（`DocumentValidator::validateForPublish()`）が済ませていて、
 * ここはその結論を表示に使うだけ。押せなくするのは親切のためで、
 * 実際に公開を止めるのは Application Service 側の検証。
 */
const publishable = computed(() => props.summary.publishable);

/** 押せない理由。押す前から見えていないと「なぜか押せない」画面になる */
const blockedReason = computed(() => {
    if (!publishable.value) {
        return props.summary.reason;
    }

    return nothingNew.value
        ? '公開中の内容と同じです。「ブロックを編集」で直すと、ここから公開に出せます。'
        : null;
});

/** 構成サマリの字下げ。深さで見出しの入れ子が分かるようにする */
function indent(depth: number): Record<string, string> {
    return { paddingInlineStart: `${Math.min(depth, 6) * 1}rem` };
}

/**
 * 依頼文のコピー。
 *
 * クリップボードは権限や環境で失敗する。失敗しても文章そのものは画面に出したままにして、
 * 手で選択してコピーできる状態を必ず残す。結果は aria-live で読み上げる。
 */
const { copy, copied } = useClipboard();
const copyMessage = ref('');

async function copyPrompt() {
    copyMessage.value = '';

    try {
        await copy(props.summary.aiPrompt);
    } catch {
        // 失敗も下の分岐で同じように扱う（画面には必ず結果を出す）
    }

    copyMessage.value = copied.value
        ? '依頼文をコピーしました。'
        : 'コピーできませんでした。下の依頼文を選択してコピーしてください。';
}

const form = useForm({
    title: props.page.title,
    slug: props.page.slug,
    seo: {
        description: props.page.seo?.description ?? '',
        noindex: props.page.seo?.noindex ?? false,
    },
});

function save() {
    form.patch(`/admin/pages/${props.page.id}`, { preserveScroll: true });
}

const busy = ref(false);

function publish() {
    busy.value = true;
    router.post(
        `/admin/pages/${props.page.id}/publish`,
        // 空欄は送らない（null）= いま公開。日時の解釈はサーバー側 1 か所
        { publishedAt: publishAt.value === '' ? null : publishAt.value },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

// 予約を取り消しても即公開せず、下書きのまま保存する。
// 保存済みの版を複製する。編集中の設定を暗黙保存しない。
const duplicating = ref(false);
function duplicatePage() {
    if (duplicating.value) {
        return;
    }

    duplicating.value = true;
    router.post(
        `/admin/pages/${props.page.id}/duplicate`,
        {},
        {
            // 別ページへ移るため、複製元のuseFormを保持して上書きさせない。
            preserveState: false,
            onFinish: () => {
                duplicating.value = false;
            },
        },
    );
}

function unpublish() {
    busy.value = true;
    router.post(
        `/admin/pages/${props.page.id}/unpublish`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                publishAt.value = '';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function restore() {
    busy.value = true;
    router.post(
        `/admin/pages/${props.page.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

/**
 * ゴミ箱へ移すのは破壊的操作なので、ブラウザの confirm ではなく
 * サーバーの ConfirmationGate を通す（AI からの操作も同じ経路）。
 */
const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        router.post(
            `/admin/pages/${props.page.id}/trash`,
            token ? { confirmation_token: token } : {},
            { preserveScroll: true, preserveState: token === null },
        );
    },
);

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="`${page.title} — ページ`" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <Link
                href="/admin/pages"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← ページ一覧へ戻る
            </Link>
            <h1 class="text-xl font-semibold">{{ page.title }}</h1>
        </div>

        <!-- lifecycle: いまどの状態で、次に何ができるかを 1 か所に集める -->
        <section
            class="flex flex-col gap-3 rounded-xl border p-4"
            data-testid="page-lifecycle"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="isLive ? 'default' : 'secondary'"
                    data-testid="page-status-badge"
                >
                    {{ statusLabel }}
                </Badge>
                <Badge variant="outline">
                    {{
                        isDocument
                            ? '本文はブロックで管理'
                            : '本文はコードで管理'
                    }}
                </Badge>
                <span class="font-mono text-xs break-all text-muted-foreground">
                    /{{ page.slug }}
                </span>
            </div>

            <p v-if="!isDocument" class="text-sm text-muted-foreground">
                このページの本文はコードで管理されています。本文の編集と公開操作はできませんが、タイトル・URL・SEO
                は変更できます。
            </p>

            <!--
                公開日時は状態の一部として常に見せる（予約中は「いつ」が本体）。
                timezone を添える — 海外のホストでも「サイトの時刻」であることが分かるように。
            -->
            <p
                v-if="page.publishedAtLocal && !isTrashed"
                class="text-sm text-muted-foreground"
                data-testid="page-published-at"
            >
                <template v-if="isScheduled">{{ scheduledNotice }}</template>
                <template v-else>
                    公開日時: {{ page.publishedAtLocal }}（{{ page.timezone }}）
                </template>
            </p>

            <!-- 直した内容がまだ公開に出ていない（公開中の版 ≠ 下書きの版） -->
            <p
                v-if="draftAhead"
                class="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                data-testid="page-draft-ahead"
            >
                直した内容は、まだ公開ページには出ていません。「直した内容を公開する」を押すと反映されます。
            </p>

            <!-- 公開日時の入力。空欄なら押した時点で公開 -->
            <div v-if="isDocument && !isTrashed" class="grid max-w-sm gap-1.5">
                <Label for="page-publish-at" class="text-sm">
                    公開日時（空欄ならすぐに公開）
                </Label>
                <input
                    id="page-publish-at"
                    v-model="publishAt"
                    type="datetime-local"
                    step="60"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :aria-describedby="
                        publishAtError
                            ? 'page-publish-at-error'
                            : 'page-publish-at-hint'
                    "
                    data-testid="page-publish-at-input"
                />
                <p
                    id="page-publish-at-hint"
                    class="text-xs text-muted-foreground"
                >
                    サイトの時刻（{{ page.timezone }}）で指定します。
                    <template v-if="isLive && willSchedule">
                        指定した日時までは、いま公開中の内容も表示されなくなります。
                    </template>
                </p>
                <InputError
                    id="page-publish-at-error"
                    :message="publishAtError"
                    data-testid="page-publish-at-error"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                <!-- 本文ブロックの編集は別画面（Visual Page Editor）。ここでは扱わない -->
                <Button v-if="isDocument && !isTrashed" size="sm" as-child>
                    <Link
                        :href="`/admin/pages/${page.id}/editor`"
                        data-testid="page-editor-link"
                    >
                        ブロックを編集
                    </Link>
                </Button>

                <Button
                    v-if="isDocument && !isTrashed"
                    variant="outline"
                    :disabled="duplicating || form.isDirty"
                    data-testid="page-duplicate-button"
                    @click="duplicatePage"
                >
                    {{ duplicating ? '複製しています…' : '下書きとして複製' }}
                </Button>
                <span
                    v-if="isDocument && !isTrashed && form.isDirty"
                    class="text-sm text-muted-foreground"
                >
                    設定の変更を保存すると複製できます。
                </span>
                <Button
                    v-if="isDocument && !isTrashed"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="previewUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-testid="page-preview-link"
                    >
                        下書きをプレビュー
                        <ExternalLink class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">（新しいタブで開きます）</span>
                    </a>
                </Button>

                <Button v-if="publicUrl" variant="outline" size="sm" as-child>
                    <a
                        :href="publicUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-testid="page-public-link"
                    >
                        公開ページを見る
                        <ExternalLink class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">（新しいタブで開きます）</span>
                    </a>
                </Button>

                <Button
                    v-if="isDocument && !isTrashed"
                    size="sm"
                    :disabled="busy || !publishable || nothingNew"
                    :aria-disabled="busy || !publishable || nothingNew"
                    :aria-describedby="
                        blockedReason ? 'page-publish-blocked' : undefined
                    "
                    data-testid="page-publish-button"
                    @click="publish"
                >
                    {{ publishLabel }}
                </Button>

                <Button
                    v-if="isDocument && (isScheduled || isLive)"
                    variant="outline"
                    size="sm"
                    :disabled="busy"
                    data-testid="page-unpublish-button"
                    @click="unpublish"
                >
                    {{
                        isScheduled
                            ? '予約を取り消して下書きへ'
                            : '下書きへ戻す'
                    }}
                </Button>

                <Button
                    v-if="isTrashed"
                    size="sm"
                    :disabled="busy"
                    data-testid="page-restore-button"
                    @click="restore"
                >
                    復元する
                </Button>

                <Button
                    v-if="!isTrashed"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    :disabled="busy"
                    data-testid="page-trash-button"
                    @click="request"
                >
                    ゴミ箱へ移す
                </Button>
            </div>

            <FrontPagePanel
                :page-id="page.id"
                :offer="frontPageOffer"
                :selected="frontPageSelected"
            />

            <!--
                押せない理由は押す前から見えている状態にする。
                色だけで伝えず、必ず文字で書く。
            -->
            <p
                v-if="blockedReason && isDocument && !isTrashed"
                id="page-publish-blocked"
                class="text-sm text-muted-foreground"
                data-testid="page-publish-blocked"
            >
                {{ blockedReason }}
            </p>

            <InputError
                :message="publishError"
                data-testid="page-publish-error"
            />
            <InputError :message="conflict" />
        </section>

        <!--
            ページの構成（読み取り専用）。
            本文を組み替える操作はここには無い。いま何が入っているかを見せるだけ。
        -->
        <section
            v-if="isDocument"
            class="flex flex-col gap-3 rounded-xl border p-4"
            data-testid="page-composition"
        >
            <h2 class="font-medium">ページの構成</h2>

            <!--
                骨格が 0 件でも「空」とは限らない。
                何も入っていないのか、本文だけで見出しが無いのかを言い分ける。
            -->
            <p
                v-if="summary.blocks.length === 0"
                class="text-sm text-muted-foreground"
                data-testid="page-composition-empty"
            >
                {{
                    summary.hasBlocks
                        ? summary.outlineEmptyMessage
                        : summary.emptyMessage
                }}
            </p>

            <ol v-else class="flex flex-col gap-1.5 text-sm">
                <li
                    v-for="(block, index) in summary.blocks"
                    :key="index"
                    class="flex flex-wrap items-center gap-2"
                    :style="indent(block.depth)"
                    data-testid="page-composition-block"
                >
                    <Badge variant="outline">{{ block.label }}</Badge>
                    <span
                        v-if="block.level !== null"
                        class="font-mono text-xs text-muted-foreground"
                    >
                        h{{ block.level }}
                    </span>
                    <span v-if="block.text" class="break-all">
                        {{ block.text }}
                    </span>
                    <span
                        v-else-if="block.type === 'heading'"
                        class="text-muted-foreground"
                    >
                        （文言なし）
                    </span>
                </li>
            </ol>

            <!--
                骨組みだけを出していると言う。言わないと、置いた本文や表が消えたように見える
                （UX 一周 第 15 周: 営業時間の表を置いたのに構成に無かった）
            -->
            <p
                v-if="summary.hasBlocks"
                class="text-xs text-muted-foreground"
                data-testid="page-composition-note"
            >
                {{ summary.outlineNote }}
                <template v-if="summary.others.length > 0">
                    このほかに
                    {{
                        summary.others
                            .map((o) => `${o.label} ${o.count}`)
                            .join('・')
                    }}
                    が入っています。
                </template>
            </p>
        </section>

        <!--
            本文の編集手段。**この画面に AI はいない。**
            ここにあるのは、利用者が自分の AI エージェントへ貼るための文章だけ。
        -->
        <section
            v-if="isDocument && !isTrashed"
            class="flex flex-col gap-3 rounded-xl border p-4"
            data-testid="page-ai-guide"
        >
            <h2 class="font-medium">
                画面でも、AI エージェントでも編集できます
            </h2>

            <p class="text-sm text-muted-foreground">
                <!-- ビジュアル編集の導線と矛盾する、旧来の AI 専用案内を更新する。 -->
                上の「ブロックを編集」から、見出し・本文・画像などを画面で編集できます。
                AI
                に依頼する場合は、セットアップ完了画面の「AIエージェント連携」で
                Codex・Claude Code・Cursor の接続設定を生成してください。
            </p>

            <div class="flex flex-col gap-2">
                <Label for="page-ai-prompt" class="text-sm">
                    依頼文（コピーして AI エージェントに貼り付けてください）
                </Label>
                <textarea
                    id="page-ai-prompt"
                    :value="summary.aiPrompt"
                    readonly
                    rows="8"
                    class="flex w-full rounded-md border border-input bg-muted/40 px-3 py-2 font-mono text-xs break-all transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    data-testid="page-ai-prompt"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        type="button"
                        data-testid="page-ai-prompt-copy"
                        @click="copyPrompt"
                    >
                        <Copy class="size-3.5" aria-hidden="true" />
                        依頼文をコピー
                    </Button>
                    <p
                        class="text-sm text-muted-foreground"
                        role="status"
                        aria-live="polite"
                        data-testid="page-ai-prompt-copy-status"
                    >
                        {{ copyMessage }}
                    </p>
                </div>
            </div>
        </section>

        <form class="flex flex-col gap-5" @submit.prevent="save">
            <h2 class="font-medium">ページ設定</h2>

            <div class="grid gap-2">
                <Label for="page-title">
                    タイトル <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="page-title"
                    v-model="form.title"
                    data-testid="page-title-input"
                />
                <InputError :message="form.errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="page-slug">
                    URL <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="page-slug"
                    v-model="form.slug"
                    data-testid="page-slug-input"
                />
                <InputError :message="form.errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="page-seo-description">SEO ディスクリプション</Label>
                <textarea
                    id="page-seo-description"
                    v-model="form.seo.description"
                    rows="3"
                    maxlength="200"
                    class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    data-testid="page-seo-description-input"
                />
                <InputError :message="form.errors['seo.description']" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="page-noindex"
                    :model-value="form.seo.noindex === true"
                    data-testid="page-noindex-toggle"
                    @update:model-value="form.seo.noindex = $event === true"
                />
                <Label for="page-noindex" class="text-sm font-normal">
                    検索エンジンに登録しない（noindex）
                </Label>
            </div>
            <InputError :message="form.errors['seo.noindex']" />

            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing">保存</Button>
                <Button variant="outline" as-child>
                    <Link href="/admin/pages">キャンセル</Link>
                </Button>
            </div>
        </form>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="ゴミ箱へ移す"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>
