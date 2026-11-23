import { createInertiaApp, router } from '@inertiajs/vue3';
import { ConfigProvider } from 'reka-ui';
import { createApp, createSSRApp, h } from 'vue';
import { initializeAdminPwa } from '@/composables/useAdminPwa';
// 「戻る」の守りは Inertia より先に popstate を受け取る必要があるので、createInertiaApp より前に読み込む
import '@/composables/useLeaveGuard';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { installGhostClickGuard } from '@/lib/ghost-click';
import { installHistoryRefresh } from '@/lib/history-refresh';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    setup({ el, App, props, plugin }) {
        // Select 等が差し込む style に、現在の HTML が許可した nonce を渡す。
        // Inertia の JSON 応答ごとの値ではなく、文書の生存中は同じ値を使う。
        const nonce =
            typeof document === 'undefined'
                ? undefined
                : document.querySelector<HTMLScriptElement>('script[nonce]')
                      ?.nonce;
        const app = (el === null ? createSSRApp : createApp)({
            render: () => h(ConfigProvider, { nonce }, () => h(App, props)),
        }).use(plugin);

        if (el !== null) {
            app.mount(el);
        }

        return app;
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
        /*
         * **実行時に <style> を差し込ませない**（F-50 / B-24）。
         * 管理画面の CSP は inline style を許していないので、既定のままだと
         * **毎頁 1 件ブロックされる**。同じ CSS は resources/css/progress.css が
         * Vite 経由（= 'self'）で配る。**色を変えるときは両方**（検査が赤で教える）。
         */
        includeCSS: false,
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Admin PWA: /admin 配下でのみ Service Worker を登録（公開サイトへ波及させない）
initializeAdminPwa();

// 二度押しの 2 回目が、替わったあとの画面の別のボタンに当たらないように（UX 一周 8 周目 R8-2・R8-4）
installGhostClickGuard(router);

// ブラウザの「戻る」で古い画面を出さない（UX 一周 13 周目 R13-1・R13-2）
installHistoryRefresh(router);
