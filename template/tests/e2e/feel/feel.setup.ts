import { expect, test as setup } from '@playwright/test';

// 触り心地の門のログイン（playwright.feel.config.ts）。値は .env から読み、表示しない
setup('管理者としてログイン（触り心地の門）', async ({ page }) => {
    const password = process.env.FEEL_ADMIN_PASSWORD ?? '';

    if (password === '') {
        throw new Error(
            'ADMIN_PASSWORD が見つかりません。`agentcms dev` 済みのプロジェクトを AGENTCMS_FEEL_PROJECT で指してください（docs/feel-bar.md）',
        );
    }

    await page.goto('/login');
    await page.fill('input[type="email"]', process.env.FEEL_ADMIN_EMAIL ?? '');
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
    await expect(page.locator('body')).toContainText('ダッシュボード');
    await page
        .context()
        // playwright.feel.config.ts と同じ場所（FEEL_AUTH_STATE）。ずれると試験が別の状態で動く
        .storageState({
            path:
                process.env.FEEL_AUTH_STATE ??
                'tests/e2e/.auth/feel-admin.json',
        });
});
