<script setup lang="ts">
/**
 * メニューの一覧（Phase 9.9 / STAGE 22D・SPEC §F）。
 *
 * テーマが使っている本（`code_managed`）には印を付け、削除の導線を出さない。
 * 出しておいて押したら断る、という作りにしない。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';

interface MenuRow {
    id: number;
    key: string;
    name: string;
    description: string | null;
    code_managed: boolean;
    item_count: number;
}

const props = defineProps<{ menus: MenuRow[]; canWrite?: boolean }>();

const writable = props.canWrite !== false;
const pending = ref<MenuRow | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation((token) => {
    const menu = pending.value;

    if (!menu) {
        return;
    }

    router.post(
        `/admin/menus/${menu.key}/delete`,
        { ...(token ? { confirmation_token: token } : {}) },
        { preserveScroll: true, preserveState: token === null },
    );
});

function remove(menu: MenuRow) {
    pending.value = menu;
    request();
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'サイトのメニュー', href: '/admin/menus' }],
    },
});
</script>

<template>
    <Head title="サイトのメニュー" />
        <div class="flex flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">サイトのメニュー</h1>
                <p class="text-sm text-muted-foreground">
                    サイトのヘッダーやフッターに出す導線を編集します。
                </p>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">名前</th>
                            <th class="px-4 py-2 font-medium">識別子</th>
                            <th class="px-4 py-2 font-medium">項目数</th>
                            <th class="px-4 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="menu in menus" :key="menu.id" data-testid="menu-row">
                            <td class="px-4 py-3">
                                <Link
                                    class="font-medium underline-offset-4 hover:underline"
                                    :href="`/admin/menus/${menu.key}`"
                                    >{{ menu.name }}</Link
                                >
                                <Badge
                                    v-if="menu.code_managed"
                                    variant="secondary"
                                    class="ml-2"
                                    data-testid="menu-code-managed"
                                >
                                    テーマが使用中
                                </Badge>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                                {{ menu.key }}
                            </td>
                            <td class="px-4 py-3">{{ menu.item_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="writable && !menu.code_managed"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    data-testid="menu-delete"
                                    @click="remove(menu)"
                                >
                                    削除
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="menus.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                                data-testid="menu-empty"
                            >
                                メニューがありません
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmationDialog
            :confirmation="confirmation"
            @confirm="accept"
            @cancel="dismiss"
        />
</template>
