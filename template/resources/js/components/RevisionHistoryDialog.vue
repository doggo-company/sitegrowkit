<script setup lang="ts">
/**
 * 投稿の履歴（Phase 9.8 / STAGE 21E）。
 *
 * ## 履歴を見に来た人の前で落ちない
 *
 * 版が 1 件も無くても、行が壊れていても、通信が失敗しても、画面は開いたままにする。
 * 原因を調べに来た相手を、原因そのもので締め出さない。状態は 4 つ（読み込み中 /
 * 失敗 / 空 / 一覧）を日本語で出し分ける。
 *
 * ## 生の内容を描かない
 *
 * snapshot はそのまま出さない。出すのは「いつ・なぜ・どこから」だけで、
 * 文字列は Vue の既定のエスケープに任せる（`v-html` は使わない）。
 *
 * ## 戻すのは 2 段階
 *
 * 何が失われるかをサーバーが返すので、それを **省略せず全部**見せてから実行する。
 * 競合したら、勝手に取り直して実行し直さない — 読み込み直しが要ると伝える。
 */
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { ContentItem } from '@/types/schema';

interface RevisionRow {
    revision: number;
    reason: string;
    kind: string;
    created_at: string | null;
    created_interface: string;
    created_by: number | null;
    entry_updated_at: string | null;
    /** 読めない版（記録が壊れている）。隠さず印を付ける */
    readable: boolean;
}

const props = defineProps<{
    item: ContentItem | null;
    routeBase: string;
    label: string;
    canWrite?: boolean;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

/** 版（楽観ロック）。読んだ値をそのまま添える */
function lockVersionOf(item: ContentItem): number | null {
    const value = item.lock_version;

    return typeof value === 'number' ? value : null;
}

const rows = ref<RevisionRow[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const conflict = ref<string | null>(null);
const target = ref<RevisionRow | null>(null);

/** その版が生まれた理由。サーバーの値をそのまま出さず、読める日本語にする */
const reasonLabel = (reason: string): string =>
    ({
        baseline: '導入時の記録',
        create: '作成',
        update: '更新',
        publish: '公開',
        unpublish: '下書きへ',
        revision_restore: '過去の版から復元',
    })[reason] ?? reason;

const interfaceLabel = (name: string): string =>
    ({ admin: '管理画面', api: 'API', mcp: 'AI', cli: 'コマンド', system: 'システム' })[
        name
    ] ?? name;

/**
 * 版の種類。**知らない値は捨てずにそのまま出す。**
 *
 * 「不明」に丸めると、あとから種類が増えたときに画面だけが古いことに気づけない。
 */
const kindLabel = (kind: string): string =>
    ({ manual: '手動', autosave: '自動保存' })[kind] ?? kind;

/**
 * 誰の操作か。**持っている識別子だけ**を出す。
 *
 * 名前やメールを引きに行かない — 履歴を開いただけで利用者の個人情報が
 * 画面へ出てくる理由が無いし、そのために API を増やす理由も無い。
 *
 * 記録が無いときに「システム」とは書かない。実行者を記録できなかったのか、
 * 本当にシステムが動いたのかは別のことで、ここでは区別がつかない。
 * どこから来たかは interface 側が示す。
 */
const actorLabel = (actor: number | null): string =>
    actor === null ? '実行者の記録なし' : `利用者 #${actor}`;

async function load(item: ContentItem) {
    loading.value = true;
    error.value = null;
    conflict.value = null;
    rows.value = [];

    try {
        const response = await fetch(
            `/admin/${props.routeBase}/${item.id}/revisions`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            error.value = '履歴を読み込めませんでした。時間をおいて開き直してください。';

            return;
        }

        const json = (await response.json()) as { revisions?: RevisionRow[] };
        rows.value = json.revisions ?? [];
    } catch {
        // 原因の詳細は出さない。次に何をすればよいかだけを伝える
        error.value = '履歴を読み込めませんでした。通信状況を確認してください。';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.item,
    (item) => {
        if (item) {
            void load(item);
        }
    },
    { immediate: true },
);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const item = props.item;
        const revision = target.value;

        if (!item || !revision) {
            return;
        }

        router.post(
            `/admin/${props.routeBase}/${item.id}/revisions/${revision.revision}/restore`,
            {
                ...(token ? { confirmation_token: token } : {}),
                lock_version: lockVersionOf(item),
            },
            {
                preserveScroll: true,
                preserveState: token === null,
                onError: (errors) => {
                    /*
                     * **勝手に取り直して実行し直さない。** 押したときと状況が違うので、
                     * 読み込み直してもう一度確かめてもらう。
                     */
                    conflict.value =
                        Object.values(errors)[0] ??
                        '状態が変わったため実行できませんでした。画面を読み込み直してください。';
                    target.value = null;
                },
            },
        );
    },
);

function restore(row: RevisionRow) {
    conflict.value = null;
    target.value = row;
    request();
}

/** 最新の版は「いまの内容」なので、戻す先にならない */
const isCurrent = (row: RevisionRow): boolean =>
    rows.value.length > 0 && row.revision === rows.value[0].revision;
</script>

<template>
    <Dialog
        :open="item !== null"
        @update:open="
            (open: boolean) => {
                if (!open) emit('close');
            }
        "
    >
        <DialogContent class="max-w-2xl" data-testid="revision-history">
            <DialogHeader>
                <DialogTitle>{{ label }}の履歴</DialogTitle>
                <DialogDescription>
                    新しい順に表示します。過去の版へ戻すと、その内容が新しい版として記録されます。
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="loading"
                class="py-6 text-center text-sm text-muted-foreground"
                data-testid="history-loading"
            >
                読み込んでいます…
            </p>

            <p
                v-else-if="error"
                class="py-6 text-center text-sm text-destructive"
                data-testid="history-error"
                role="alert"
            >
                {{ error }}
            </p>

            <p
                v-else-if="rows.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
                data-testid="history-empty"
            >
                履歴はまだありません。
            </p>

            <div v-else class="max-h-96 overflow-y-auto">
                <ul class="divide-y" data-testid="history-list">
                    <li
                        v-for="row in rows"
                        :key="row.revision"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium"
                                    >版 {{ row.revision }}</span
                                >
                                <Badge v-if="isCurrent(row)" variant="default"
                                    >現在</Badge
                                >
                                <Badge variant="secondary">{{
                                    reasonLabel(row.reason)
                                }}</Badge>
                                <Badge
                                    v-if="!row.readable"
                                    variant="destructive"
                                    data-testid="history-unreadable"
                                >
                                    記録を読めません
                                </Badge>
                            </div>
                            <!--
                                いつ・どこから・どの種類・誰が。
                                受け取っている記録は隠さず出す（すべて素のテキスト。
                                値が何であれ Vue の既定の書き出しで文字として出る）。
                            -->
                            <p class="text-xs text-muted-foreground">
                                {{ row.created_at ?? '日時不明' }} ／
                                {{ interfaceLabel(row.created_interface) }} ／
                                <span :data-testid="`history-kind-${row.revision}`">{{
                                    kindLabel(row.kind)
                                }}</span>
                                ／
                                <span :data-testid="`history-actor-${row.revision}`">{{
                                    actorLabel(row.created_by)
                                }}</span>
                                <template v-if="row.entry_updated_at">
                                    ／ 当時の更新日時
                                    {{ row.entry_updated_at }}
                                </template>
                            </p>
                        </div>
                        <Button
                            v-if="canWrite !== false && row.readable && !isCurrent(row)"
                            variant="secondary"
                            size="sm"
                            :data-testid="`history-restore-${row.revision}`"
                            @click="restore(row)"
                        >
                            この版へ戻す
                        </Button>
                    </li>
                </ul>
            </div>

            <p
                v-if="conflict"
                class="text-sm text-destructive"
                data-testid="history-conflict"
                role="alert"
                aria-live="polite"
            >
                {{ conflict }}
            </p>

            <DialogFooter>
                <Button variant="secondary" @click="emit('close')">閉じる</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmationDialog
        :confirmation="confirmation"
        confirm-label="この版へ戻す"
        @confirm="accept"
        @cancel="dismiss"
    />
</template>
