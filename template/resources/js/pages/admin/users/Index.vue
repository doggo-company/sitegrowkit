<script setup lang="ts">
/**
 * 利用者の一覧 — PHASE WP-BASELINE / STAGE #18A・SPEC §1A.10。
 *
 * 権限と状態は **色だけで表さず、文字でも示す**。
 * ここに出る操作はサーバー側の権限判定と同じ規則で出し分けている
 * （ボタンを隠すのは補助で、実際の可否は Application Service が決める）。
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/admin-datetime';
import { useAdminTimezone } from '@/composables/useAdminTimezone';
import type { AdminUser, PageMeta, UserFilterOptions } from '@/types/user';

const props = defineProps<{
    users: AdminUser[];
    meta: PageMeta;
    filters: { q: string; role: string; status: string };
    options: UserFilterOptions;
}>();

const query = ref({ ...props.filters });

function applyFilters() {
    router.get('/admin/users', query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get(
        '/admin/users',
        { ...query.value, page },
        { preserveState: true, preserveScroll: true },
    );
}

/*
 * 人が読む日時は `admin-datetime` を通す（WP 一周 10 周目 2026-09-04）。
 * #10 で 1 か所へまとめたが、この画面は通っていなかった
 * （`2026-09-04T12:16:56+09:00` がそのまま出ていた）。
 */
const timezone = useAdminTimezone();
</script>

<template>
    <Head title="利用者" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">利用者</h1>
                <p class="text-sm text-muted-foreground">
                    管理画面を使う人と、その権限を管理します。利用者は削除せず、無効化して入口を閉じます。
                </p>
            </div>
            <Button as-child>
                <Link href="/admin/users/create" prefetch>利用者を招待</Link>
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border p-4"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-2">
                <Label for="user-search">名前・メールアドレス</Label>
                <Input
                    id="user-search"
                    v-model="query.q"
                    class="w-56"
                    placeholder="検索"
                />
            </div>
            <div class="grid gap-2">
                <Label for="user-role">権限</Label>
                <select
                    id="user-role"
                    v-model="query.role"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="role in options.roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="user-status">状態</Label>
                <select
                    id="user-status"
                    v-model="query.status"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="status in options.statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
            <Button type="submit" variant="secondary">絞り込む</Button>
        </form>

        <!-- 横スクロールする領域はキーボードでも操作できるようにする（axe: scrollable-region-focusable） -->
        <div
            class="overflow-x-auto rounded-xl border"
            tabindex="0"
            role="region"
            aria-label="利用者の一覧"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            名前
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            メールアドレス
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            権限
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            状態
                        </th>
                        <th class="px-4 py-3 font-medium text-muted-foreground">
                            最終ログイン
                        </th>
                        <th class="w-40 px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-b last:border-0 hover:bg-muted/50"
                        data-testid="user-row"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/admin/users/${user.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ user.name }}
                            </Link>
                            <span
                                v-if="user.isSelf"
                                class="ml-2 text-xs text-muted-foreground"
                                >（自分）</span
                            >
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ user.email }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary">{{
                                user.roleLabel
                            }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    user.isActive ? 'outline' : 'destructive'
                                "
                            >
                                {{ user.statusLabel }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ formatDateTime(user.lastLoginAt, timezone) || '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="`/admin/users/${user.id}/activity`"
                                    >操作履歴</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td
                            class="px-4 py-6 text-center text-muted-foreground"
                            colspan="6"
                        >
                            条件に合う利用者がいません。
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="meta.lastPage > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page <= 1"
                @click="goToPage(meta.page - 1)"
            >
                前へ
            </Button>
            <span class="text-sm text-muted-foreground">
                {{ meta.page }} / {{ meta.lastPage }}（全 {{ meta.total }} 件）
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.page >= meta.lastPage"
                @click="goToPage(meta.page + 1)"
            >
                次へ
            </Button>
        </nav>
    </div>
</template>
