<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    pageId: number;
    offer?: boolean;
    selected?: boolean;
}>();
const busy = ref(false);
const error = ref('');

function makeFront(): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    router.post(`/admin/sample-pages/home/front/${props.pageId}`, {}, {
        preserveScroll: true,
        onError: (errors) => {
            error.value = String(Object.values(errors)[0] ?? 'トップページにできませんでした');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}
</script>

<template>
    <div v-if="selected" class="border p-3 text-sm" data-testid="page-front-selected">
        トップページに表示中
        <Link href="/" class="ml-3 underline" target="_blank" rel="noopener">トップページを見る</Link>
    </div>
    <div v-else-if="offer" class="flex flex-wrap items-center gap-3 border p-3 text-sm" data-testid="page-front-offer">
        <p class="flex-1">公開したこのページを、サイトのトップ（/）に表示できます。</p>
        <Button size="sm" :disabled="busy" data-testid="page-make-front" @click="makeFront">
            このページをトップページにする
        </Button>
        <p v-if="error" role="alert" class="w-full text-destructive">{{ error }}</p>
    </div>
</template>
