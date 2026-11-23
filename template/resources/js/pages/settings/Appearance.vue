<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '外観設定',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="外観設定" />

    <h1 class="sr-only">外観設定</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="外観設定"
            description="管理画面の表示テーマを変更します"
        />
        <AppearanceTabs />
    </div>
</template>
