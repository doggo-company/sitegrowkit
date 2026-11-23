import { expect, test } from '@playwright/test';

// Phase 2 REQUIRED E2E（13 項目）: メディア管理と Works 統合をブラウザ操作で検証
// 1x1 の正常な PNG（バッファ生成）
const VALID_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

test.describe('メディア管理と Works 統合', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    const stamp = Date.now();
    const mediaName = `E2E メディア ${stamp}`;
    const mediaAlt = `E2E 用サンプル画像 ${stamp}`;

    test('アップロード → alt 入力 → 一覧表示', async ({ page }) => {
        // 1-2. ログイン済み（storageState）で Media 画面を開く
        await page.goto('/admin/media');
        await expect(page.locator('h1')).toContainText('メディア');

        // 3-4. 正常画像をアップロードし alt を入力
        await page.setInputFiles('#media-file', {
            name: 'e2e-photo.png',
            mimeType: 'image/png',
            buffer: VALID_PNG,
        });
        await page.fill('#media-name', mediaName);
        await page.fill('#media-alt', mediaAlt);
        await page.click(
            '[data-testid="media-upload-form"] button[type="submit"]',
        );

        // 5. Media 一覧へ表示される
        await expect(page.locator('[data-testid="media-grid"]')).toContainText(
            mediaName,
        );
    });

    test('Works 編集で Media を選択 → 保存 → 公開サイトへ実画像表示', async ({
        page,
    }) => {
        // 6. Works（import 済みの imported-work-6）を編集し、Media を差し替える
        await page.goto('/admin/works');
        await page
            .locator('tr', { hasText: 'ハーブティー' })
            .getByRole('link', { name: '編集' })
            .click();

        // 7. Media を選択（最新 = 直前にアップロードしたもの）
        await page.getByRole('button', { name: '画像を選択' }).click();
        await page.locator('[data-testid^="media-choice-"]').first().click();
        await expect(
            page.locator('[data-testid="media-picker-current"]'),
        ).toBeVisible();

        // 8. 保存
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/works');

        // 9-10. 公開 Works に実画像 + alt / width / height
        await page.goto('/works/imported-work-6');
        const img = page.locator('.article__hero img');
        await expect(img).toBeVisible();
        await expect(img).toHaveAttribute('src', /\/storage\/media\/.+\.png/);
        await expect(img).toHaveAttribute('alt', mediaAlt);
        await expect(img).toHaveAttribute('width', '1');
        await expect(img).toHaveAttribute('height', '1');
        await expect(img).toHaveAttribute('loading', 'eager');
    });

    test('使用中 Media は削除保護される', async ({ page }) => {
        // 11. 使用中バッジの項目は削除ボタンが無効
        await page.goto('/admin/media');
        const usedCard = page
            .locator('[data-testid^="media-item-"]', { hasText: '使用中' })
            .first();
        await expect(usedCard).toBeVisible();
        await expect(
            usedCard.getByRole('button', { name: '削除' }),
        ).toBeDisabled();
    });

    test('未使用 Media は確認後に削除できる', async ({ page }) => {
        // 12. 未使用メディアをアップロードして削除
        const name = `E2E 削除対象 ${stamp}`;
        await page.goto('/admin/media');
        await page.setInputFiles('#media-file', {
            name: 'e2e-delete-me.png',
            mimeType: 'image/png',
            buffer: VALID_PNG,
        });
        await page.fill('#media-name', name);
        await page.click(
            '[data-testid="media-upload-form"] button[type="submit"]',
        );
        await expect(page.locator('[data-testid="media-grid"]')).toContainText(
            name,
        );

        // 削除はサーバーの ConfirmationGate を通る（Phase 9.1B / STEP 7）。
        // 1 回目のクリックで影響範囲つきの確認が返り、承認して初めて消える
        await page
            .locator('[data-testid^="media-item-"]', { hasText: name })
            .getByRole('button', { name: '削除' })
            .click();

        const dialog = page.locator('[data-testid="operation-confirmation"]');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText(name);
        await expect(page.locator('[data-testid="media-grid"]')).toContainText(name);

        await dialog.getByTestId('operation-confirm-button').click();

        await expect(
            page.locator('[data-testid="media-grid"]'),
        ).not.toContainText(name);
    });

    test('不正ファイルのアップロードは拒否される', async ({ page }) => {
        // 13. 拡張子は png だが中身が画像でない → サーバー側で拒否
        await page.goto('/admin/media');
        await page.setInputFiles('#media-file', {
            name: 'broken.png',
            mimeType: 'image/png',
            buffer: Buffer.from('this is not an image at all'),
        });
        await page.click(
            '[data-testid="media-upload-form"] button[type="submit"]',
        );

        await expect(
            page.getByText('画像として読み込めないファイルです', {
                exact: false,
            }),
        ).toBeVisible();
    });
});
