<script setup lang="ts">
/**
 * 保存できなかったことを、**押したボタンの横で**言う（UX 一周 2026-09-28）。
 *
 * 入力欄の下のエラーだけでは、その欄が画面の外にあると気づけない。
 * ここは「保存できませんでした」と、直す項目へ飛べるボタンを出す。
 * `role="alert"` にして、読み上げにも載せる。
 */
import { computed } from 'vue';
import { saveErrorItems } from '@/lib/save-errors';

const props = defineProps<{
    errors: Record<string, string | undefined>;
    fields: { key: string; label: string; inputId?: string }[];
}>();

const items = computed(() => saveErrorItems(props.errors, props.fields));

/** 保存に失敗した直後に呼ぶ。最初の項目へ移る（押した人の目をそこへ連れて行く） */
function focusFirst(): void {
    const first = items.value.find((item) => item.label !== '');

    if (first) {
        focusField(first.inputId);
    }
}

defineExpose({ focusFirst });

function focusField(inputId: string): void {
    const el = document.getElementById(inputId);

    el?.scrollIntoView({ block: 'center' });
    el?.focus({ preventScroll: true });
}
</script>

<template>
    <div
        v-if="items.length > 0"
        role="alert"
        data-testid="save-error-summary"
        class="rounded-md border border-destructive bg-destructive/10 px-3 py-2 text-sm"
    >
        <p class="font-medium">
            保存できませんでした。次の項目を直してから、もう一度保存してください。
        </p>
        <ul class="mt-1 list-disc pl-5">
            <li v-for="item in items" :key="item.key">
                <template v-if="item.label">
                    <button
                        type="button"
                        class="underline underline-offset-2"
                        @click="focusField(item.inputId)"
                    >
                        {{ item.label }}
                    </button>
                    ：
                </template>
                {{ item.message }}
            </li>
        </ul>
    </div>
</template>
