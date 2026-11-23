<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * SiteGrowKit のマーク（Phase 8.5-F / F-UX1）。
 *
 * owner 選定の raster concept artwork（Bernese + CMS blocks + sprout）を
 * **そのまま**使用する暫定アセット。crop / 透過化 / リサイズ / light・dark 用の
 * 色調整のみで、造形は変更していない（最終 vector ロゴではない）。
 *
 * light / dark は `prefers-color-scheme` ではなく **`.dark` クラス**で切り替える
 * （管理画面のテーマは手動切替もあるため）。
 * 装飾として置く場合は呼び出し側で `alt=""` を渡す。
 */
defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        className?: HTMLAttributes['class'];
        alt?: string;
    }>(),
    { alt: 'SiteGrowKit' },
);
</script>

<template>
    <span :class="cn('inline-flex', props.className)" v-bind="$attrs">
        <img
            src="/brand/sitegrowkit-mark.png"
            :alt="props.alt"
            class="h-full w-full object-contain dark:hidden"
            decoding="async"
        />
        <img
            src="/brand/sitegrowkit-mark-dark.png"
            :alt="props.alt ? '' : ''"
            aria-hidden="true"
            class="hidden h-full w-full object-contain dark:block"
            decoding="async"
        />
    </span>
</template>
