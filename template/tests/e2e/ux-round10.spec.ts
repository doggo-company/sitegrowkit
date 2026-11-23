import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 10 周目（2026-11-05・整体院の受付・速く打って次を押す・rc14）の回帰。
 *
 * - R10-1: 記事のタイトルで Enter を押すと、本文を書く前に下書きで保存されて一覧へ戻った
 * - R10-2: ブロック編集で打ってすぐ「ページ設定へ戻る」を押すと「公開中の内容と同じです」と出て公開できなかった
 * - R10-3: ゴミ箱へ移すだけの確認のボタンが「削除する」だった
 * - R10-4: ダッシュボードの「未対応 1」が押せなかった（下の注記）
 */
async function gotoCreate(page: Page): Promise<string> {
    for (const base of ['post', 'news']) {
        const res = await page.goto(`/admin/${base}/create`);

        if (res?.ok()) {
            return base;
        }
    }

    throw new Error('記事の作成画面が無い');
}

test.describe('UX 一周 10 周目', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 1440, height: 900 },
    });

    test('記事のタイトルで Enter を押しても保存されず、書く画面に残る（R10-1）', async ({
        page,
    }) => {
        const base = await gotoCreate(page);
        await page.fill('#title', `年末年始のお休み ${Date.now()}`);
        await page.locator('#title').press('Enter');
        await page.waitForTimeout(800);

        await expect(page).toHaveURL(new RegExp(`/admin/${base}/create$`));
    });

    test('打ってすぐ「ページ設定へ戻る」を押しても、直した内容を公開できる（R10-2）', async ({
        page,
    }) => {
        const slug = `round10-back-${Date.now()}`;
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('はじめての方へ');
        await page.getByTestId('page-slug-input').fill(slug);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();

        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.locator('#prop-text').fill('はじめての方へ');
        await page.locator('#prop-text').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み', { timeout: 10_000 });
        await page.goto(edit);
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-unpublish-button')).toBeVisible();

        // 公開したあとに直して、待たずに戻る
        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('layer-heading').first().click();
        await page.locator('#prop-text').click();
        await page.keyboard.type('（駐車場あり）');
        await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
        await page.waitForURL(edit);

        await expect(page.getByTestId('page-publish-button')).toBeEnabled();
        await expect(page.locator('main')).not.toContainText('公開中の内容と同じです');
    });

    test('ゴミ箱へ移すだけの確認は「ゴミ箱へ移す」と言う（R10-3）', async ({
        page,
    }) => {
        const title = `ゴミ箱の確認 ${Date.now()}`;
        const base = await gotoCreate(page);
        await page.fill('#title', title);

        if ((await page.locator('#body').count()) > 0) {
            await page.locator('#body').click();
            await page.keyboard.type('本文');
        }

        await page.click('button[type="submit"]');
        await page.waitForURL(new RegExp(`/admin/${base}$`));

        const row = page.locator('tr', { hasText: title });
        await row.getByRole('button', { name: 'ゴミ箱へ移す', exact: true }).first().click();
        const dialog = page.getByTestId('operation-confirmation');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'ゴミ箱へ移す' })).toBeVisible();
        await expect(dialog.getByRole('button', { name: '削除する' })).toHaveCount(0);
    });

    /*
     * R10-4（ダッシュボードの「未対応」を押せる札に）は、未対応の受信がある状態を作る道が環境ごとに違う
     * （確認画面・見本の窓口）ので、ここでは見ない。直した日に、受信を 1 件入れた環境で押して確かめた。
     */
});
