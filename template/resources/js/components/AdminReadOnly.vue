<script setup lang="ts">
import { computed, provide, useTemplateRef, watchEffect } from 'vue';

const props = defineProps<{
    readOnly: boolean;
    editing: boolean;
    message?: string | null;
}>();
const locked = computed(() => props.readOnly && props.editing);
provide('admin-read-only', locked);
const fields = useTemplateRef<HTMLElement>('fields');

// fieldset disables native controls. Rich text editors need contenteditable=false,
// while their text must remain available to screen readers (inert would hide it).
watchEffect((cleanup) => {
    const element = fields.value;

    if (!locked.value || !element) {
        return;
    }

    const changed = new Map<Element, string>();
    const disableRichText = () => {
        for (const input of element.querySelectorAll(
            '[contenteditable="true"]',
        )) {
            changed.set(input, 'true');
            input.setAttribute('contenteditable', 'false');
        }
    };
    disableRichText();
    const observer = new MutationObserver(disableRichText);
    observer.observe(element, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['contenteditable'],
    });
    cleanup(() => {
        observer.disconnect();

        for (const [input, value] of changed) {
            input.setAttribute('contenteditable', value);
        }
    });
});

function blockMutation(event: Event): void {
    if (locked.value) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}

function blockAction(event: MouseEvent): void {
    if (
        locked.value &&
        event.target instanceof Element &&
        event.target.closest('button, [role="button"]')
    ) {
        blockMutation(event);
    }
}
</script>

<template>
    <div
        :data-admin-read-only="readOnly || undefined"
        :data-admin-editing="editing || undefined"
    >
        <p
            v-if="readOnly"
            role="status"
            class="px-4 py-3"
            data-testid="admin-read-only-notice"
        >
            {{ message }}
        </p>
        <fieldset
            ref="fields"
            :disabled="locked"
            class="min-w-0 border-0 p-0"
            @submit.capture="blockMutation"
            @beforeinput.capture="blockMutation"
            @dragstart.capture="blockMutation"
            @drop.capture="blockMutation"
            @click.capture="blockAction"
        >
            <slot />
        </fieldset>
    </div>
</template>

<style scoped>
[data-admin-read-only][data-admin-editing]
    :deep(
        button:not([role='combobox']):not([role='checkbox']):not(
                [role='switch']
            )
    ),
[data-admin-read-only] :deep(a[href$='/create']) {
    display: none;
}
</style>
