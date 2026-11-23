import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';

/**
 * Dashboard の「はじめに」パネルの表示状態（Phase 8.5-E）。
 *
 * 保存先は **localStorage のみ**。DB migration も schema 変更も増やさない。
 * ブラウザのデータを消すと再表示されるが、それは許容仕様。
 *
 * 初期値は「非表示」から始め、mount 後に localStorage を読んで表示を決める。
 * こうしないと、閉じた利用者にパネルが一瞬見えてしまう。
 */
const STORAGE_KEY = 'agentcms.getting-started.dismissed';

function isDismissed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        // プライベートモード等で localStorage が使えなくても壊さない
        return false;
    }
}

function rememberDismissed(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, '1');
    } catch {
        // 保存できなくても閉じる操作自体は成立させる
    }
}

export type UseGettingStartedReturn = {
    visible: Ref<boolean>;
    dismiss: () => void;
};

export function useGettingStarted(): UseGettingStartedReturn {
    const visible = ref(false);

    onMounted(() => {
        visible.value = !isDismissed();
    });

    const dismiss = (): void => {
        visible.value = false;
        rememberDismissed();
    };

    return { visible, dismiss };
}
