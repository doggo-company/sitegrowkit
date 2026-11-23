import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({
    storageState: process.env.FEEL_AUTH_STATE ?? 'tests/e2e/.auth/admin.json',
});

async function openUpload(page: Page) {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill('画像の確認');
    await page.getByTestId('page-slug-input').fill(`upload-${Date.now()}`);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await page.getByTestId('page-editor-link').click();
    await page.waitForURL(/\/editor$/);
    await page.getByTestId('editor-empty-start').click();
    await page.getByTestId('palette-image').click();
    await page.getByTestId('media-picker-open').first().click();
    await expect(page.getByTestId('media-picker-dialog')).toBeVisible();
}

for (const width of [1366, 390]) {
    test(`#1: ${width}px 39MB画像は選んだ直後に理由を表示し送信しない`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 844 });
        await openUpload(page);
        let uploads = 0;
        await page.route('**/admin/media', async (route) => {
            if (route.request().method() === 'POST') {
                uploads++;
                await route.fulfill({
                    status: 413,
                    contentType: 'text/html',
                    body: '<h1>413 Request Entity Too Large</h1>',
                });
            } else {
                await route.continue();
            }
        });
        // File.size is decimal MB in the message: the original finding was 39,237,699 bytes.
        await page
            .getByTestId('media-picker-upload-file')
            .setInputFiles({
                name: 'large.jpg',
                mimeType: 'image/jpeg',
                buffer: Buffer.alloc(39_237_699),
            });
        await expect(page.getByTestId('media-picker-upload-error')).toHaveText(
            '20MBまで。この画像は39MBです。小さくしてから選び直してください。',
            { timeout: 1000 },
        );
        expect(uploads).toBe(0);
        await expect(
            page.getByTestId('media-picker-upload-file'),
        ).toBeEnabled();
        await page.screenshot({
            path: `test-results/round25-upload-${width}.png`,
            fullPage: true,
        });
    });
}

test('#1: サーバーがHTMLの413を返しても日本語で上限と次の操作を示す', async ({
    page,
}) => {
    await openUpload(page);
    await page.route('**/admin/media', async (route) => {
        if (route.request().method() === 'POST') {
            await route.fulfill({
                status: 413,
                contentType: 'text/html',
                body: '<h1>413 Request Entity Too Large</h1>',
            });
        } else {
            await route.continue();
        }
    });
    await page
        .getByTestId('media-picker-upload-file')
        .setInputFiles({
            name: 'small.jpg',
            mimeType: 'image/jpeg',
            buffer: Buffer.from('image fixture'),
        });
    await expect(page.getByTestId('media-picker-upload-error')).toHaveText(
        '画像が大きすぎて送れませんでした。20MBまでの画像を選んでください。',
    );
    await page.screenshot({
        path: 'test-results/round25-upload-413.png',
        fullPage: true,
    });
});
