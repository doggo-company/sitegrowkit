<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <!--
        マーク自体が円形バッジとして完成しているため、以前の暗い角丸コンテナは外す
        （Phase 8.5-F / F-UX1）。商品名は SiteGrowKit、「育てるCMS。」は supporting copy。
    -->
    <AppLogoIcon class="size-8 shrink-0" alt="SiteGrowKit" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
