<script setup lang="ts">
/**
 * カスタムフィールド（Field Group）の一覧 — Phase 9.5-A / 9.5-B。
 *
 * **物理削除は無い。** アーカイブすると編集画面から見えなくなるだけで、
 * 定義も入力済みの値も残る。復元すれば元に戻る。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import type { FieldGroup } from '@/types/field-group';

const props = defineProps<{ groups: FieldGroup[]; installed: boolean }>();

const statusLabels: Record<string, string> = {
    draft: '下書き',
    published: '公開',
    archived: 'アーカイブ',
};

function statusLabel(status: string): string {
    return statusLabels[status] ?? `不明（${status}）`;
}

/** 並べ替えは **全件をまとめて**送る（部分送信だと触っていない束の位置が動く） */
const orderForm = useForm({ orderedIds: [] as string[] });

const ids = computed(() => props.groups.map((group) => group.id));

function move(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= ids.value.length) {
        return;
    }

    orderForm.orderedIds = ids.value.map((id, i) => {
        if (i === index) {
            return ids.value[target];
        }

        if (i === target) {
            return ids.value[index];
        }

        return id;
    });

    orderForm.post('/admin/field-groups/reorder', { preserveScroll: true });
}

/** アーカイブはサーバーの確認ゲートを通す（ブラウザの confirm は使わない） */
const archiving = { id: '' };

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        router.post(
            `/admin/field-groups/${archiving.id}/archive`,
            token ? { confirmation_token: token } : {},
            { preserveScroll: true },
        );
    },
);

function archive(id: string): void {
    archiving.id = id;
    request();
}

function restore(id: string): void {
    router.post(
        `/admin/field-groups/${id}/restore`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="カスタムフィールド" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">カスタムフィールド</h1>
                <p class="text-sm text-muted-foreground">
                    投稿タイプやフォームへまとめて割り当てる項目の束です。公開中の束だけが、割当先の編集画面に項目として現れます。
                </p>
            </div>
            <Button v-if="installed" as-child>
                <Link href="/admin/field-groups/create" prefetch
                    >カスタムフィールドを追加</Link
                >
            </Button>
        </div>

        <!-- 「まだ準備できていない」と「0 件」を混同させない -->
        <div
            v-if="!installed"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
            data-testid="field-groups-uninstalled"
        >
            カスタムフィールドの保存先がまだ準備できていません。データベースの更新（マイグレーション）を実行すると、この画面が使えるようになります。
        </div>

        <div
            v-else-if="groups.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
            data-testid="field-groups-empty"
        >
            カスタムフィールドはまだ 1
            つもありません。「カスタムフィールドを追加」から作成できます。
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
                            割当先
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            項目数
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="w-64 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(group, index) in groups"
                        :key="group.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                        data-testid="field-group-row"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ group.label }}
                            <p
                                v-if="!group.valid"
                                class="mt-1 text-xs font-normal text-destructive"
                                data-testid="field-group-invalid"
                            >
                                定義エラー: {{ group.invalidReason }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ group.key }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="group.locations.length === 0"
                                class="text-muted-foreground"
                                >未割り当て</span
                            >
                            <ul v-else class="flex flex-col gap-1">
                                <li
                                    v-for="(location, i) in group.locations"
                                    :key="`${location.kind}:${location.value}:${i}`"
                                    class="text-xs"
                                >
                                    <template v-if="location.kindLabel">
                                        {{ location.kindLabel }} /
                                        {{ location.label ?? location.value }}
                                        <span
                                            v-if="!location.label"
                                            class="text-muted-foreground"
                                            >（未解決）</span
                                        >
                                    </template>
                                    <!-- この版で扱えない割当先も黙って隠さない -->
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        data-testid="field-group-location-unsupported"
                                        >未対応の割当先（{{
                                            location.kind
                                        }}）</span
                                    >
                                </li>
                            </ul>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ group.fieldCount }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    group.active
                                        ? 'default'
                                        : group.valid
                                          ? 'secondary'
                                          : 'destructive'
                                "
                            >
                                {{ statusLabel(group.status) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === 0"
                                    data-testid="field-group-move-up"
                                    @click="move(index, -1)"
                                >
                                    上へ
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === groups.length - 1"
                                    data-testid="field-group-move-down"
                                    @click="move(index, 1)"
                                >
                                    下へ
                                </Button>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="`/admin/field-groups/${group.id}`"
                                        data-testid="field-group-edit-link"
                                        >編集</Link
                                    >
                                </Button>
                                <Button
                                    v-if="group.status === 'archived'"
                                    variant="outline"
                                    size="sm"
                                    data-testid="field-group-restore"
                                    @click="restore(group.id)"
                                >
                                    復元
                                </Button>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    data-testid="field-group-archive"
                                    @click="archive(group.id)"
                                >
                                    アーカイブ
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="installed" class="text-xs text-muted-foreground">
            アーカイブしても、定義と入力済みの値は残ります。削除する手段はありません。
        </p>

        <ConfirmationDialog
            :confirmation="confirmation"
            confirm-label="アーカイブする"
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>
