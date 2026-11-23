<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
const sending = ref(false);
const error = ref('');
function open(): void {
    if (sending.value) {
return;
}

    error.value = '';
    sending.value = true;
    router.post(
        '/admin/starter-page',
        {},
        {
            onError: (errors) => {
 error.value = errors.starter ?? '見本を作成できませんでした。もう一度お試しください。'; 
},
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        class="rounded-lg border p-4"
        aria-labelledby="starter-page-heading"
        data-testid="starter-page-card"
    >
        <h2 id="starter-page-heading" class="font-semibold">
            写真と文章が入った見本から始める
        </h2>
        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
            「余白」は、店の紹介と商品への入口を先に伝える初期デザインです。架空のパン屋の見本を下書きで作り、写真と文章を編集できます。既存のトップは変わりません。作成済みなら、そのページを開きます。
        </p>
        <p v-if="error" role="alert" class="mt-2 text-sm text-destructive">{{ error }}</p>
        <Button class="mt-3" :aria-busy="sending" :disabled="sending" @click="open">{{
            sending ? '開いています…' : '見本の下書きを開く'
        }}</Button>
    </section>
</template>
