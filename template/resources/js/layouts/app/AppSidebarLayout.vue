<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminReadOnly from '@/components/AdminReadOnly.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
const readOnly = computed(
    () =>
        (page.props.adminScreen as { readOnly?: boolean } | undefined)
            ?.readOnly === true,
);

const readOnlyMessage = computed(
    () =>
        (
            page.props.adminScreen as
                { readOnlyMessage?: string | null } | undefined
        )?.readOnlyMessage,
);

const editing = computed(
    () =>
        (page.props.adminScreen as { editing?: boolean } | undefined)
            ?.editing === true,
);

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <FlashMessage />
            <AdminReadOnly
                :read-only="readOnly"
                :editing="editing"
                :message="readOnlyMessage"
                ><slot
            /></AdminReadOnly>
        </AppContent>
        <Toaster />
        <LeaveConfirmDialog />
    </AppShell>
</template>
