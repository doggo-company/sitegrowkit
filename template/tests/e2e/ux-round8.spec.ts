import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 8 周目（2026-11-05・花屋の店主・スマホの二度タップ・rc12）の回帰。
 *
 * - R8-2: 「公開する」を二度タップすると、公開で 1 行増えて下へずれた「下書きとして複製」に
 *   2 回目が当たり、見知らぬコピーのページへ飛んだ
 *
 * 押し方は人の二度タップ（同じ場所を 150ms あけて 2 回）。playwright の dblclick は間が短すぎて
 * 1 回目の応答が届く前に 2 回目が終わるので、画面が替わったあとの 2 回目にならない。
 */
async function doublePress(page: Page, testId: string): Promise<void> {
    const box = await page.getByTestId(testId).boundingBox();

    if (!box) {
        throw new Error(`${testId} が見えない`);
    }

    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    const answered = page.waitForResponse((res) => res.request().method() === 'POST');
    await page.mouse.click(x, y);
    // 1 回目の応答で画面が替わった直後に、同じ場所をもう一度押す（人の二度タップの 2 回目）
    await answered;
    await page.waitForTimeout(60);
    await page.mouse.click(x, y);
    await page.waitForTimeout(800);
}

test.describe('UX 一周 8 周目', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 390, height: 844 },
    });

    test('「公開する」を二度押ししても、2 回目が別のボタンに当たらない（R8-2）', async ({
        page,
    }) => {
        const slug = `round8-double-${Date.now()}`;
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('花束の料金');
        await page.getByTestId('page-slug-input').fill(slug);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();

        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.locator('#prop-text').fill('花束');
        await page.locator('#prop-text').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み', { timeout: 10_000 });

        await page.goto(edit);
        await page.getByTestId('page-publish-button').scrollIntoViewIfNeeded();
        await doublePress(page, 'page-publish-button');

        await expect(page).toHaveURL(edit);
        await page.goto('/admin/pages');
        await expect(page.locator('main')).not.toContainText(`${slug}-copy`);
        await expect(page.locator('main')).not.toContainText('花束の料金（コピー）');
    });
});
