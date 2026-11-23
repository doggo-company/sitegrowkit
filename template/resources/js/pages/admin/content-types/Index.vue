<script setup lang="ts">
/**
 * 投稿タイプ（Content Type）の一覧 — Phase 9.4 / STAGE #17A、Phase 9.7 / STAGE 20C。
 *
 * 一覧は **実行時の登録簿**から来る（news / works を画面へ焼き込まない）。
 * code 管理の型も表示するが、編集と無効化はできない（リポジトリのファイルが正）。
 *
 * **「削除」は無い。** 無効にすると管理メニューから外れ、投稿を保存できなくなるだけで、
 * 定義・テーブル・投稿・分類の割り当て・メディアの参照・操作履歴は残る。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type {
    ContentTypeDefinition,
    ContentTypeReadiness,
} from '@/types/content-type';

const props = defineProps<{
    types: ContentTypeDefinition[];
    readiness: ContentTypeReadiness;
    readinessMessage: string | null;
}>();

const statusLabels: Record<ContentTypeDefinition['status'], string> = {
    ready: '利用可能',
    missing_table: 'テーブル未作成',
    no_admin_route: '管理画面なし',
    unavailable: '利用不可',
    orphan_settings: '定義が見つかりません',
};

/** 登録の進行状態。**利用者が動かす運用状態とは別の軸** */
const lifecycleLabels: Record<string, string> = {
    ready: '',
    provisioning: '作成が完了していません',
    deprovisioning: '削除の途中で止まっています',
    tombstoned: '削除済み',
};

function lifecycleLabel(value: string): string {
    return lifecycleLabels[value] ?? `不明な状態（${value}）`;
}

/** 並べ替えの対象は投稿タイプだけ（定義が見つからない行は動かせない） */
const reorderable = computed(() =>
    props.types.filter((type) => type.status !== 'orphan_settings'),
);

/** 操作の結果を読み上げる（キーボードだけで使う人に、何が起きたかを伝える） */
const announcement = ref('');

/**
 * 並べ替えは **全件をまとめて**送る。
 * 差分で送ると、送信の間に他者が並べ替えたときに意味が変わってしまう。
 */
const orderForm = useForm({
    items: [] as { id: string; lock_version: number }[],
});

function move(index: number, delta: number): void {
    const list = [...reorderable.value];
    const target = index + delta;

    if (target < 0 || target >= list.length) {
        return;
    }

    const moved = list[index];
    list[index] = list[target];
    list[target] = moved;

    orderForm.items = list.map((type) => ({
        id: type.id,
        lock_version: type.settings.lockVersion,
    }));

    orderForm.post('/admin/content-types/reorder', {
        preserveScroll: true,
        onSuccess: () => {
            announcement.value = `${moved.label} を ${target + 1} 番目に移動しました`;
        },
    });
}

/**
 * 確認ダイアログは **1 画面に 1 つ**。
 * 2 つ置くとどちらの確認でも両方が開き、支援技術からはどちらも見えなくなる。
 * どの操作の確認かは `pending` が持ち、**不明なら何も送らない**。
 */
const pending = ref<{ action: 'archive'; type: ContentTypeDefinition } | null>(
    null,
);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const target = pending.value;

        if (target === null) {
            return;
        }

        router.post(
            `/admin/content-types/${target.type.id}/${target.action}`,
            {
                lock_version: target.type.settings.lockVersion,
                ...(token ? { confirmation_token: token } : {}),
            },
            {
                preserveScroll: true,
                /*
                 * **`preserveState` が要る。** 1 回目の送信は確認を求められて
                 * 同じ画面へ戻るが、既定ではその往復で component が作り直され、
                 * `pending` が null に戻る。そうなると確認ボタンを押しても
                 * 「どの操作か分からない」ので何も送らない（fail-closed が正しく働き、
                 * 利用者から見ると「承認したのに何も起きない」になる）。
                 */
                preserveState: true,
                /*
                 * **1 回目の往復も「成功」である。** 確認を求められて同じ画面へ戻るのも
                 * Inertia から見れば成功した visit なので、ここで無条件に片付けると
                 * 確認ボタンを押したときに送る相手が居なくなる。
                 * 片付けるのは **token を付けて送ったとき（= 実際に適用したとき）だけ**。
                 */
                onSuccess: () => {
                    if (token === null) {
                        return;
                    }

                    announcement.value = `${target.type.label} を無効にしました`;
                    pending.value = null;
                },
            },
        );
    },
);

function archive(type: ContentTypeDefinition): void {
    pending.value = { action: 'archive', type };
    request();
}

function cancel(): void {
    pending.value = null;
    dismiss();
}

/** 復元は値を失わないので確認を挟まない（取り返しのつく操作に同じ手数を求めない） */
function restore(type: ContentTypeDefinition): void {
    router.post(
        `/admin/content-types/${type.id}/restore`,
        { lock_version: type.settings.lockVersion },
        {
            preserveScroll: true,
            onSuccess: () => {
                announcement.value = `${type.label} を元に戻しました`;
            },
        },
    );
}
</script>

<template>
    <Head title="投稿タイプ" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">投稿タイプ</h1>
                <p class="text-sm text-muted-foreground">
                    お知らせや実績のような投稿の種類を管理します。追加した投稿タイプは、すぐに管理画面・API・AI
                    連携から使えます。
                </p>
            </div>
            <Button as-child>
                <Link href="/admin/content-types/create" prefetch
                    >投稿タイプを追加</Link
                >
            </Button>
        </div>

        <!-- 設定を読めなかったことを黙って隠さない（一覧は落とさない） -->
        <div
            v-if="readiness !== 'ready'"
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
            data-testid="content-types-readiness"
        >
            {{ readinessMessage }}
        </div>

        <p aria-live="polite" class="sr-only" data-testid="content-types-live">
            {{ announcement }}
        </p>

        <div
            v-if="types.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
            data-testid="content-types-empty"
        >
            投稿タイプはまだ 1 つもありません。「投稿タイプを追加」から作成できます。
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            表示名
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            ID
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            保存先テーブル
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            管理
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            公開スラッグ
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            並び
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            投稿件数
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            サイドバー
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="w-72 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(type, index) in types"
                        :key="type.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                        data-testid="content-type-row"
                        :data-content-type="type.id"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ type.label }}
                            <p
                                v-if="type.settings.unrecognized.length > 0"
                                class="mt-1 text-xs font-normal text-destructive"
                                data-testid="content-type-unrecognized"
                            >
                                読めない設定:
                                {{ type.settings.unrecognized.join('、') }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ type.id }}
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ type.table ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <!-- code 管理はリポジトリのファイルが正。この画面からは変更できない -->
                            <Badge
                                :variant="
                                    type.managedBy === 'cms'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    type.managedBy === 'cms'
                                        ? 'CMS 管理'
                                        : 'コード管理（読み取り専用）'
                                }}
                            </Badge>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                            data-testid="content-type-slug"
                        >
                            {{ type.settings.publicSlug ?? '—' }}
                        </td>
                        <td
                            class="px-4 py-3 tabular-nums"
                            data-testid="content-type-order"
                        >
                            {{
                                type.settings.menuOrder === 0
                                    ? '未設定'
                                    : type.settings.menuOrder
                            }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ type.entryCount ?? '—' }}
                        </td>
                        <!--
                            サイドバーに出すかは**設定で切り替えられる**（B-39 (3)）。
                            機能は前から在ったが、この一覧に出ていなかったので
                            「隠せると分からない」状態だった。列にして見つけられるようにする。
                        -->
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    type.capabilities.showInMenu
                                        ? 'outline'
                                        : 'secondary'
                                "
                                data-testid="content-type-show-in-menu"
                            >
                                {{
                                    type.capabilities.showInMenu
                                        ? '出す'
                                        : '出さない'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col items-start gap-1">
                                <Badge
                                    :variant="
                                        type.settings.status === 'archived'
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    data-testid="content-type-status"
                                >
                                    {{
                                        type.settings.status === 'archived'
                                            ? '無効'
                                            : statusLabels[type.status]
                                    }}
                                </Badge>
                                <span
                                    v-if="type.settings.lifecycle !== 'ready'"
                                    class="text-xs text-destructive"
                                    data-testid="content-type-lifecycle"
                                >
                                    {{ lifecycleLabel(type.settings.lifecycle) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === 0"
                                    data-testid="content-type-move-up"
                                    @click="move(index, -1)"
                                >
                                    上へ
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === types.length - 1"
                                    data-testid="content-type-move-down"
                                    @click="move(index, 1)"
                                >
                                    下へ
                                </Button>
                                <Button
                                    v-if="type.adminUrl"
                                    variant="outline"
                                    size="sm"
                                    as-child
                                >
                                    <Link
                                        :href="type.adminUrl"
                                        data-testid="content-type-entries-link"
                                        >投稿一覧</Link
                                    >
                                </Button>
                                <Button
                                    v-if="type.editable"
                                    variant="outline"
                                    size="sm"
                                    as-child
                                >
                                    <Link
                                        :href="`/admin/content-types/${type.id}/edit`"
                                        data-testid="content-type-edit-link"
                                        >設定</Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        type.editable &&
                                        type.settings.status === 'archived'
                                    "
                                    variant="outline"
                                    size="sm"
                                    data-testid="content-type-restore"
                                    @click="restore(type)"
                                >
                                    元に戻す
                                </Button>
                                <Button
                                    v-else-if="type.editable"
                                    variant="ghost"
                                    size="sm"
                                    data-testid="content-type-archive"
                                    @click="archive(type)"
                                >
                                    無効にする
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            無効にしても、投稿・分類の割り当て・メディアの参照・操作履歴は残ります。投稿タイプを完全に削除する手段はまだありません。
        </p>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="無効にする"
            @confirm="accept"
            @cancel="cancel"
        />
    </div>
</template>
