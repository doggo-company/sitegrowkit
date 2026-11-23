<script setup lang="ts">
// 問い合わせ一覧（閲覧と状態フィルタのみ — submissions kind）
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types/schema';

interface Submission {
    id: number;
    name: string;
    email: string;
    subject: string;
    status: string;
    admin_notified_at: string | null;
    admin_notification_error: string | null;
    created_at: string;
}

defineProps<{
    items: Paginated<Submission>;
    status: string;
    statuses: { value: string; label: string }[];
}>();

const subjectLabels: Record<string, string> = {
    estimate: 'お見積もり',
    consultation: 'ご相談',
    other: 'その他',
};

type BadgeVariant = 'default' | 'outline' | 'destructive' | 'secondary';
const statusVariant = (s: string): BadgeVariant =>
    (({
        received: 'destructive',
        in_progress: 'default',
        completed: 'secondary',
        spam: 'outline',
    })[s] as BadgeVariant) ?? 'outline';

function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function filter(value: string) {
    router.get('/admin/contact', value ? { status: value } : {}, {
        preserveState: true,
    });
}
</script>

<template>
    <Head title="お問い合わせ" />

    <div class="flex flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">お問い合わせ</h1>

        <div class="flex flex-wrap gap-2">
            <Button
                :variant="status === '' ? 'default' : 'outline'"
                size="sm"
                @click="filter('')"
            >
                すべて
            </Button>
            <Button
                v-for="s in statuses"
                :key="s.value"
                :variant="status === s.value ? 'default' : 'outline'"
                size="sm"
                @click="filter(s.value)"
            >
                {{ s.label }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            受信日時
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            お名前
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            件名
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            通知
                        </th>
                        <th class="w-20 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items.data"
                        :key="item.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ item.created_at.replace('T', ' ').slice(0, 16) }}
                        </td>
                        <td class="px-4 py-3">{{ item.name }}</td>
                        <td class="px-4 py-3">
                            {{ subjectLabels[item.subject] ?? item.subject }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(item.status)">
                                {{
                                    statuses.find(
                                        (s) => s.value === item.status,
                                    )?.label ?? item.status
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="item.admin_notified_at"
                                class="text-xs text-muted-foreground"
                                >送信済み</span
                            >
                            <span
                                v-else-if="item.admin_notification_error"
                                class="text-xs text-destructive"
                                >失敗</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="`/admin/contact/${item.id}`"
                                    >詳細</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="items.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            お問い合わせはありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

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
