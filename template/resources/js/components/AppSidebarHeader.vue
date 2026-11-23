<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- 管理画面から公開サイトへ 1 操作で移動できるようにする（F-UX3） -->
        <div class="ml-auto flex shrink-0 items-center gap-1">
            <ThemeToggle />
            <Button variant="ghost" size="sm" as-child>
                <a
                    href="/"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="view-site-link"
                >
                    サイトを見る
                    <ExternalLink class="size-3.5" aria-hidden="true" />
                    <span class="sr-only">（新しいタブで開きます）</span>
                </a>
            </Button>
        </div>
    </header>
</template>
