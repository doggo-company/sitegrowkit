import { expect, test } from '@playwright/test';

const ADMIN_EMAIL = process.env.ADMIN_EMAIL ?? 'admin@example.test';

test.describe('管理画面（未認証）', () => {
    test('誤ったパスワードではログインできない', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[type="email"]', ADMIN_EMAIL);
        await page.fill('input[type="password"]', 'wrong-password');
        await page.click('button[type="submit"]');
        await expect(page).not.toHaveURL(/dashboard/);
    });
});

test.describe('管理画面（認証済み）', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('News 作成 → 公開サイトに反映', async ({ page }) => {
        const stamp = Date.now();
        const title = `E2E ニュース ${stamp}`;

        await page.goto('/admin/news');
        await expect(page.locator('h1')).toContainText('ニュース');
        await page.click('text=新規作成');

        await page.fill('#title', title);
        await page.fill('#body', 'E2E で作成した本文です。');
        await page.click('#status');
        await page.getByRole('option', { name: '公開' }).click();
        const now = new Date(Date.now() - 60_000);
        const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
            .toISOString()
            .slice(0, 16);
        await page.fill('#published_at', local);
        await page.click('button[type="submit"]');

        await page.waitForURL('**/admin/news');
        await expect(page.locator('table')).toContainText(title);

        // 公開サイトに反映されている（slug 自動生成）
        await page.goto('/news');
        await expect(page.locator('main')).toContainText(title);
    });

    test('問い合わせ一覧と状態変更', async ({ page }) => {
        await page.goto('/admin/contact');
        await expect(page.locator('h1')).toContainText('お問い合わせ');

        await page.locator('table a', { hasText: '詳細' }).first().click();
        await expect(page.locator('h1')).toContainText('お問い合わせ #');

        // 状態セレクトを開いて「対応中」へ
        await page.getByRole('combobox').click();
        await page.getByRole('option', { name: '対応中' }).click();
        await page.getByRole('button', { name: '更新' }).click();
        await expect(page.getByRole('combobox')).toContainText('対応中');
    });

    test('サイト設定の保存', async ({ page }) => {
        await page.goto('/admin/site');
        await expect(page.locator('h1')).toContainText('サイト設定');
        await page.fill('#tagline', '小さなチームのための、確かなウェブ制作');
        const saved = page.waitForResponse(
            (res) =>
                res.url().includes('/admin/site') &&
                res.request().method() !== 'GET',
        );
        await page.getByRole('button', { name: '保存' }).click();
        await saved;

        // Phase 5: 公開サイトは import されたテンプレート（tagline を表示しないデザイン）のため、
        // 設定の永続化は管理画面の再読み込みで検証する（Preserve First — 勝手に埋め込まない）
        await page.goto('/admin/site');
        await expect(page.locator('#tagline')).toHaveValue(
            '小さなチームのための、確かなウェブ制作',
        );
    });
});
