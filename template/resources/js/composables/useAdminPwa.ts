import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Admin PWA: Service Worker 登録・更新検知・インストール案内。
 * 登録は /admin 配下のページでのみ行う（公開サイトへ SW を波及させない）。
 * 更新は勝手に適用せず、トーストのユーザー操作起点で切り替える。
 */

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const installPromptEvent = ref<BeforeInstallPromptEvent | null>(null);

export function useAdminPwa() {
    const isStandalone =
        typeof window !== 'undefined' &&
        (window.matchMedia('(display-mode: standalone)').matches ||
            ('standalone' in window.navigator &&
                (window.navigator as { standalone?: boolean }).standalone ===
                    true));

    const isIos =
        typeof window !== 'undefined' &&
        /iPad|iPhone|iPod/.test(window.navigator.userAgent);

    async function promptInstall(): Promise<void> {
        const event = installPromptEvent.value;

        if (!event) {
            return;
        }

        await event.prompt();
        await event.userChoice;
        installPromptEvent.value = null;
    }

    return { installPromptEvent, isStandalone, isIos, promptInstall };
}

export function initializeAdminPwa(): void {
    if (!window.location.pathname.startsWith('/admin')) {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPromptEvent.value = event as BeforeInstallPromptEvent;
    });

    if (!('serviceWorker' in navigator)) {
        return;
    }

    // Push タップ時の遷移: iOS standalone PWA では SW の WindowClient.navigate() が
    // 効かないため、SW からの postMessage を受けてアプリ側（Inertia）で遷移する
    navigator.serviceWorker.addEventListener('message', (event) => {
        const data = event.data as { type?: string; url?: string } | null;

        if (data?.type === 'notification-navigate' && data.url) {
            router.visit(data.url);
        }
    });

    // iOS では postMessage も復帰時に届かないことがあるため、
    // SW が Cache API に書いた「遷移待ち URL」を起動時・復帰時に読んで遷移する
    // （5 分以上前のものは古い通知として破棄）
    const consumePendingNavigation = async (): Promise<void> => {
        if (!('caches' in window)) {
            return;
        }

        try {
            const cache = await caches.open('agentcms-admin-nav');
            const res = await cache.match('/__agentcms/pending-navigation');

            if (!res) {
                return;
            }

            await cache.delete('/__agentcms/pending-navigation');
            const createdAt = Number(res.headers.get('x-created') ?? 0);
            const url = await res.text();

            if (url && Date.now() - createdAt < 5 * 60 * 1000) {
                router.visit(url);
            }
        } catch {
            // Cache API が使えない環境では postMessage 経路に任せる
        }
    };

    void consumePendingNavigation();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void consumePendingNavigation();
        }
    });

    navigator.serviceWorker
        .register('/admin/sw.js', { scope: '/admin' })
        .then((registration) => {
            // iOS は SW の更新チェックに消極的で、旧 SW が数日単位で残り続ける
            // （実機で確認）。起動ごとに明示的に更新を確認する
            void registration.update().catch(() => {});
            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;

                if (!worker) {
                    return;
                }

                worker.addEventListener('statechange', () => {
                    // 既存 SW がいる状態で installed = 新バージョン待機中
                    if (
                        worker.state === 'installed' &&
                        navigator.serviceWorker.controller
                    ) {
                        toast.info('管理画面の新しいバージョンがあります', {
                            duration: Infinity,
                            action: {
                                label: '更新',
                                onClick: () => {
                                    worker.postMessage('SKIP_WAITING');
                                    navigator.serviceWorker.addEventListener(
                                        'controllerchange',
                                        () => window.location.reload(),
                                        { once: true },
                                    );
                                },
                            },
                        });
                    }
                });
            });
        })
        .catch(() => {
            // SW が使えなくても管理画面は通常どおり動作する（PWA は付加機能）
        });
}
