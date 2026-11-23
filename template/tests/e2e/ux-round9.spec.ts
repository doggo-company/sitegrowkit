import { expect, test } from '@playwright/test';

/**
 * 公開前の UX 一周 9 周目（2026-11-05・農家民宿・ノート PC・rc13）の回帰。
 *
 * - M2（メンテナの決定）: 「その他」を押すと、お仕事の名前を打つ欄が出る
 * - R9-3: メニューの行を自分のページに替えても、赤い「非公開にしています」が保存するまで残った
 *
 * 名前の使われ方・最初の 5 歩は `tests/Feature/UxRelease20261105Round9Test.php`、
 * 確認の文（R9-2）は `field-confirmation.test.ts`。
 */
test.describe('UX 一周 9 周目', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 1366, height: 768 },
    });

    test('「その他」はお仕事の名前を打ってから決める（M2）', async ({
        page,
    }) => {
        await page.goto('/dashboard');
        const picker = page.getByTestId('industry-picker');

        if ((await picker.count()) === 0) {
            await page.getByRole('button', { name: '選び直す' }).click();
        }

        await page.getByTestId('industry-other').click();
        await expect(page.getByTestId('industry-other-form')).toBeVisible();
        await page.getByTestId('industry-name').fill('宿・民宿');
        await page.getByTestId('industry-other-submit').click();

        await expect(page.getByTestId('industry-current')).toContainText(
            '宿・民宿',
        );
        await page.goto('/about');
        await expect(page.locator('main')).toContainText('宿・民宿');
    });

    test('メニューの行のリンク先を替えたら、前のリンク先の警告を出さない（R9-3）', async ({
        page,
    }) => {
        // 見本の /about を非公開にして、メニューの行に警告が出る状態にする
        await page.goto('/admin/pages');
        const hide = page.getByTestId('sample-hide-about');
        test.skip((await hide.count()) === 0, '/about の見本が無い環境');
        await hide.click();
        await expect(page.getByTestId('sample-show-about')).toBeVisible();

        await page.goto('/admin/menus/header');
        const warned = page.getByTestId('menu-unavailable');

        // ほかの試験が /about の行を外していることがある。無ければ足して保存する
        if ((await warned.count()) === 0) {
            await page.getByRole('button', { name: '項目を追加' }).click();
            const added = page.getByTestId('menu-item').last();
            await added.locator('select').first().selectOption('route');
            await added.locator('select').nth(1).selectOption('about');
            await page.getByTestId('menu-save').click();
            await expect(page.getByTestId('flash-success')).toBeVisible();
            await page.reload();
        }

        test.skip((await warned.count()) === 0, 'メニューに /about の行を置けない環境');
        const before = await warned.count();

        // 警告の付いた行のリンク先を「カスタム URL」に替える（保存はしない）
        const row = page.getByTestId('menu-item').filter({ has: warned.first() }).first();
        await row.locator('select').first().selectOption('custom');
        await expect(page.getByTestId('menu-unavailable')).toHaveCount(before - 1);

        // 戻しておく
        await page.goto('/admin/pages');
        await page.getByTestId('sample-show-about').click();
        await expect(page.getByTestId('sample-hide-about')).toBeVisible();
    });
});
