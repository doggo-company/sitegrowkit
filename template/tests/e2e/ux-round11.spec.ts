import { expect, test } from '@playwright/test';

/**
 * 公開前の UX 一周 11 周目（2026-11-05・出張カメラマン・PC とスマホ・rc15）の回帰。
 *
 * - R11-1: 公開中のページのブロック編集を開き直すと「保存済み（下書き）」と出た
 * - R11-3: 公開側のフォームで、日付・数値の欄だけ小さく枠も違った（取り込んだ見本の枠の CSS）
 *
 * R11-2（必須にする確認の言葉）は `field-confirmation.test.ts`。
 */
test.describe('UX 一周 11 周目', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('公開中のページを開き直すと「公開中と同じ内容」と出る（R11-1）', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('撮影の流れ');
        await page.getByTestId('page-slug-input').fill(`round11-${Date.now()}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();

        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.locator('#prop-text').fill('撮影の流れ');
        await page.locator('#prop-text').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み', { timeout: 10_000 });
        await page.goto(edit);
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-unpublish-button')).toBeVisible();

        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await expect(page.getByTestId('editor-save-state')).toHaveText('保存済み（公開中と同じ内容）');
    });

    test('取り込んだ見本の枠のフォームで、日付・数値の欄もほかの欄と同じ大きさ（R11-3）', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/contact');
        test.skip((await page.locator('.field').count()) === 0, '見本の枠のフォームではない環境');

        const heights = await page.evaluate(() => {
            const field = document.querySelector('.field');
            const make = (type: string): number => {
                const input = document.createElement('input');
                input.type = type;
                field!.appendChild(input);
                const h = input.getBoundingClientRect().height;
                input.remove();

                return h;
            };

            return { text: make('text'), date: make('date'), number: make('number'), tel: make('tel') };
        });

        expect(Math.abs(heights.date - heights.text)).toBeLessThan(4);
        expect(Math.abs(heights.number - heights.text)).toBeLessThan(4);
        expect(Math.abs(heights.tel - heights.text)).toBeLessThan(4);
    });
});
