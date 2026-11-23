import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 遅れて届く候補で、開いている公開状態の選択肢が画面外へ動かないことを確認する。
const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

if (!composeCwd || !process.env.APP_URL || process.env.APP_URL.endsWith(':8180') || !/^http:\/\/(localhost|127\.0\.0\.1):[0-9]+$/.test(process.env.APP_URL) || composeCwd === process.cwd()) {
    throw new Error('使い捨てサイトのAPP_URLとAGENTCMS_COMPOSE_CWDを明示してください。');
}

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

async function loginAsOwner(page: Page): Promise<void> {
    const { email, password } = adminCredentials();
    await login(page, email, password);
}


for (const width of [1280, 390]) {
    test.describe(`参照候補の遅延 ${width}px`, () => {
        test.use({ viewport: { width, height: 850 } });
        test('候補が届いても開いた公開メニューの位置を保つ', async ({ page }) => {
            const key = `delay_${width}_${Date.now()}`;
            await loginAsOwner(page);
            await page.goto('/admin/field-groups/create');
            await page.getByTestId('field-group-label').fill('遅延候補の確認');
            await page.getByTestId('field-group-key').fill(key);
            await page.getByTestId('field-group-field-label').fill('確認用の参照');
            await page.getByTestId('field-group-field-key').fill(key);
            await page.getByTestId('field-group-field-type').selectOption('relation');
            await page.getByTestId('field-group-field-target').selectOption('post');
            await page.getByTestId('field-group-add-location').click();
            await page.getByTestId('field-group-location-row').getByLabel('割当先').selectOption('post');
            await page.getByTestId('field-group-submit').click();
            await page.waitForURL('**/admin/field-groups');

            let release: () => void = () => {};
            const gate = new Promise<void>((resolve) => {
 release = resolve;
});
            await page.route('**/admin/relations/post/options?*', async (route) => {
                await gate;
                await route.fulfill({ json: { options: Array.from({ length: 20 }, (_, i) => ({ id: 500000 + i, title: `候補${i}：表示位置を変えない長い日本語の見出し` })) } });
            });

            try {
                await page.goto('/admin/post/create');
                await page.locator('#title').fill('遅延しても選べる');
                await page.locator('#body').fill('本文');
                await page.locator('#status').click();
                const option = page.getByRole('option', { name: '公開', exact: true });
                await expect(option).toBeVisible();
                const before = await page.locator('#status').boundingBox();
                release();
                const results = page.locator(`[aria-labelledby="${key}-label"]`).getByTestId('relation-results');
                await expect(results.getByTestId('relation-option')).toHaveCount(20);
                const after = await page.locator('#status').boundingBox();
                expect(Math.abs(after!.y - before!.y)).toBeLessThan(2);
                await option.click();
                await expect(page.locator('#status')).toContainText('公開');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            } finally {
                release();
            }
        });
    });
}
