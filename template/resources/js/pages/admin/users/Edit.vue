<script setup lang="ts">
/**
 * 利用者の詳細 — PHASE WP-BASELINE / STAGE #18A。
 *
 * 基本情報・権限・有効状態を **別々の操作**として扱う
 * （同じフォームから権限が動くと、確認と履歴の粒度が合わなくなる）。
 * 権限変更と無効化はサーバーの ConfirmationGate を通す。
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import ConfirmationDialog from '@/components/ConfirmationDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOperationConfirmation } from '@/composables/useOperationConfirmation';
import { formatDateTime } from '@/lib/admin-datetime';
import { useAdminTimezone } from '@/composables/useAdminTimezone';
import type { AdminUser, UserFilterOptions } from '@/types/user';

const props = defineProps<{ user: AdminUser; options: UserFilterOptions }>();

const profile = useForm({ name: props.user.name, email: props.user.email });
const nextRole = ref(props.user.role);

/** 権限変更と無効化は、どちらもサーバーが発行した確認トークンを添えて再送する */
const pending = ref<'role' | 'deactivate' | null>(null);

const { confirmation, request, accept, dismiss } = useOperationConfirmation(
    (token) => {
        const data = token ? { confirmation_token: token } : {};

        if (pending.value === 'role') {
            router.post(
                `/admin/users/${props.user.id}/role`,
                { ...data, role: nextRole.value },
                { preserveScroll: true },
            );

            return;
        }

        router.post(`/admin/users/${props.user.id}/deactivate`, data, {
            preserveScroll: true,
        });
    },
);

function changeRole() {
    pending.value = 'role';
    request();
}

function deactivate() {
    pending.value = 'deactivate';
    request();
}

/*
 * 人が読む日時は `admin-datetime` を通す（WP 一周 10 周目 2026-09-04）。
 * #10 で 1 か所へまとめたが、この画面は通っていなかった
 * （`2026-09-04T12:16:56+09:00` がそのまま出ていた）。
 */
const timezone = useAdminTimezone();

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => profile.isDirty && !profile.processing);
</script>

<template>
    <Head :title="user.name" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ user.name }}</h1>
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="secondary">{{ user.roleLabel }}</Badge>
                <Badge :variant="user.isActive ? 'outline' : 'destructive'">{{
                    user.statusLabel
                }}</Badge>
                <span class="text-muted-foreground">{{ user.email }}</span>
            </p>
            <p
                v-if="user.isLastActiveOwner"
                class="text-sm text-muted-foreground"
            >
                この利用者は最後のオーナーです。権限の変更と無効化はできません。
            </p>
        </div>

        <section class="grid gap-4 rounded-xl border p-4">
            <h2 class="text-base font-semibold">基本情報</h2>
            <form
                class="grid gap-4"
                @submit.prevent="profile.patch(`/admin/users/${user.id}`)"
            >
                <div class="grid gap-2">
                    <Label for="name">名前</Label>
                    <Input id="name" v-model="profile.name" maxlength="120" />
                    <InputError :message="profile.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">メールアドレス</Label>
                    <Input
                        id="email"
                        v-model="profile.email"
                        type="email"
                        maxlength="255"
                    />
                    <InputError :message="profile.errors.email" />
                </div>
                <div>
                    <Button type="submit" :disabled="profile.processing"
                        >保存</Button
                    >
                </div>
            </form>
        </section>

        <section class="grid gap-4 rounded-xl border p-4">
            <h2 class="text-base font-semibold">権限</h2>
            <p class="text-sm text-muted-foreground">
                権限を変えると、この利用者はいったんログアウトされます。
            </p>
            <div class="grid gap-2">
                <Label for="role">権限</Label>
                <select
                    id="role"
                    v-model="nextRole"
                    class="h-9 w-56 rounded-md border border-input bg-transparent px-3 text-sm"
                    :disabled="!user.canChangeRole"
                >
                    <option
                        v-for="role in options.roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </select>
            </div>
            <div>
                <Button
                    variant="secondary"
                    :disabled="!user.canChangeRole || nextRole === user.role"
                    @click="changeRole"
                >
                    権限を変更
                </Button>
            </div>
        </section>

        <section class="grid gap-4 rounded-xl border p-4">
            <h2 class="text-base font-semibold">利用状況</h2>
            <dl class="grid gap-1 text-sm sm:grid-cols-2">
                <div>
                    <dt class="inline text-muted-foreground">招待日時：</dt>
                    <dd class="inline">{{ user.invitedAt ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">最終ログイン：</dt>
                    <dd class="inline">
                        {{ formatDateTime(user.lastLoginAt, timezone) || '—' }}
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="`/admin/users/${user.id}/activity`"
                        >操作履歴を見る</Link
                    >
                </Button>
                <Button
                    v-if="user.pendingInvitation && user.isActive"
                    variant="outline"
                    @click="
                        router.post(`/admin/users/${user.id}/invitation`, {})
                    "
                >
                    招待メールを再送
                </Button>
                <!--
                    メールを使わずに渡すための URL（STAGE #18B）。
                    発行し直すと前に渡した URL は使えなくなる
                -->
                <Button
                    v-if="user.pendingInvitation && user.isActive"
                    variant="outline"
                    @click="
                        router.post(
                            `/admin/users/${user.id}/setup-link`,
                            {},
                            { preserveScroll: true },
                        )
                    "
                >
                    パスワード設定 URL を発行
                </Button>
                <Button
                    v-if="user.canReactivate"
                    variant="secondary"
                    @click="
                        router.post(`/admin/users/${user.id}/reactivate`, {})
                    "
                >
                    再び有効にする
                </Button>
                <Button
                    v-else
                    variant="destructive"
                    :disabled="!user.canDeactivate"
                    @click="deactivate"
                >
                    無効化する
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                利用者は削除しません。無効化すると、ログインできなくなり、開いている画面からもログアウトされます。過去の操作履歴は残ります。
            </p>
        </section>

        <ConfirmationDialog
            :confirmation="confirmation"
            :confirm-label="
                pending === 'role' ? '権限を変更する' : '無効化する'
            "
            @confirm="accept"
            @cancel="dismiss"
        />
    </div>
</template>
