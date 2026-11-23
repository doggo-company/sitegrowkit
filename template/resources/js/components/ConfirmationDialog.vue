<script setup lang="ts">
/**
 * サーバーが返した確認内容（要約 + 影響範囲）を見せてから破壊的操作を実行する
 * ダイアログ（Phase 9.1B / STEP 7）。文言はサーバーが決める。
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { OperationConfirmation } from '@/types/operation';

const props = withDefaults(
    defineProps<{
        confirmation: OperationConfirmation | null;
        confirmLabel?: string;
        /**
         * 押すボタンの見た目（UX 一周 3 周目 F2）。
         *
         * **確認そのものは省かない**（確認ゲートはサーバーが決める）。ただ、
         * 影響を受けるデータが 1 件も無いと分かっている画面で赤いボタンを出すと、
         * 「何かが消える」と読まれて怖がらせる。そういう画面だけ `default` にする。
         */
        tone?: 'destructive' | 'default';
        /** 文言の後に添える一言（例: 受信がまだ 0 件であること）。事実だけを書く */
        note?: string | null;
    }>(),
    { confirmLabel: '削除する', tone: 'destructive', note: null },
);

const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>();

/**
 * 押すボタンの名前。ゴミ箱へ移すだけの確認に「削除する」と出さない（UX 一周 10 周目 R10-3:
 * 中身は「ゴミ箱からは元に戻せます」なのに、ボタンが「削除する」で、消えて戻せないように読めた）。
 * 呼ぶ側が名前を決めていないときだけ、確認の題から言い換える。
 */
const label = computed(() =>
    props.confirmLabel === '削除する' &&
    /ゴミ箱へ移/.test(props.confirmation?.summary ?? '')
        ? 'ゴミ箱へ移す'
        : props.confirmLabel,
);
</script>

<template>
    <Dialog
        :open="confirmation !== null"
        @update:open="
            (open: boolean) => {
                if (!open) emit('cancel');
            }
        "
    >
        <DialogContent data-testid="operation-confirmation">
            <DialogHeader>
                <DialogTitle>{{ confirmation?.summary }}</DialogTitle>
                <DialogDescription>
                    内容を確認してから実行してください。
                </DialogDescription>
            </DialogHeader>
            <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li v-for="(line, i) in confirmation?.impact ?? []" :key="i">
                    {{ line }}
                </li>
            </ul>
            <p
                v-if="note"
                class="text-sm"
                data-testid="operation-confirmation-note"
            >
                {{ note }}
            </p>
            <DialogFooter>
                <Button variant="secondary" @click="emit('cancel')"
                    >キャンセル</Button
                >
                <Button
                    :variant="
                        tone === 'destructive' ? 'destructive' : 'default'
                    "
                    data-testid="operation-confirm-button"
                    @click="emit('confirm')"
                >
                    {{ label }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
