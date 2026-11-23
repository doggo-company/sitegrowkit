import { expect, test } from '@playwright/test';

/**
 * 公開前の UX 一周 6 周目（2026-11-05・英会話教室の先生・rc10）の回帰。
 *
 * - R6-3: メニューを画面を読み直さずに 2 回続けて保存すると、2 回目が「重なった」で断られ、
 *   足した行が消え、開発者向けの英語のエラー画面が出た
 * - R6-6: フォームの識別子を空のまま保存すると /forms/form-1 になり、あとから変えられないことが
 *   保存したあとにしか書いていなかった
 *
 * R6-1・R6-2・R6-4 は `tests/Feature/UxRelease20261105Round6Test.php`。
 */
test.describe('UX 一周 6 周目', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('メニューを続けて 2 回保存しても、2 回目も保存される（R6-3）', async ({
        page,
    }) => {
        await page.goto('/admin/menus/header');
        test.skip(
            (await page.getByTestId('menu-remove-0').count()) === 0,
            'ヘッダーのメニューに項目が無い環境',
        );
        const before = await page.getByTestId('menu-item').count();
        const description = page.locator('#menu-description');

        // 1 回目: 1 行外して、説明を書いて保存
        await page.getByTestId('menu-remove-0').click();
        await description.fill('上のメニュー');
        await page.getByTestId('menu-save').click();
        await expect(page.getByTestId('flash-success')).toBeVisible();
        await expect(page.getByTestId('menu-removed-notice')).toHaveCount(0);

        // 2 回目: 読み直さずに、説明を空にして保存（6 周目は 409 と英語のエラー画面）
        await description.fill('');
        await page.getByTestId('menu-save').click();
        await expect(page.getByTestId('menu-conflict')).toHaveCount(0);
        await expect(page.getByText('Exception trace')).toHaveCount(0);

        await page.reload();
        await expect(page.getByTestId('menu-item')).toHaveCount(before - 1);
        await expect(page.locator('#menu-description')).toHaveValue('');
    });

    test('識別子を空のまま作ると番号の URL になり、あとで変えられないことを保存の前に言う（R6-6）', async ({
        page,
    }) => {
        await page.goto('/admin/forms/new');
        await page.getByTestId('form-name').fill('体験レッスンのお申し込み');
        await expect(page.locator('main')).toContainText('/forms/form-1');
        await expect(page.locator('main')).toContainText('保存したあとは変えられません');
    });
});
