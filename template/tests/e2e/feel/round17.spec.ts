import { expect, test } from '@playwright/test';

/*
 * UX 一周 17 周目の採点（round17-grade.md）G1: ページの URL の候補を、画面が形態素解析で読む。
 * 触り心地の門（playwright.feel.config.ts）から回る。
 */

test.use({
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

for (const device of [
    { name: 'PC', viewport: { width: 1366, height: 768 }, touch: false },
    { name: 'スマホ', viewport: { width: 390, height: 844 }, touch: true },
] as const) {
    test.describe(`G1: URL の候補（${device.name}）`, () => {
        test.use({
            viewport: device.viewport,
            hasTouch: device.touch,
            isMobile: device.touch,
        });

        test('「焼き上がり時間」を URL 空のまま作ると /yaki-agari-jikan（yaki-ki-ue-ga-ri-toki-ma だった）', async ({
            page,
        }) => {
            await page.goto('/admin/pages/create');
            await page.getByTestId('page-title-input').fill('焼き上がり時間');
            await expect(page.getByTestId('page-slug-draft')).toContainText(
                '/yaki-agari-jikan',
                {
                    timeout: 15_000,
                },
            );
            await page.getByRole('button', { name: '作成する' }).click();
            await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
            await expect(page.getByTestId('page-slug-input')).toHaveValue(
                /^yaki-agari-jikan(-\d+)?$/,
            );
        });
    });
}
