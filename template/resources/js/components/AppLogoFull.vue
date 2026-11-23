<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * SiteGrowKit のフルロゴ（Phase 8.5-F / F-UX1）。
 *
 * owner 選定の raster concept artwork（Bernese + AGENT CMS KIT + CMS blocks +
 * sprout + 育てるCMS。）を crop / 透過化 / リサイズしただけの暫定アセット。
 * 造形は変更していない（最終 vector ロゴではない）。
 *
 * light / dark は `.dark` クラスで切り替える（手動テーマ切替に追従させるため）。
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        className?: HTMLAttributes['class'];
        alt?: string;
    }>(),
    { alt: 'SiteGrowKit — 育てるCMS。' },
);
</script>

<template>
    <span :class="cn('inline-flex', props.className)" v-bind="$attrs">
        <img
            src="/brand/sitegrowkit-logo.png"
            :alt="props.alt"
            class="h-full w-full object-contain dark:hidden"
            decoding="async"
        />
        <img
            src="/brand/sitegrowkit-logo-dark.png"
            alt=""
            aria-hidden="true"
            class="hidden h-full w-full object-contain dark:block"
            decoding="async"
        />
    </span>
</template>
