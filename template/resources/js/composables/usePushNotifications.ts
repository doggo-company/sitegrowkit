import { onMounted, ref } from 'vue';

import { adminApi } from '@/lib/adminApi';

/**
 * Web Push の購読管理。通知権限の要求は必ずユーザー操作（enable()）起点 —
 * ページ表示だけで権限ダイアログを出してはいけない（仕様 PERMISSION UX）。
 */

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = window.atob(
        (base64 + padding).replace(/-/g, '+').replace(/_/g, '/'),
    );

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

/**
 * 送るのは `adminApi` に任せる（Phase 9.6 / gateway 一本化）。
 *
 * XSRF の取り方をここでも書いていたが、**取り方が画面ごとに分かれると
 * 片方だけ動く**（C7 の段 5 で実際に起きた）。宛先が `/admin/` でも同じである。
 */
async function requestJson(
    method: 'POST' | 'DELETE',
    url: string,
    body: unknown,
): Promise<boolean> {
    const result = await adminApi.send(method, url, body);

    return result.status === 'success';
}

export function usePushNotifications(vapidPublicKey: string) {
    const supported =
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window;

    const permission = ref<NotificationPermission>(
        supported ? Notification.permission : 'default',
    );
    const subscribed = ref(false);
    const busy = ref(false);
    const error = ref('');

    onMounted(async () => {
        if (!supported) {
            return;
        }

        const registration =
            await navigator.serviceWorker.getRegistration('/admin');
        const subscription = await registration?.pushManager.getSubscription();

        subscribed.value = subscription !== null && subscription !== undefined;
    });

    async function enable(): Promise<void> {
        error.value = '';
        busy.value = true;

        try {
            // 1. ユーザー操作起点で権限要求
            permission.value = await Notification.requestPermission();

            if (permission.value !== 'granted') {
                error.value =
                    '通知が許可されませんでした。ブラウザの設定から変更できます。';

                return;
            }

            // 2. SW の準備を待って購読
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(
                    vapidPublicKey,
                ) as BufferSource,
            });

            // 3. サーバーへ登録（本人の subscription として保存される）
            const registered = await requestJson(
                'POST',
                '/admin/notifications/subscriptions',
                subscription.toJSON(),
            );

            if (!registered) {
                await subscription.unsubscribe();
                error.value =
                    '通知の登録に失敗しました。時間をおいて再度お試しください。';

                return;
            }

            subscribed.value = true;
        } catch {
            error.value =
                '通知の登録に失敗しました。時間をおいて再度お試しください。';
        } finally {
            busy.value = false;
        }
    }

    async function disable(): Promise<void> {
        error.value = '';
        busy.value = true;

        try {
            const registration =
                await navigator.serviceWorker.getRegistration('/admin');
            const subscription =
                await registration?.pushManager.getSubscription();

            if (subscription) {
                await requestJson(
                    'DELETE',
                    '/admin/notifications/subscriptions',
                    {
                        endpoint: subscription.endpoint,
                    },
                );
                await subscription.unsubscribe();
            }

            subscribed.value = false;
        } catch {
            error.value = '通知の解除に失敗しました。';
        } finally {
            busy.value = false;
        }
    }

    return { supported, permission, subscribed, busy, error, enable, disable };
}
