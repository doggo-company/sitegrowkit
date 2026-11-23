<script setup lang="ts">
/**
 * 分類の一覧（Phase 9.3B / #16・SPEC §R、Phase 9.7 / STAGE 20D）。
 *
 * **コード管理の分類はここから変えられない**（定義ファイルが正）。
 * 管理画面で作った分類は、作成・編集・無効化・復元・関連付け・
 * 「一度も使っていない定義の削除」まで行える。
 *
 * **「削除」は無効化ではない。** 無効にしても項目も割り当ても残るが、
 * 削除は本当に消える — だから一度でも使った分類には出さない。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { AdminTaxonomy } from '@/types/taxonomy';
import { useAdminCapability } from '@/composables/useAdminCapability';

const canManage = useAdminCapability('content_type.manage');

defineProps<{ taxonomies: AdminTaxonomy[] }>();

/** 操作の結果を読み上げる（キーボードだけで使う人に、何が起きたかを伝える） */
const announcement = ref('');

/**
 * 確認ダイアログは **1 画面に 1 つ**。どの操作かは `pending` が持ち、
 * **不明なら何も送らない**。
 */
const pending = ref<{
    action: 'archive' | 'remove';
    taxonomy: AdminTaxonomy;
} | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const target = pending.value;

        if (target === null) {
            return;
        }

        router.post(
            `/admin/taxonomies/${target.taxonomy.key}/${target.action}`,
            {
                lock_version: target.taxonomy.lockVersion,
                ...(token ? { confirmation_token: token } : {}),
            },
            {
                preserveScroll: true,
                // 1 回目の往復も成功した visit なので、**token を付けたときだけ**片付ける
                preserveState: true,
                onSuccess: () => {
                    if (token === null) {
                        return;
                    }

                    announcement.value =
                        target.action === 'archive'
                            ? `${target.taxonomy.label} を無効にしました`
                            : `${target.taxonomy.label} を削除しました`;
                    pending.value = null;
                },
            },
        );
    },
);

function archive(taxonomy: AdminTaxonomy): void {
    pending.value = { action: 'archive', taxonomy };
    request();
}

function remove(taxonomy: AdminTaxonomy): void {
    pending.value = { action: 'remove', taxonomy };
    request();
}

function cancel(): void {
    pending.value = null;
    dismiss();
}

/** 復元は値を失わないので確認を挟まない */
function restore(taxonomy: AdminTaxonomy): void {
    router.post(
        `/admin/taxonomies/${taxonomy.key}/restore`,
        { lock_version: taxonomy.lockVersion },
        {
            preserveScroll: true,
            onSuccess: () => {
                announcement.value = `${taxonomy.label} を元に戻しました`;
            },
        },
    );
}

/** 一度も使っていない定義だけ削除できる（サーバーが最終判定を持つ） */
function deletable(taxonomy: AdminTaxonomy): boolean {
    return (
        taxonomy.managedBy === 'cms' &&
        taxonomy.termCount === 0 &&
        taxonomy.contentTypes.length === 0
    );
}
</script>

<template>
    <Head title="分類管理" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">分類管理</h1>
                <p class="text-sm text-muted-foreground">
                    コンテンツに付けるカテゴリやタグを管理します。コード管理の分類は、定義ファイルが正なのでここからは変更できません。
                </p>
            </div>
            <Button v-if="canManage" as-child>
                <Link href="/admin/taxonomies/create" prefetch>分類を追加</Link>
            </Button>
        </div>

        <p aria-live="polite" class="sr-only" data-testid="taxonomies-live">
            {{ announcement }}
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            分類
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            キー
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            管理
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            項目数
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            関連する投稿タイプ
                        </th>
                        <th class="w-72 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="taxonomy in taxonomies"
                        :key="taxonomy.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                        data-testid="taxonomy-row"
                        :data-taxonomy="taxonomy.key"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/admin/taxonomies/${taxonomy.key}`"
                                class="font-medium underline-offset-4 hover:underline"
                                data-testid="taxonomy-link"
                            >
                                {{ taxonomy.label }}
                            </Link>
                            <p
                                v-if="taxonomy.description"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ taxonomy.description }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ taxonomy.key }}
                        </td>
                        <td class="px-4 py-3">
                            <!-- managed_by = code は label / key を read-only 表示する（§R） -->
                            <Badge
                                :variant="
                                    taxonomy.managedBy === 'code'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    taxonomy.managedBy === 'code'
                                        ? 'コード管理（変更不可）'
                                        : 'CMS 管理'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    taxonomy.status === 'archived'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                data-testid="taxonomy-status"
                            >
                                {{
                                    taxonomy.status === 'archived'
                                        ? '無効'
                                        : '有効'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ taxonomy.activeTermCount }} 件
                            <span
                                v-if="taxonomy.archivedTermCount > 0"
                                class="text-muted-foreground"
                            >
                                （アーカイブ済み
                                {{ taxonomy.archivedTermCount }} 件）
                            </span>
                        </td>
                        <td
                            class="px-4 py-3 text-xs"
                            data-testid="taxonomy-content-types"
                        >
                            <span
                                v-if="taxonomy.contentTypes.length === 0"
                                class="text-muted-foreground"
                                >未関連</span
                            >
                            <span v-else class="font-mono">{{
                                taxonomy.contentTypes.join('、')
                            }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link
                                        :href="`/admin/taxonomies/${taxonomy.key}`"
                                        >項目を管理</Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        canManage &&
                                        taxonomy.managedBy === 'cms'
                                    "
                                    variant="outline"
                                    size="sm"
                                    as-child
                                >
                                    <Link
                                        :href="`/admin/taxonomies/${taxonomy.key}/edit`"
                                        data-testid="taxonomy-edit-link"
                                        >設定</Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        canManage &&
                                        taxonomy.managedBy === 'cms' &&
                                        taxonomy.status === 'archived'
                                    "
                                    variant="outline"
                                    size="sm"
                                    data-testid="taxonomy-restore"
                                    @click="restore(taxonomy)"
                                >
                                    元に戻す
                                </Button>
                                <Button
                                    v-else-if="
                                        canManage &&
                                        taxonomy.managedBy === 'cms'
                                    "
                                    variant="ghost"
                                    size="sm"
                                    data-testid="taxonomy-archive"
                                    @click="archive(taxonomy)"
                                >
                                    無効にする
                                </Button>
                                <Button
                                    v-if="canManage && deletable(taxonomy)"
                                    variant="ghost"
                                    size="sm"
                                    data-testid="taxonomy-remove"
                                    @click="remove(taxonomy)"
                                >
                                    削除
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="taxonomies.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="taxonomies-empty"
                        >
                            分類がまだ登録されていません。「分類を追加」から作成できます。
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            無効にしても、項目・投稿に付いている分類・投稿タイプとの関連付けは残ります。削除できるのは、一度も使っていない分類だけです。
        </p>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="実行する"
            @confirm="accept"
            @cancel="cancel"
        />
    </div>
</template>
