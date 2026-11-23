<script setup lang="ts">
// Schema 駆動の一覧画面（collection kind 共通）。個別 CRUD 画面の手書きは禁止。
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ChevronDown, ChevronUp, ExternalLink } from '@lucide/vue';
import { toast } from 'vue-sonner';
import {
    contentState,
    contentStateLabel,
    contentStateVariant,
} from '@/lib/content-state';
import PublicTypeNotice from '@/components/PublicTypeNotice.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { adminApi } from '@/lib/adminApi';
import { formatDateTime, toDateOnly } from '@/lib/admin-datetime';
import RevisionHistoryDialog from '@/components/RevisionHistoryDialog.vue';
import { useAdminTimezone } from '@/composables/useAdminTimezone';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { AdminSchema, ContentItem, Paginated } from '@/types/schema';

const props = defineProps<{
    schema: AdminSchema;
    items: Paginated<ContentItem>;
    q: string;
    routeBase: string;
    /** 'active' | 'trashed'。同じ画面の別の状態（Phase 9.8 / STAGE 21E） */
    state?: string;
    /** 無効にした投稿タイプでは読むだけにする（SPEC §A.3） */
    canWrite?: boolean;
    /** 掲載順を触れるか（P1 ②）。**宣言ではなく実行時の状態**なのでサーバーが決める */
    canReorder?: boolean;
    /** この投稿タイプを公開サイトに出していないか（公開にしても出ないことを言う） */
    publicTypeDisabled?: boolean;
}>();

const timezone = useAdminTimezone();

const search = ref(props.q);

const viewingTrash = computed(() => props.state === 'trashed');
const writable = computed(() => props.canWrite !== false);

/** 一覧の切り替え。検索語は状態ごとに持ち直す（混ざらないように） */
function showState(next: 'active' | 'trashed') {
    router.get(
        `/admin/${props.routeBase}`,
        next === 'trashed' ? { state: 'trashed' } : {},
        { preserveState: false },
    );
}

/** 版（楽観ロック）。**送れば必ず検証される** ので、読んだ値をそのまま添える */
function lockVersionOf(item: ContentItem): number | null {
    const value = item.lock_version;

    return typeof value === 'number' ? value : null;
}

/** いま開いている履歴の対象（null なら閉じている） */
const historyItem = ref<ContentItem | null>(null);

const fieldLabel = (key: string) =>
    props.schema.fields.find((f) => f.key === key)?.label ?? key;
const fieldType = (key: string) =>
    props.schema.fields.find((f) => f.key === key)?.type;

function formatCell(item: ContentItem, key: string): string {
    const value = item[key];

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    /*
     * 日時は **サーバーの時間帯**で読む（WP 一周 5 周目 2026-09-03）。
     *
     * ここは以前 `String(value).replace('T', ' ').slice(0, 16)` だった。
     * `published_at` は Carbon なので Inertia が ISO8601（UTC・末尾 Z）で流す —
     * **UTC の文字をそのまま JST として出していた**（19:29 の記事が 10:29 と出る）。
     */
    if (fieldType(key) === 'datetime') {
        return formatDateTime(value, timezone.value);
    }

    if (fieldType(key) === 'date') {
        return toDateOnly(value, timezone.value);
    }

    return String(value);
}

/**
 * 「公開」なのに公開日時が空 = **サイトには出ていない**（WP 一周 2 周目 2026-09-03）。
 *
 * 判定は公開面と同じ条件（`HasContentSchema::scopePublished()` の `published_at` 側）。
 * 出す場所は公開日時の列だけ — 状態の列は「公開」と言い続けてよい。
 * そこが嘘なのではなく、**2 つ揃って初めて出る**ことが伝わっていなかった。
 */
function notPublicYet(item: ContentItem, key: string): boolean {
    if (key !== 'published_at' || item.status !== 'published') {
        return false;
    }

    const value = item[key];

    return value === null || value === undefined || value === '';
}

// core が付与する editUrl を優先し、無い場合だけ routeBase から組み立てる
const editHref = (item: ContentItem): string =>
    item.editUrl ?? `/admin/${props.routeBase}/${item.id}/edit`;

function submitSearch() {
    router.get(
        `/admin/${props.routeBase}`,
        // 状態を保ったまま検索する（ゴミ箱を見ていたなら、ゴミ箱の中を検索する）
        viewingTrash.value
            ? { q: search.value, state: 'trashed' }
            : { q: search.value },
        { preserveState: true },
    );
}

// Laravel paginator のラベル（&laquo; 等）を v-html なしで表示する
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

/**
 * ゴミ箱へ移すのは取り消せる操作だが、**利用者にとっては「消す」導線**である。
 * ブラウザの confirm() ではなくサーバーの ConfirmationGate を通す（Phase 9.1B / STEP 7）。
 * 何が起きるか（戻せること・公開面から消えること）はサーバーが文面で返す。
 */
const pendingItem = ref<ContentItem | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const item = pendingItem.value;

        if (!item) {
            return;
        }

        router.post(
            `/admin/${props.routeBase}/${item.id}/trash`,
            {
                ...(token ? { confirmation_token: token } : {}),
                // 読んだときの版を添える。その間に誰かが更新していたら上書きしない
                lock_version: lockVersionOf(item),
            },
            {
                preserveScroll: true,
                preserveState: token === null,
            },
        );
    },
);

function trashItem(item: ContentItem) {
    pendingItem.value = item;
    request();
}

/** ゴミ箱から戻す。**確認は取らない**（戻すこと自体は何も失わない） */
function restoreItem(item: ContentItem) {
    router.post(
        `/admin/${props.routeBase}/${item.id}/restore`,
        {
            lock_version: lockVersionOf(item),
        },
        { preserveScroll: true },
    );
}

/*
 * 掲載順（P1 ②）。
 *
 * ## 全件を送らない
 *
 * この画面が持っているのは**その頁の行**だけである。並び全体を送る形にすると、
 * **頁の外の行を上書き**する。送るのは行のIDと上下の方向だけ。全体の現在位置はサーバーで判断する。
 *
 * ## 上へ / 下へ にした理由
 *
 * drag は「どこへ落としたか」を送るまでに状態を持つ。1 つ動かすだけなら
 * **隣と入れ替える**で足り、キーボードでも押せる。**まず動くものを置く。**
 */
const reordering = ref<number | null>(null);

const rows = computed(() => props.items.data);

/**
 * ページ境界をまたぐ位置の計算はサーバーの ReorderContent が担う。
 */
function moveUp(index: number) {
    send(rows.value[index], 'up', index);
}

function moveDown(index: number) {
    send(rows.value[index], 'down', index);
}

async function send(
    item: ContentItem,
    direction: 'up' | 'down',
    index: number,
) {
    // 絞り込み中は見えていない行との位置関係を取り違えないようにする。
    if (props.q.trim() !== '' || reordering.value !== null) {
        return;
    }

    reordering.value = Number(item.id);

    const result = await adminApi.send(
        'POST',
        `/api/v1/content/${props.schema.id}/${item.id}/reorder`,
        { direction },
    );

    reordering.value = null;

    if (result.status !== 'success') {
        /*
         * **黙って戻さない。** 押したのに何も起きないのが一番分からない。
         * 文面はサーバーが返したものをそのまま出す。
         */
        toast.error(
            ('message' in result ? result.message : null) ??
                '並べ替えできませんでした。',
        );

        return;
    }

    // **並びは一覧そのもの**なので、引き直す（どこまで動いたかを画面で見せる）
    // ページ境界を越えた行は移動先のページで確認できるようにする。
    const nextPage =
        direction === 'up' && index === 0
            ? Math.max(1, props.items.current_page - 1)
            : direction === 'down' && index === rows.value.length - 1
              ? Math.min(props.items.last_page, props.items.current_page + 1)
              : props.items.current_page;

    router.get(
        `/admin/${props.routeBase}`,
        { page: nextPage },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="schema.label" />

    <div class="flex flex-col gap-4 p-4">
        <PublicTypeNotice
            v-if="publicTypeDisabled"
            :schema-id="schema.id"
            :label="schema.label"
        />
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">
                {{ schema.label }}
                <span v-if="viewingTrash" class="text-muted-foreground"
                    >のゴミ箱</span
                >
            </h1>
            <Button v-if="!viewingTrash && writable" as-child>
                <Link :href="`/admin/${routeBase}/create`" prefetch
                    >新規作成</Link
                >
            </Button>
        </div>

        <!-- 通常とゴミ箱は同じ画面の別の状態。どちらを見ているかを常に示す -->
        <div
            class="flex gap-1"
            role="tablist"
            :aria-label="`${schema.label}の表示切り替え`"
        >
            <Button
                :variant="viewingTrash ? 'ghost' : 'default'"
                size="sm"
                role="tab"
                :aria-selected="!viewingTrash"
                data-testid="state-active"
                @click="showState('active')"
            >
                {{ schema.label }}
            </Button>
            <Button
                :variant="viewingTrash ? 'default' : 'ghost'"
                size="sm"
                role="tab"
                :aria-selected="viewingTrash"
                data-testid="state-trashed"
                @click="showState('trashed')"
            >
                ゴミ箱
            </Button>
        </div>

        <p
            v-if="viewingTrash"
            class="text-sm text-muted-foreground"
            data-testid="trash-note"
        >
            ゴミ箱にある{{
                schema.label
            }}です。公開ページには表示されません。元に戻せます。
        </p>

        <p
            v-if="!writable"
            class="text-sm text-muted-foreground"
            data-testid="readonly-note"
        >
            この投稿タイプは無効になっているため、閲覧のみ可能です。
        </p>

        <p
            v-if="canReorder"
            class="text-sm text-muted-foreground"
            data-testid="reorder-note"
        >
            ▲▼
            で掲載順を変えられます。ページをまたいで移動でき、公開ページにも反映されます。検索中は検索を解除してから並べ替えてください。
        </p>

        <form
            v-if="schema.search.length"
            class="flex max-w-sm gap-2"
            @submit.prevent="submitSearch"
        >
            <Input v-model="search" type="search" placeholder="検索..." />
            <Button type="submit" variant="secondary">検索</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th
                            v-for="key in schema.list"
                            :key="key"
                            class="px-4 py-3 font-medium text-muted-foreground"
                        >
                            {{ fieldLabel(key) }}
                        </th>
                        <th class="w-32 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, index) in items.data"
                        :key="item.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                    >
                        <td
                            v-for="key in schema.list"
                            :key="key"
                            class="px-4 py-3"
                        >
                            <!-- 公開日時が先なら「予約」（7 周目 R7-3: もう出ている記事と同じ「公開」だった） -->
                            <Badge
                                v-if="fieldType(key) === 'status'"
                                :variant="
                                    contentStateVariant(
                                        contentState(item[key], item.published_at),
                                    )
                                "
                                data-testid="content-state"
                            >
                                {{
                                    contentStateLabel(
                                        contentState(item[key], item.published_at),
                                    )
                                }}
                            </Badge>
                            <!-- タイトル列は行を代表する列。クリックで編集画面へ（F-UX2） -->
                            <Link
                                v-else-if="key === schema.titleKey"
                                :href="editHref(item)"
                                class="font-medium underline-offset-4 hover:underline"
                                data-testid="row-title-link"
                            >
                                {{ formatCell(item, key) }}
                            </Link>
                            <!--
                                🔴 **「公開」なのにサイトに出ていないことを、その場で言う**
                                （WP 一周 2 周目 2026-09-03）。

                                公開面は `status` だけでなく `published_at` も見る。
                                日時が空だと「公開」と出ているのにサイトには出ない。
                                いまは保存時に日時が入るので新しく作ったものでは起きないが、
                                **前から在る行**はこの形で残っている。黙って「—」と出さない。
                            -->
                            <template v-else-if="notPublicYet(item, key)">
                                <span class="text-destructive">
                                    {{ formatCell(item, key) }}
                                    <span class="text-xs"
                                        >（日時が空なのでサイトには出ていません）</span
                                    >
                                </span>
                            </template>
                            <template v-else>{{
                                formatCell(item, key)
                            }}</template>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <!--
                                掲載順（P1 ②）。**この頁の中で隣と入れ替える**。
                                頁の外の行は送らないので、上書きしない
                            -->
                            <template v-if="canReorder">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="
                                        (index === 0 &&
                                            items.current_page === 1) ||
                                        reordering !== null ||
                                        q.trim() !== ''
                                    "
                                    :aria-label="`${formatCell(item, schema.titleKey ?? 'id')}を1つ上へ`"
                                    data-testid="row-move-up"
                                    @click="moveUp(index)"
                                >
                                    <ChevronUp
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="
                                        (index === items.data.length - 1 &&
                                            items.current_page ===
                                                items.last_page) ||
                                        reordering !== null ||
                                        q.trim() !== ''
                                    "
                                    :aria-label="`${formatCell(item, schema.titleKey ?? 'id')}を1つ下へ`"
                                    data-testid="row-move-down"
                                    @click="moveDown(index)"
                                >
                                    <ChevronDown
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </template>
                            <!-- 公開ページを持つときだけ導線を出す（無効なリンクを出さない） -->
                            <Button
                                v-if="item.publicUrl && !viewingTrash"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="item.publicUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-testid="row-public-link"
                                >
                                    公開ページを見る
                                    <ExternalLink
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span class="sr-only"
                                        >（新しいタブで開きます）</span
                                    >
                                </a>
                            </Button>
                            <!-- ゴミ箱では編集も公開もさせない。戻すか、履歴を見るだけ -->
                            <Button
                                v-if="!viewingTrash && writable"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <Link :href="editHref(item)">編集</Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-testid="row-history"
                                @click="historyItem = item"
                            >
                                履歴
                            </Button>
                            <Button
                                v-if="viewingTrash && writable"
                                variant="ghost"
                                size="sm"
                                data-testid="row-restore"
                                @click="restoreItem(item)"
                            >
                                元に戻す
                            </Button>
                            <Button
                                v-if="!viewingTrash && writable"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                data-testid="row-trash"
                                @click="trashItem(item)"
                            >
                                ゴミ箱へ移す
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="items.data.length === 0">
                        <td
                            :colspan="schema.list.length + 1"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="empty-state"
                        >
                            {{
                                viewingTrash
                                    ? 'ゴミ箱は空です'
                                    : `まだ${schema.label}がありません`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />

        <RevisionHistoryDialog
            :item="historyItem"
            :route-base="routeBase"
            :label="schema.label"
            :can-write="writable"
            @close="historyItem = null"
        />

        <nav v-if="items.last_page > 1" class="flex gap-1">
            <template v-for="(link, i) in items.links" :key="i">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    as-child
                >
                    <Link :href="link.url">{{ pageLabel(link.label) }}</Link>
                </Button>
            </template>
        </nav>
    </div>
</template>
