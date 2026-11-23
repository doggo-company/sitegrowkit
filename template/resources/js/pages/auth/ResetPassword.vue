<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        // 招待されて初めて設定する人も同じ画面を通る。見出しは「設定」（UX 一周 23 周目 #3）
        title: 'パスワードの設定',
        description: '新しいパスワードを入力してください',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
    /** 招待されて初めて設定する（UX 一周 23 周目 #3: 初めてなのに「再設定」と出た） */
    firstTime?: boolean;
}>();

const heading = computed(() =>
    props.firstTime ? 'パスワードの設定' : 'パスワードの再設定',
);

const inputEmail = ref(props.email);
</script>

<template>
    <Head :title="heading" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">メールアドレス</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">新しいパスワード</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="新しいパスワード"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation"
                    >新しいパスワード（確認）</Label
                >
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="新しいパスワードをもう一度入力"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                {{
                    props.firstTime
                        ? 'パスワードを設定する'
                        : 'パスワードを再設定'
                }}
            </Button>
        </div>
    </Form>
</template>
