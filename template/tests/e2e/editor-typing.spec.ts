import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * UX 一周 2 周目（パン屋さん）の M4・M5・N2 の恒久回帰。9/27 の周から 3 回目の指摘だった。
 *
 * - M5: 欄に打って待っても「未保存の変更あり」のまま、プレビューも変わらなかった
 * - M4: プレビューの見出しを押して打っても、何も入らなかった
 * - N2: 見出しを置くと右の欄が下がり、「追加する」が見えなくなった
 *
 * **欄を離れない**（Tab も押さない）で確かめる。離れれば前から保存されていた。
 */
const stamp = Date.now();

async function freshEditor(page: Page, slug: string): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`打鍵 ${slug}`);
    await page.getByTestId('page-slug-input').fill(slug);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
    await page.goto(page.url().replace(/\/edit$/, '/editor'));
    await page.getByTestId('canvas-empty-start').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
}

function canvasText(page: Page) {
    return page.frameLocator('[data-testid="editor-canvas"]').locator('main');
}

test.describe('ブロック編集で打つ', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('空ページに見出しを置いてそのまま打つと、本文に保存される', async ({ page }) => {
        await freshEditor(page, `typing-first-${stamp}`);
        const field = page.locator('#prop-text');
        await expect(field).toBeFocused();
        await page.keyboard.type('季節のお花をあなたに');
        await expect(field).toHaveValue('季節のお花をあなたに');
        await expect(canvasText(page)).toContainText('季節のお花をあなたに');
        await expect(page.getByTestId('editor-save-state')).toContainText('保存済み');
        await page.reload();
        await expect(canvasText(page)).toContainText('季節のお花をあなたに');
    });

    for (const type of ['heading', 'text', 'richtext']) {
        test(`${type} を置いた直後にそのまま打てる`, async ({ page }) => {
            await freshEditor(page, `typing-add-${type}-${stamp}`);
            await page.getByTestId(`palette-${type}`).click();
            const field = page.locator(type === 'richtext' ? '#prop-html' : '#prop-text');
            await expect(field).toBeFocused();
            await page.keyboard.type('花のある暮らし');

            if (type === 'richtext') {
                await expect(field).toHaveText('花のある暮らし');
            } else {
                await expect(field).toHaveValue('花のある暮らし');
            }

            await expect(canvasText(page)).toContainText('花のある暮らし');
            await expect(page.getByTestId('editor-save-state')).toContainText('保存済み');
            await page.reload();
            await expect(canvasText(page)).toContainText('花のある暮らし');
        });
    }

    for (const reentry of ['Tab', 'クリック'] as const) {
        test(`初期文言のまま離れたメニュー表へ ${reentry} で戻って打つと置き換わり、保存される`, async ({ page }) => {
            await freshEditor(page, `typing-reenter-${reentry === 'Tab' ? 'tab' : 'click'}-${stamp}`);
            await page.getByTestId('palette-menutable').click();
            const field = page.locator('#prop-items');
            await field.click();
            await expect(field).toHaveValue(
                '〇〇（品名・コース名）｜〇〇円\n〇〇（品名・コース名）｜〇〇円\n〇〇（品名・コース名）｜〇〇円',
            );

            // 選んだ範囲を矢印キーで外してから離れる。選んだまま離れると、戻ったときに
            // ブラウザが前の選択を戻すので、r21 の「入り直したら選ぶ」が無くても通ってしまう
            // （2 周目 2026-11-23 に、select() を消した版で通るのを実測）。
            await page.keyboard.press('ArrowRight');
            expect(
                await field.evaluate(
                    (el: HTMLTextAreaElement) =>
                        el.selectionStart === el.selectionEnd,
                ),
            ).toBe(true);

            // 表の題は品名と値段の直前の欄。そこを押してから Tab で戻る。
            await page.locator('#prop-caption').click();
            await expect(field).not.toBeFocused();

            if (reentry === 'Tab') {
                await page.keyboard.press('Tab');
            } else {
                await field.click();
            }

            await expect(field).toBeFocused();
            await page.keyboard.type('お花の定期便');
            await expect(field).toHaveValue('お花の定期便');
            await expect(canvasText(page)).toContainText('お花の定期便');
            await expect(canvasText(page)).not.toContainText('〇〇（品名・コース名）');
            await expect(page.getByTestId('editor-save-state')).toContainText('保存済み');
            await page.reload();
            await expect(canvasText(page)).toContainText('お花の定期便');
            await expect(canvasText(page)).not.toContainText('〇〇（品名・コース名）');
        });

        test(`書き換えたメニュー表へ ${reentry} で戻ると全選択されず、前の文字が残る`, async ({ page }) => {
            await freshEditor(page, `typing-keep-${reentry === 'Tab' ? 'tab' : 'click'}-${stamp}`);
            await page.getByTestId('palette-menutable').click();
            const field = page.locator('#prop-items');
            await field.click();
            await page.keyboard.type('季節の花');
            await expect(field).toHaveValue('季節の花');
            await expect(page.getByTestId('editor-save-state')).toContainText('保存済み');
            await page.locator('#prop-caption').click();
            await expect(field).not.toBeFocused();

            if (reentry === 'Tab') {
                await page.keyboard.press('Tab');
            } else {
                await field.click();
            }

            await expect(field).toBeFocused();
            // キーで選択を解除する前に、再入場で全選択されていないことを調べる。
            expect(await field.evaluate((element: HTMLInputElement | HTMLTextAreaElement) =>
                element.selectionStart === element.selectionEnd,
            )).toBe(true);
            await page.keyboard.press('End');
            await page.keyboard.type('を届けます');
            await expect(field).toHaveValue('季節の花を届けます');
            await expect(canvasText(page)).toContainText('季節の花を届けます');
            await expect(page.getByTestId('editor-save-state')).toContainText('保存済み');
            await page.reload();
            await expect(canvasText(page)).toContainText('季節の花を届けます');
        });
    }

    test('欄を離れなくても、打つ手が止まれば保存されてプレビューに出る（M5）', async ({
        page,
    }) => {
        await freshEditor(page, `typing-m5-${stamp}`);

        const field = page.locator('#prop-text');
        await field.click();
        await field.press('ControlOrMeta+a');
        await page.keyboard.type('焼きたてのパン');

        // 打っている最中は「入力中」
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '入力中',
        );
        // 欄にカーソルを置いたまま待つだけで保存される
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
            {
                timeout: 10_000,
            },
        );
        await expect(field).toBeFocused();
        await expect(canvasText(page)).toContainText('焼きたてのパン', {
            timeout: 10_000,
        });

        // 読み直しても残っている
        await page.reload();
        await expect(canvasText(page)).toContainText('焼きたてのパン');
    });

    test('プレビューの見出しを押して打つと、その見出しが変わる（M4）', async ({
        page,
    }) => {
        await freshEditor(page, `typing-m4-${stamp}`);

        // 右の欄からはいったん離れておく（プレビューから始める人の手順）
        await page.getByTestId('editor-save-state').click();
        await canvasText(page)
            .getByText('見出しを入力', { exact: true })
            .first()
            .click();

        await expect(page.locator('#prop-text')).toBeFocused();
        await page.keyboard.press('ControlOrMeta+a');
        await page.keyboard.type('季節のおすすめ');

        await expect(canvasText(page)).toContainText('季節のおすすめ', {
            timeout: 10_000,
        });
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
            {
                timeout: 10_000,
            },
        );
    });

    test('ブロックを置いて欄を触っても「追加する」が見えている（N2）', async ({
        page,
    }) => {
        await freshEditor(page, `typing-n2-${stamp}`);

        /*
         * 右の欄の下の方（選択中のブロックの欄）を触ると、右の欄がそこまで下がる。
         * **部品を押す前に**見る — 押すと playwright が部品の所まで戻してしまい、見えなくても通る。
         */
        await page.locator('#prop-text').click();
        await page.getByTestId('editor-delete').scrollIntoViewIfNeeded();
        await expect(page.getByTestId('palette-richtext')).toBeInViewport();
        await expect(page.getByTestId('palette-image')).toBeInViewport();

        // そのまま次のブロックを足せる
        await page.getByTestId('palette-richtext').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
    });
});
