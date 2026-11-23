import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 13 周目（2026-11-05・こども英語と書道の教室・ブラウザの「戻る」をよく使う・rc17）の回帰。
 *
 * - R13-1: ブロック編集からブラウザの「戻る」で設定へ戻ると、置いた中身が無い古い画面（「このページはまだ空です」）
 * - R13-2: 受信を「対応済み」にして「戻る」と、一覧は「未対応」のまま
 * - R12-2 残り: 記事の書きかけが、ブラウザの「戻る」で知らせなく消えた
 * - R13-5: 記事の書きかけでサイドバーを押すと、何も起きず理由も出ない（ブラウザの確かめの窓は、
 *   自動操作のブラウザでは出ずに「やめる」になる。画面の中の確かめに替えた）
 */
async function gotoPostCreateFromList(page: Page): Promise<string> {
    for (const base of ['post', 'news']) {
        const res = await page.goto(`/admin/${base}`);

        if (res?.ok()) {
            // 一覧から画面の中のリンクで入る（「戻る」で一覧へ戻れるように）
            await page.locator(`a[href$="/admin/${base}/create"]`).first().click();
            await page.waitForURL(new RegExp(`/admin/${base}/create$`));

            return base;
        }
    }

    throw new Error('記事の一覧が無い');
}

test.describe('UX 一周 13 周目（ブラウザの「戻る」）', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 1366, height: 768 },
    });

    test('ブロック編集から「戻る」で戻ると、置いたブロックが入った今の画面が出る（R13-1）', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('コースと月謝');
        await page.getByTestId('page-slug-input').fill(`round13-back-${Date.now()}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();
        await expect(page.locator('main')).toContainText('まだ空です');

        await page.getByRole('link', { name: 'ブロックを編集' }).first().click();
        await page.waitForURL(/\/editor$/);
        await page.getByTestId('canvas-empty-start').click();
        await page.locator('#prop-text').fill('コースと月謝');
        await page.locator('#prop-text').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み', {
            timeout: 10_000,
        });

        await page.goBack();
        await page.waitForURL(edit);

        await expect(page.locator('main')).not.toContainText('まだ空です', { timeout: 5_000 });
        await expect(page.getByTestId('page-publish-button')).toBeEnabled();
    });

    test('受信の対応状態を替えて「戻る」と、一覧にも替えた状態が出る（R13-2）', async ({
        page,
    }) => {
        await page.goto('/admin/forms/contact/submissions');
        const rows = page.getByTestId('submission-row');
        test.skip((await rows.count()) === 0, '受信が 0 件の環境（contact.spec が先に 1 件入れる）');

        await rows.first().getByTestId('submission-open').click();
        await page.waitForURL(/\/submissions\/\d+$/);
        await page.getByTestId('submission-status').click();
        const options = page.getByRole('option');
        const current = (await page.getByTestId('submission-status').innerText()).trim();
        let chosen = '';

        for (let i = 0; i < (await options.count()); i++) {
            const label = (await options.nth(i).innerText()).trim();

            if (label !== current) {
                chosen = label;
                await options.nth(i).click();
                break;
            }
        }

        await page.getByTestId('submission-status-save').click();
        await expect(page.locator('body')).toContainText('状態を変更しました');

        await page.goBack();
        await page.waitForURL(/\/submissions$/);
        await expect(page.getByTestId('submission-row').first()).toContainText(chosen, {
            timeout: 5_000,
        });
    });

    test('記事の書きかけで「戻る」を押すと、画面の中で確かめ、残れば打った内容が残る（R12-2 残り）', async ({
        page,
    }) => {
        const native: string[] = [];
        page.on('dialog', (d) => {
            native.push(d.message());
            void d.dismiss();
        });
        const base = await gotoPostCreateFromList(page);
        const createUrl = page.url();
        await page.fill('#title', '書きかけテスト');

        await page.goBack();
        const dialog = page.getByTestId('leave-confirm');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('保存していない変更があります');
        await dialog.getByRole('button', { name: 'この画面に残る' }).click();

        await expect(dialog).toBeHidden();
        await expect(page).toHaveURL(createUrl);
        await expect(page.locator('#title')).toHaveValue('書きかけテスト');

        await page.goBack();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: '保存せずに移る' }).click();
        await page.waitForURL(new RegExp(`/admin/${base}$`));
        expect(native).toEqual([]);
    });

    test('記事の書きかけでサイドバーを押すと、画面の中で確かめが出る（R13-5）', async ({
        page,
    }) => {
        const native: string[] = [];
        page.on('dialog', (d) => {
            native.push(d.message());
            void d.dismiss();
        });
        await gotoPostCreateFromList(page);
        const createUrl = page.url();
        await page.fill('#title', '書きかけテスト');

        await page.getByRole('link', { name: 'ページ', exact: true }).first().click();
        const dialog = page.getByTestId('leave-confirm');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'この画面に残る' }).click();
        await expect(page).toHaveURL(createUrl);
        await expect(page.locator('#title')).toHaveValue('書きかけテスト');

        await page.getByRole('link', { name: 'ページ', exact: true }).first().click();
        await dialog.getByRole('button', { name: '保存せずに移る' }).click();
        await page.waitForURL(/\/admin\/pages$/);
        expect(native).toEqual([]);
    });
});
