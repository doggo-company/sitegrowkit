import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 12 周目（2026-11-05・保護猫カフェ・PC 中心・rc16）の回帰。
 *
 * - R12-1: お仕事の候補で、選ぶ前に何が替わるかが見えなかった
 * - R12-2: フォーム・記事の編集画面で、保存せずにサイドバーから移ると、打った内容が黙って消えた
 */
async function gotoCreate(page: Page): Promise<void> {
    for (const base of ['post', 'news']) {
        const res = await page.goto(`/admin/${base}/create`);

        if (res?.ok()) {
            return;
        }
    }

    throw new Error('記事の作成画面が無い');
}

test.describe('UX 一周 12 周目', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 1440, height: 900 },
    });

    test('お仕事の候補に、選ぶと何が替わるかが出る（R12-1）', async ({ page }) => {
        await page.goto('/dashboard');

        if ((await page.getByTestId('industry-picker').count()) === 0) {
            await page.getByRole('button', { name: '選び直す' }).click();
        }

        await expect(page.getByTestId('industry-hint-cafe')).toContainText('ご用件');
        await expect(page.getByTestId('industry-hint-trimming')).toContainText('ご用件');
        await expect(page.getByTestId('industry-hint-other')).toContainText('お仕事の名前');
    });

    test('保存していない記事から移ろうとすると確かめ、やめれば打った内容が残る（R12-2）', async ({
        page,
    }) => {
        await gotoCreate(page);
        const url = page.url();
        await page.fill('#title', '書きかけテスト');

        // 13 周目 R13-5: 確かめはブラウザの confirm() でなく画面の中に出す
        await page.getByRole('link', { name: 'ページ', exact: true }).first().click();
        const dialog = page.getByTestId('leave-confirm');
        await expect(dialog).toContainText('保存していない変更があります');
        await dialog.getByRole('button', { name: 'この画面に残る' }).click();

        await expect(page).toHaveURL(url);
        await expect(page.locator('#title')).toHaveValue('書きかけテスト');
    });

    test('何も打っていなければ、確かめずに移る（R12-2）', async ({ page }) => {
        await gotoCreate(page);

        const messages: string[] = [];
        page.on('dialog', (dialog) => {
            messages.push(dialog.message());
            void dialog.accept();
        });
        await page.getByRole('link', { name: 'ページ', exact: true }).first().click();
        await page.waitForURL(/\/admin\/pages$/);

        expect(messages).toEqual([]);
        await expect(page.getByTestId('leave-confirm')).toHaveCount(0);
    });
});
