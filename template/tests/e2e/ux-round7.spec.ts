import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 7 周目（2026-11-05・司法書士事務所の事務員・PC・ダブルクリック・rc11）の回帰。
 *
 * - R7-1: メニューの「保存」をダブルクリックすると、保存できているのに「重なった」と赤字が出た
 * - R7-2: 見本の「非公開にする」をダブルクリックすると、英語の開発者向けエラー画面（500）が出た
 * - R7-3: 公開日時を先にしたお知らせが、一覧で「公開」と出た
 * - R7-4: トップ用のページを公開しても、トップにする一手が画面に無かった
 *
 * R7-5（士業）は `tests/Feature/UxRelease20261105Round7Test.php`。
 */
test.describe('UX 一周 7 周目', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 1536, height: 864 },
    });

    test('メニューの保存をダブルクリックしても、失敗と言わない（R7-1）', async ({
        page,
    }) => {
        await page.goto('/admin/menus/header');
        await page.locator('#menu-description').fill(`二度押し ${Date.now()}`);
        await page.getByTestId('menu-save').dblclick();

        await expect(page.getByTestId('flash-success')).toBeVisible();
        await expect(page.getByTestId('menu-conflict')).toHaveCount(0);
        await expect(page.getByText('保存できませんでした')).toHaveCount(0);
    });

    test('見本の「非公開にする」をダブルクリックしても、エラー画面が出ない（R7-2）', async ({
        page,
    }) => {
        const errors: number[] = [];
        page.on('response', (res) => {
            if (res.status() >= 500) {
                errors.push(res.status());
            }
        });

        await page.goto('/admin/pages');
        const hide = page.getByTestId('sample-hide-about');
        test.skip((await hide.count()) === 0, '/about の見本が無い環境');
        await hide.dblclick();

        await expect(page.getByTestId('sample-show-about')).toBeVisible();
        await expect(page.getByText('Exception')).toHaveCount(0);
        expect(errors).toEqual([]);

        // 戻しておく（ほかの試験のため）
        await page.getByTestId('sample-show-about').click();
        await expect(page.getByTestId('sample-hide-about')).toBeVisible();
    });

    test('公開日時を先にした記事は一覧で「予約」と出る（R7-3）', async ({
        page,
    }) => {
        const title = `予約の確認 ${Date.now()}`;
        await gotoCreate(page);
        await page.fill('#title', title);
        await page.click('#status');
        await page.getByRole('option', { name: '公開' }).click();
        await page
            .locator('input[type="datetime-local"]')
            .first()
            .fill('2030-01-01T09:00');
        await expect(page.getByTestId('schedule-help')).toContainText('予約');
        await page.click('button[type="submit"]');
        await page.waitForURL(/\/admin\/[a-z]+$/);

        const row = page.locator('tr', { hasText: title });
        await expect(row.getByTestId('content-state')).toHaveText('予約');
    });

    test('トップ用のページを公開すると、トップにする一手が出る（R7-4）', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('トップページ');
        await page.getByTestId('page-slug-input').fill(`home-${Date.now()}`.slice(0, 20));
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();
        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.locator('#prop-text').fill('ようこそ');
        await page.locator('#prop-text').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み', { timeout: 10_000 });

        await page.goto(edit);
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-front-offer')).toBeVisible();
        await page.getByTestId('page-make-front').click();
        await expect(page.getByTestId('page-front-offer')).toHaveCount(0);

        await page.goto('/');
        await expect(page.locator('main')).toContainText('ようこそ');

        // 戻しておく（ほかの試験はトップが見本の前提）
        await page.goto('/admin/site');
        const name = page.locator('#site_name');

        if ((await name.inputValue()) === '') {
            await name.fill('試験のサイト');
        }

        await page.locator('#front_page_id').click();
        await page.getByRole('option', { name: /選ばない/ }).click();
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expect(page.getByTestId('site-saved')).toBeVisible();
    });
});

async function gotoCreate(page: Page): Promise<void> {
    for (const base of ['post', 'news']) {
        const res = await page.goto(`/admin/${base}/create`);

        if (res?.ok()) {
            return;
        }
    }

    throw new Error('記事の作成画面が無い');
}
