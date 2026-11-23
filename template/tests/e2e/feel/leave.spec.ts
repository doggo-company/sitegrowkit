import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/*
 * 打った内容を黙って消さない（UX 一周 14 周目 R14-1・R14-2・R14-3 / docs/feel-bar.md）。
 * 触り心地の門の一部として kit-verify から回る。
 *
 * - R14-1: ブロック編集で打ってすぐブラウザの「戻る」→ 最後の打ち込みが消えた（自動保存の待ちのあいだ）
 * - R14-2: サイト設定で打って、保存せずにサイドバー・「戻る」→ 窓が出ずに消えた
 * - R14-3: メニューの表示名で同じ
 */

test.use({
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

/** ブラウザの確認（confirm / beforeunload）は使わない。出たら記録して失敗にする */
function noNativeDialogs(page: Page): string[] {
    const native: string[] = [];
    page.on('dialog', (d) => {
        native.push(d.message());
        void d.dismiss();
    });

    return native;
}

/** 書きかけで離れようとすると、画面の中の窓が出る。「残る」で値が残り、「保存せずに移る」で移れる */
async function expectGuard(
    page: Page,
    field: ReturnType<Page['locator']>,
    typed: string,
    leave: () => Promise<unknown>,
    where: string,
): Promise<void> {
    const url = page.url();
    await leave();
    const dialog = page.getByTestId('leave-confirm');
    await expect(
        dialog,
        `${where}: 窓が出ずに移った（打った内容が消える）`,
    ).toBeVisible();
    await dialog.getByRole('button', { name: 'この画面に残る' }).click();
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(url);
    await expect(field).toHaveValue(typed);
}

test.describe('打った内容を黙って消さない（R14）', () => {
    test('サイト設定: 保存せずにサイドバー・「戻る」で離れると、窓で確かめる（R14-2）', async ({
        page,
    }) => {
        const native = noNativeDialogs(page);
        // 画面の中の移動で開く（goto で開くと「戻る」が文書ごとの移動になり、ブラウザの確認に回る）
        await page.goto('/dashboard');
        await page
            .getByRole('link', { name: 'サイト設定', exact: true })
            .first()
            .click();
        await page.waitForURL(/\/admin\/site$/);
        const field = page
            .locator('main input[type=text], main input:not([type])')
            .first();
        await expect(field).toBeVisible();
        const typed = `${await field.inputValue()} 書きかけ`;
        await field.fill(typed);

        await expectGuard(
            page,
            field,
            typed,
            () =>
                page
                    .getByRole('link', { name: 'ダッシュボード', exact: true })
                    .first()
                    .click(),
            'サイト設定・サイドバー',
        );
        await expectGuard(
            page,
            field,
            typed,
            () => page.goBack(),
            'サイト設定・戻る',
        );
        expect(native).toEqual([]);
    });

    test('メニュー: 表示名を打って保存せずに「戻る」・サイドバーで離れると、窓で確かめる（R14-3）', async ({
        page,
    }) => {
        const native = noNativeDialogs(page);
        await page.goto('/admin/menus');
        await page.locator('main a[href*="/admin/menus/"]').first().click();
        await page.waitForURL(/\/admin\/menus\/[^/]+$/);
        const field = page
            .locator('main input[type=text], main input:not([type])')
            .first();
        await expect(field).toBeVisible();
        const typed = '料金表 書きかけ';
        await field.fill(typed);

        await expectGuard(
            page,
            field,
            typed,
            () => page.goBack(),
            'メニュー・戻る',
        );
        await expectGuard(
            page,
            field,
            typed,
            () =>
                page
                    .getByRole('link', { name: 'フォーム', exact: true })
                    .first()
                    .click(),
            'メニュー・サイドバー',
        );
        expect(native).toEqual([]);
    });

    test('ブロック編集: 打ってすぐブラウザの「戻る」でも、最後の打ち込みが残る（R14-1）', async ({
        page,
    }) => {
        const native = noNativeDialogs(page);
        const slug = `leave-${Date.now()}`;
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`離れる ${slug}`);
        await page.getByTestId('page-slug-input').fill(slug);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
        const editUrl = page.url();
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL(/\/editor$/);
        const editorUrl = page.url();
        await page.getByTestId('canvas-empty-start').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );

        const field = page.locator('#prop-text');
        await field.click();
        await field.press('ControlOrMeta+a');
        await page.keyboard.type('打ってすぐ戻る');
        // 待たずに戻る（自動保存の待ちのあいだ）
        await page.goBack();
        await page.waitForURL(editUrl, { timeout: 10_000 });
        // popstate は保存待ちの間にも一度この URL になる。設定画面への移動完了まで待つ。
        await expect(page.getByTestId('page-editor-link')).toBeVisible();

        await page.goto(editorUrl);
        await expect(
            page
                .locator('#prop-text')
                .or(page.getByTestId('editor-canvas'))
                .first(),
        ).toBeVisible();
        await expect(
            page.frameLocator('[data-testid="editor-canvas"]').locator('main'),
            '戻る直前の打ち込みが消えた',
        ).toContainText('打ってすぐ戻る', { timeout: 10_000 });
        expect(native).toEqual([]);
    });
});
