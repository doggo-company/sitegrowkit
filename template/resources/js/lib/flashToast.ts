import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    /*
     * 別の画面へ移ったら、前の画面の知らせを閉じる（UX 一周 15 周目 T7: 「見本を非公開にしました」が
     * 2 回画面を移ったあとも本文の下に残っていた）。移った先の知らせは、このあとの flash で出る。
     */
    router.on('start', (event) => {
        const visit = (event as CustomEvent).detail?.visit as
            { method?: string } | undefined;

        if ((visit?.method ?? 'get') === 'get') {
            toast.dismiss();
        }
    });

    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message);
    });
}
