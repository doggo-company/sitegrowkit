import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import type { OperationConfirmation } from '@/types/operation';

/**
 * 破壊的操作の確認（Phase 9.1B / STEP 7）。
 *
 * ブラウザの confirm() ではなくサーバーの ConfirmationGate を通す。
 * 1 回目は token なしで送る → 影響範囲と token が flash で返る →
 * 利用者が承認したら token 付きで送り直す。AI からの操作も同じ gate を通る。
 *
 * @param send token を受け取って実際のリクエストを投げる関数
 */
export function useOperationConfirmation(send: (token: string | null) => void) {
    const confirmation = ref<OperationConfirmation | null>(null);

    /**
     * **この instance が確認の返事を待っているか**（Phase 9.8 / STAGE 21E）。
     *
     * flash は router 全体へ届く。1 つの画面にこの composable が 2 つ以上あると、
     * 要求を出していないほうまで同じ確認を拾ってしまい、**同じダイアログが 2 枚開く**。
     * さらに悪いことに、片方で承認すると **別の操作へ token が渡る**
     * （token は operation + 引数 + 実行者に束縛されているのでサーバーは弾くが、
     * 画面としては壊れている）。だから「自分が出した要求の返事」だけを受け取る。
     */
    const awaiting = ref(false);

    function dispatch(token: string | null): void {
        awaiting.value = true;
        send(token);
    }

    /** 確認を要求する（1 回目の送信。この時点では何も変更されない） */
    function request(): void {
        confirmation.value = null;
        dispatch(null);
    }

    /** 利用者が承認した（2 回目の送信） */
    function accept(): void {
        const token = confirmation.value?.token ?? null;
        confirmation.value = null;

        if (token) {
            // token が古ければサーバーは確認をやり直させる。その返事も受け取る
            dispatch(token);

            return;
        }

        awaiting.value = false;
    }

    function dismiss(): void {
        confirmation.value = null;
        awaiting.value = false;
    }

    onMounted(() => {
        const stop = router.on('flash', (event) => {
            if (!awaiting.value) {
                return;
            }

            awaiting.value = false;

            const data = (event as CustomEvent).detail?.flash?.confirmation as
                OperationConfirmation | undefined;

            if (data) {
                confirmation.value = data;
            }
        });

        onUnmounted(stop);
    });

    return { confirmation, request, accept, dismiss };
}
