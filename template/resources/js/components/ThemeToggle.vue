<script setup lang="ts">
import { computed } from 'vue';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';

/**
 * 管理画面の外観切り替え（Phase 8.5-F / F-UX4）。
 *
 * 既存の `useAppearance`（localStorage + cookie、`app.blade.php` 側の
 * flash prevention 付き）をそのまま使い、**管理画面のヘッダーから 1 操作で**
 * 届くようにするだけ。公開サイトのテーマには影響しない。
 */
const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', label: 'ライト', Icon: Sun },
    { value: 'dark', label: 'ダーク', Icon: Moon },
    { value: 'system', label: 'システム設定に合わせる', Icon: Monitor },
] as const;

const current = computed(
    () => options.find((o) => o.value === appearance.value) ?? options[2],
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                data-testid="theme-toggle"
                :aria-label="`管理画面の色: ${current.label}（変更する）`"
            >
                <component
                    :is="current.Icon"
                    class="size-4"
                    aria-hidden="true"
                />
                <!-- 「外観」だと WordPress の外観（テーマ）と取り違える（UX 一周 2026-09-28）。これは管理画面だけの色 -->
                <span class="sr-only sm:not-sr-only">管理画面の色</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                :data-testid="`theme-option-${option.value}`"
                :aria-current="appearance === option.value ? 'true' : undefined"
                @select="updateAppearance(option.value)"
            >
                <component
                    :is="option.Icon"
                    class="size-4"
                    aria-hidden="true"
                />
                {{ option.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
