/*
 * Admin PWA Service Worker（scope: /admin — 公開サイトには一切関与しない）。
 * キャッシュ戦略は保守的:
 *   - /build/ のハッシュ付きアセットのみ cache-first（immutable なので安全）
 *   - 管理画面 HTML / API は一切キャッシュしない（古い管理データを見せない）
 *   - ナビゲーション失敗時のみ offline ページを表示
 * 認証・セキュリティを迂回する処理は置かない（fetch はキャッシュ判定のみ）。
 */
const CACHE_NAME = 'agentcms-admin-v5';
const OFFLINE_URL = '/admin/offline';
// notificationclick の遷移先受け渡し用（アプリ側が復帰時に読む。Cache API は page からも読める）
const NAV_CACHE = 'agentcms-admin-nav';
const NAV_KEY = '/__agentcms/pending-navigation';

self.addEventListener('install', (event) => {
    // 即時適用（skipWaiting）: この SW は immutable アセットと offline ページしか
    // キャッシュせず互換性問題が起きないため待機させない。待機方式だと iOS で
    // 旧 SW が残り続け、notificationclick 等の修正が届かない（実機で確認）。
    // ページの強制リロードはしない（更新トーストは従来どおりユーザー起点）。
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.add(OFFLINE_URL)),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) => key !== CACHE_NAME && key !== NAV_CACHE,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

// 新バージョンの適用はユーザー操作（更新トースト）起点 — 勝手にリロードしない
self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Vite ビルド資産（ファイル名にハッシュ入り）だけ cache-first
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(CACHE_NAME).then(async (cache) => {
                const cached = await cache.match(request);

                if (cached) {
                    return cached;
                }

                const response = await fetch(request);

                if (response.ok) {
                    cache.put(request, response.clone());
                }

                return response;
            }),
        );

        return;
    }

    // 管理画面のページ遷移は常にネットワーク。落ちたときだけ offline ページ
    if (request.mode === 'navigate' && url.pathname.startsWith('/admin')) {
        event.respondWith(
            fetch(request).catch(async () => {
                const offline = await caches.match(OFFLINE_URL);

                return offline ?? Response.error();
            }),
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        // 不正な payload は表示しない
    }

    event.waitUntil(
        self.registration.showNotification(data.title || '通知', {
            body: data.body || '',
            icon: '/admin-pwa/icon-192.png',
            badge: '/admin-pwa/icon-192.png',
            data: { url: data.url || '/admin' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url =
        (event.notification.data && event.notification.data.url) || '/admin';

    // iOS の SW ナビゲーション API（navigate / openWindow / postMessage）は
    // standalone PWA で軒並み信頼できない（実機で確認）。
    // 遷移先を Cache API に書き、アプリ側（useAdminPwa）が復帰時に読んで遷移する。
    // focus / postMessage / openWindow は成功すれば儲けものの best-effort。
    event.waitUntil(
        (async () => {
            const cache = await caches.open(NAV_CACHE);
            await cache.put(
                NAV_KEY,
                new Response(url, {
                    headers: { 'x-created': Date.now() + '' },
                }),
            );

            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of clients) {
                if (client.url.includes('/admin') && 'focus' in client) {
                    await client.focus();
                    client.postMessage({ type: 'notification-navigate', url });

                    return;
                }
            }

            await self.clients.openWindow(url);
        })(),
    );
});
