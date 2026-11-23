<script setup lang="ts">
/**
 * 404 の記録（Phase 9.10 / STAGE 23D・SPEC §G・§H.2）。
 *
 * **ここからルールを作れることが本命である。** ゴミ箱へ入れた投稿や、
 * 外部サイトに残った古いリンクは、どこへ転送すべきかを CMS が知らない。
 * だが「どの URL が踏まれているか」は分かる。人は転送先だけ決めればよい。
 *
 * IP は画面に出さない。記録が在ることだけ分かれば足りる。
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Log {
    id: number;
    path: string;
    last_query: string | null;
    last_referrer: string | null;
    last_user_agent: string | null;
    has_ip: boolean;
    hit_count: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    resolved_at: string | null;
}

const props = defineProps<{
    logs: Log[];
    meta: { total: number; includeResolved: boolean };
    canWrite?: boolean;
}>();

const writable = props.canWrite !== false;
const target = ref<Log | null>(null);

const form = useForm({ target_url: '', status_code: 301 });

function start(log: Log) {
    target.value = log;
    form.clearErrors();
    form.target_url = '';
}

function submit() {
    const log = target.value;

    if (!log) {
        return;
    }

    form.post(`/admin/not-found/${log.id}/resolve`, {
        preserveScroll: true,
        onSuccess: () => {
            target.value = null;
        },
    });
}

function toggleResolved() {
    router.get(
        '/admin/not-found',
        { includeResolved: props.meta.includeResolved ? undefined : 1 },
        { preserveScroll: true },
    );
}

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="見つからなかった URL" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">見つからなかった URL</h1>
                <p class="text-sm text-muted-foreground">
                    404 になった URL です。同じ URL は 1 行にまとまります。
                    よく踏まれているものから直すと効果があります。
                </p>
            </div>
            <Button
                variant="secondary"
                data-testid="not-found-toggle-resolved"
                @click="toggleResolved"
            >
                {{ meta.includeResolved ? '未対応だけ表示' : '対応済みも表示' }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">URL</th>
                        <th class="px-4 py-2 font-medium">回数</th>
                        <th class="px-4 py-2 font-medium">最後に見た</th>
                        <th class="px-4 py-2 font-medium">参照元</th>
                        <th class="px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="log in logs"
                        :key="log.id"
                        data-testid="not-found-row"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ log.path }}
                            <Badge
                                v-if="log.resolved_at"
                                variant="secondary"
                                class="ml-1"
                            >
                                対応済み
                            </Badge>
                        </td>
                        <td class="px-4 py-3">{{ log.hit_count }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ log.last_seen_at ?? '—' }}
                        </td>
                        <td
                            class="max-w-64 truncate px-4 py-3 text-xs text-muted-foreground"
                        >
                            {{ log.last_referrer ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="writable && !log.resolved_at"
                                variant="ghost"
                                size="sm"
                                data-testid="not-found-resolve"
                                @click="start(log)"
                            >
                                転送を作る
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="logs.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                            data-testid="not-found-empty"
                        >
                            記録はありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 記録から 1 操作でルールを作る -->
        <section
            v-if="target"
            class="rounded-lg border p-4"
            aria-labelledby="resolve-heading"
            data-testid="not-found-form"
        >
            <h2 id="resolve-heading" class="text-sm font-medium">
                <span class="font-mono">{{ target.path }}</span>
                の転送先を決める
            </h2>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="grid gap-1.5">
                    <Label for="resolve-target">転送先</Label>
                    <Input
                        id="resolve-target"
                        v-model="form.target_url"
                        class="w-72"
                        placeholder="/new-page"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="resolve-status">転送の種類</Label>
                    <select
                        id="resolve-status"
                        v-model.number="form.status_code"
                        class="h-9 rounded-md border px-2 text-sm"
                    >
                        <option :value="301">301 恒久的に移動</option>
                        <option :value="302">302 一時的に移動</option>
                    </select>
                </div>
                <Button data-testid="not-found-save" @click="submit"
                    >作る</Button
                >
                <Button
                    variant="secondary"
                    data-testid="not-found-cancel"
                    @click="target = null"
                >
                    やめる
                </Button>
            </div>

            <p
                v-if="form.errors.target_url"
                class="mt-2 text-sm text-destructive"
                role="alert"
                aria-live="polite"
                data-testid="not-found-error"
            >
                {{ form.errors.target_url }}
            </p>
        </section>
    </div>
</template>
