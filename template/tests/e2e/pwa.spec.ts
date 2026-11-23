import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * Phase 3: Admin PWA + Web Push の E2E。
 *
 * ブラウザ実 Push（Push Service 経由の受信）は CI で安定再現できないため、
 * PushTransport 境界より先のロジックは Pest 側で検証している:
 *   - 他ユーザー subscription 操作拒否 / Email失敗時のPush独立 / 無効subscription削除
 *     → modules/Notifications/tests/（PushSubscriptionApiTest / NotifierTest / ContactPushTest）
 * ここでは実ブラウザでしか検証できないもの（SW 登録・offline・UI・API 認可・
 * 実 Transport 経由の Push 失敗が Contact を壊さないこと）を検証する。
 */

const FAKE_SUBSCRIPTION = {
    // 到達不能 endpoint（127.0.0.1:1 は即時 connection refused）— 実 Transport の失敗経路を踏む
    endpoint: 'https://127.0.0.1:1/push/e2e-fake-endpoint',
    keys: { p256dh: 'e2e-fake-p256dh', auth: 'e2e-fake-auth' },
};

async function xsrfHeaders(page: Page): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find(
        (c) => c.name === 'XSRF-TOKEN',
    );

    return {
        'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie.value) : '',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    };
}

test.describe('Admin PWA（未認証で取得できる資産）', () => {
    test('manifest が PWA 必要条件を満たす', async ({ request }) => {
        const response = await request.get('/admin/manifest.webmanifest');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('manifest');

        const manifest = await response.json();

        expect(manifest.name).toBeTruthy();
        expect(manifest.short_name).toBeTruthy();
        expect(manifest.start_url).toBe('/admin');
        // manifest scope は "/"（iOS: /login リダイレクトを scope 外にしない）。SW scope は /admin のまま
        expect(manifest.scope).toBe('/');
        expect(manifest.display).toBe('standalone');
        expect(manifest.theme_color).toBeTruthy();
        expect(manifest.background_color).toBeTruthy();

        const sizes = manifest.icons.map((i: { sizes: string }) => i.sizes);

        expect(sizes).toContain('192x192');
        expect(sizes).toContain('512x512');
        expect(
            manifest.icons.some(
                (i: { purpose?: string }) => i.purpose === 'maskable',
            ),
        ).toBe(true);

        // アイコン実体が配信される
        for (const icon of manifest.icons) {
            const res = await request.get(icon.src);

            expect(res.status()).toBe(200);
            expect(res.headers()['content-type']).toBe('image/png');
        }
    });

    test('Service Worker が正しいヘッダーで配信される', async ({ request }) => {
        const response = await request.get('/admin/sw.js');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('javascript');
        expect(response.headers()['service-worker-allowed']).toBe('/admin');
        // 更新検知を妨げる長期キャッシュをしない
        expect(response.headers()['cache-control']).toContain('no-cache');
    });

    test('offline ページが認証なしで表示できる', async ({ request }) => {
        const response = await request.get('/admin/offline');

        expect(response.status()).toBe(200);
        expect(await response.text()).toContain('オフラインです');
    });

    test('公開サイトには manifest / SW を出さない', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('link[rel="manifest"]')).toHaveCount(0);

        const swCount = await page.evaluate(async () => {
            const regs = await navigator.serviceWorker.getRegistrations();

            return regs.length;
        });

        expect(swCount).toBe(0);
    });

    test('認証なしでは subscription を登録できない', async ({ request }) => {
        const response = await request.post(
            '/admin/notifications/subscriptions',
            {
                headers: { Accept: 'application/json' },
                data: FAKE_SUBSCRIPTION,
            },
        );

        // CSRF(419) または未認証(401) — いずれも拒否
        expect([401, 419]).toContain(response.status());
    });
});

test.describe('Admin PWA（認証済み）', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('管理画面で Service Worker が登録される', async ({ page }) => {
        await page.goto('/admin/news');

        const scope = await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.ready;

            return registration.scope;
        });

        expect(scope).toMatch(/\/admin$/);
    });

    test('オフライン時は offline ページを表示し、古いデータを見せない', async ({
        page,
        context,
    }) => {
        // SW を確実に有効化してから切断
        await page.goto('/admin/news');
        await page.evaluate(async () => navigator.serviceWorker.ready);
        await page.reload();

        await context.setOffline(true);
        await page.goto('/admin/contact').catch(() => undefined);

        await expect(page.locator('h1')).toContainText('オフラインです');
        await context.setOffline(false);
    });

    test('通知設定画面が表示され、通知種別を切り替えて保存できる', async ({
        page,
    }) => {
        await page.goto('/admin/notifications');

        await expect(page.locator('h1')).toContainText('通知設定');
        await expect(page.getByTestId('push-card')).toBeVisible();
        await expect(page.getByTestId('install-card')).toBeVisible();

        // 保存はサーバー処理完了（PUT のレスポンス）まで待ってからリロードする
        const save = async () => {
            await Promise.all([
                page.waitForResponse((r) =>
                    r.url().includes('/admin/notifications/preferences'),
                ),
                page.getByTestId('save-preferences').click(),
            ]);
        };

        // まず ON へ正規化（過去の実行状態に依存しない）
        const pref = page.getByTestId('pref-contact.new');

        if ((await pref.getAttribute('data-state')) === 'unchecked') {
            await pref.click();
            await save();
            await page.reload();
        }

        // 新規お問い合わせを OFF にして保存
        await expect(pref).toHaveAttribute('data-state', 'checked');
        await pref.click();
        await expect(pref).toHaveAttribute('data-state', 'unchecked');
        await save();

        // リロード後も保持されている（DB 保存の確認）
        await page.reload();
        await expect(page.getByTestId('pref-contact.new')).toHaveAttribute(
            'data-state',
            'unchecked',
        );

        // 後続テストのため ON に戻す
        await page.getByTestId('pref-contact.new').click();
        await save();
        await page.reload();
        await expect(page.getByTestId('pref-contact.new')).toHaveAttribute(
            'data-state',
            'checked',
        );
    });

    test('subscription 登録 API が動作する', async ({ page }) => {
        await page.goto('/admin/notifications');

        const response = await page.request.post(
            '/admin/notifications/subscriptions',
            {
                headers: await xsrfHeaders(page),
                data: FAKE_SUBSCRIPTION,
            },
        );

        expect(response.status()).toBe(201);
        expect(await response.json()).toEqual({ subscribed: true });
    });

    test('Push 送信失敗（到達不能 endpoint）でも問い合わせは保存される', async ({
        page,
    }) => {
        // 前テストで登録した到達不能 subscription がある状態で、実 Transport を通す
        const stamp = Date.now();

        await page.goto('/contact');
        await page.fill('#name', `E2E Push失敗 ${stamp}`);
        await page.fill('#email', 'e2e-push@example.test');
        await page.selectOption('#type', 'other');
        await page.fill(
            '#message',
            'Push 失敗時も問い合わせが残ることの検証です。',
        );
        await page.waitForFunction(() => {
            const el = document.querySelector<HTMLInputElement>(
                '[name="cf-turnstile-response"]',
            );

            return el !== null && el.value.length > 0;
        });
        await page.check('input[name="privacy"]');
        await page.click('button[type="submit"]');

        await expect(page.locator('.contact-success')).toContainText(
            'お問い合わせを受け付けました',
        );

        // DB へ保存されている（管理画面一覧に出る）
        await page.goto('/admin/contact');
        await expect(page.locator('table')).toContainText(
            `E2E Push失敗 ${stamp}`,
        );
    });

    test('subscription 削除 API が動作する', async ({ page }) => {
        await page.goto('/admin/notifications');

        const response = await page.request.delete(
            '/admin/notifications/subscriptions',
            {
                headers: await xsrfHeaders(page),
                data: { endpoint: FAKE_SUBSCRIPTION.endpoint },
            },
        );

        expect(response.status()).toBe(200);
        expect(await response.json()).toEqual({ deleted: true });
    });
});
