<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
/**
 * 利用者の招待 — PHASE WP-BASELINE / STAGE #18A・SPEC §1A.11。
 *
 * **パスワードは入力させないし、表示もしない。** 招待メールのリンクから
 * 本人がパスワードを設定する。
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UserFilterOptions } from '@/types/user';

const props = defineProps<{ options: UserFilterOptions }>();

const form = useForm({
    name: '',
    email: '',
    role: props.options.roles.at(-1)?.value ?? 'viewer',
    /*
     * 受け渡し方法 — STAGE #18B。
     *
     * 既定はメール。**届く相手にはメールが一番安全**で、
     * 管理者の手元に URL が残らない。
     * 手動は「メールが使えない・届かない」ときの逃げ道として用意する。
     */
    deliveryMethod: 'email',
});

const deliveryMethods = [
    {
        value: 'email',
        label: 'メールで招待する（推奨）',
        description:
            '本人のメールアドレスへパスワード設定の案内を送ります。URL は管理者の画面に残りません。',
    },
    {
        value: 'manual',
        label: 'URL を受け取って自分で渡す',
        description:
            'メールは送りません。パスワード設定 URL をこの操作の直後に 1 度だけ表示するので、LINE など本人だけが見られる経路で渡してください。',
    },
];

// 保存していない変更があれば、離れる前に画面の中の窓で確かめる（UX 一周 14 周目 R14-2・R14-3）
useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head title="利用者を招待" />

    <form
        class="flex max-w-2xl flex-col gap-6 p-4"
        @submit.prevent="form.post('/admin/users')"
    >
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">利用者を招待</h1>
            <p class="text-sm text-muted-foreground">
                パスワードは本人が設定します。こちらでパスワードを決めることはできません。受け渡し方法は下で選べます。
            </p>
        </div>

        <div class="grid gap-4 rounded-xl border p-4">
            <div class="grid gap-2">
                <Label for="name"
                    >名前 <span class="text-destructive">*</span></Label
                >
                <Input id="name" v-model="form.name" maxlength="120" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email"
                    >メールアドレス
                    <span class="text-destructive">*</span></Label
                >
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    maxlength="255"
                />
                <InputError :message="form.errors.email" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">
                    権限 <span class="text-destructive">*</span>
                </legend>
                <label
                    v-for="role in options.roles"
                    :key="role.value"
                    class="flex items-start gap-3 rounded-md border p-3 text-sm"
                >
                    <input
                        v-model="form.role"
                        type="radio"
                        name="role"
                        class="mt-1 size-4 accent-primary"
                        :value="role.value"
                    />
                    <span>
                        <span class="font-medium">{{ role.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            role.description
                        }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.role" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">
                    受け渡し方法 <span class="text-destructive">*</span>
                </legend>
                <label
                    v-for="method in deliveryMethods"
                    :key="method.value"
                    class="flex items-start gap-3 rounded-md border p-3 text-sm"
                >
                    <input
                        v-model="form.deliveryMethod"
                        type="radio"
                        name="deliveryMethod"
                        class="mt-1 size-4 accent-primary"
                        :value="method.value"
                    />
                    <span>
                        <span class="font-medium">{{ method.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            method.description
                        }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.deliveryMethod" />
            </fieldset>
        </div>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                {{
                    form.deliveryMethod === 'manual'
                        ? '利用者を作成して URL を表示'
                        : '招待メールを送る'
                }}
            </Button>
            <Button variant="secondary" as-child>
                <Link href="/admin/users">キャンセル</Link>
            </Button>
        </div>
    </form>
</template>
