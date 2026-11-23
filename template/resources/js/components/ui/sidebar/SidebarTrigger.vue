<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { Menu, PanelLeftClose, PanelLeftOpen } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

/**
 * ナビゲーションの開閉ボタン（Phase 8.5-F / F-UX9）。
 *
 * モバイルでは drawer（Sheet）の開閉になるため、
 * ハンバーガーアイコン + 日本語の accessible name + `aria-expanded` /
 * `aria-controls` を持たせる。Escape・外側クリック・focus 管理は Sheet 側が担う。
 */
const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, state, openMobile, toggleSidebar } = useSidebar()

const expanded = computed(() =>
  isMobile.value ? openMobile.value : state.value === "expanded",
)
const label = computed(() =>
  isMobile.value
    ? expanded.value
      ? "メニューを閉じる"
      : "メニューを開く"
    : expanded.value
      ? "ナビゲーションを閉じる"
      : "ナビゲーションを開く",
)
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    data-testid="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-11 w-11', props.class)"
    :aria-label="label"
    :aria-expanded="expanded"
    aria-controls="app-sidebar"
    @click="toggleSidebar"
  >
    <Menu v-if="isMobile" />
    <PanelLeftOpen v-else-if="state === 'collapsed'" />
    <PanelLeftClose v-else />
  </Button>
</template>
