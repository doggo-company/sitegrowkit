<script setup lang="ts">
/**
 * 保存していない変更があるときの「移りますか？」（UX 一周 13 周目 R13-5・R12-2 残り）。
 * ブラウザの confirm() は出ないことがある（自動操作・ポップアップを止めた設定）ので、画面の中に出す。
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    answerLeave,
    LEAVE_MESSAGE,
    LEAVE_TITLE,
    leavePrompt,
} from '@/composables/useLeaveGuard';
</script>

<template>
    <Dialog
        :open="leavePrompt !== null"
        @update:open="
            (open: boolean) => {
                if (!open) answerLeave(false);
            }
        "
    >
        <!-- フォーカスが外へ動いただけでは閉じない（答えはボタンか Esc で受け取る） -->
        <DialogContent
            data-testid="leave-confirm"
            @focus-outside.prevent
        >
            <DialogHeader>
                <DialogTitle>{{ LEAVE_TITLE }}</DialogTitle>
                <DialogDescription>{{ LEAVE_MESSAGE }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="answerLeave(true)"
                    >保存せずに移る</Button
                >
                <Button @click="answerLeave(false)">この画面に残る</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
