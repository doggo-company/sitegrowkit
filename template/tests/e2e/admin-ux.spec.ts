import { expect, test } from '@playwright/test';

/**
 * Phase 8.5-F — Owner Final Visual / UX Review Findings の回帰。
 * F-UX1 branding / F-UX2 タイトルリンク / F-UX3 公開ページ導線 /
 * F-UX4 View Site / F-UX5 テーマ切り替え / F-UX9 モバイルナビ。
 */
test.describe('管理画面 UX（認証済み）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('F-UX1: Laravel のロゴマークを出さない', async ({ page }) => {
        await page.goto('/dashboard');

        // 旧 Laravel マークの path データが残っていないこと
        const svgs = await page
            .locator('svg path')
            .evaluateAll((nodes) =>
                nodes.map((n) => n.getAttribute('d') ?? ''),
            );

        expect(svgs.some((d) => d.startsWith('M17.2 5.633'))).toBe(false);
        expect(await page.locator('img[src*="laravel" i]').count()).toBe(0);
    });

    test('F-UX2: 一覧のタイトルから編集画面へ入れる', async ({ page }) => {
        await page.goto('/admin/news');

        const title = page.locator('[data-testid="row-title-link"]').first();
        await expect(title).toBeVisible();
        const label = (await title.textContent())?.trim() ?? '';

        await title.click();
        await page.waitForURL('**/admin/news/*/edit');
        await expect(page.locator('#title')).toHaveValue(label);
    });

    test('F-UX2: 「編集」ボタンも同じ編集画面へ入る', async ({ page }) => {
        await page.goto('/admin/news');
        const row = page.locator('tbody tr').first();
        const titleHref = await row
            .locator('[data-testid="row-title-link"]')
            .getAttribute('href');

        await row.getByRole('link', { name: '編集' }).click();
        await page.waitForURL('**/edit');
        expect(page.url()).toContain(titleHref ?? '');
    });

    test('F-UX3: 公開中のコンテンツから公開ページを開ける', async ({
        page,
    }) => {
        await page.goto('/admin/news');
        const link = page.locator('[data-testid="row-public-link"]').first();
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('target', '_blank');
        await expect(link).toHaveAttribute('rel', /noopener/);

        const href = (await link.getAttribute('href')) ?? '';
        expect(href).toMatch(/^\/news\//);

        const response = await page.request.get(href);
        expect(response.status()).toBe(200);
    });

    test('F-UX3: 下書きには公開ページへの無効なリンクを出さない', async ({
        page,
    }) => {
        const title = `下書き ${Date.now()}`;

        await page.goto('/admin/news/create');
        await page.fill('#title', title);
        await page.fill('#body', '下書き本文');
        await page.click('#status');
        await page.getByRole('option', { name: '下書き' }).click();
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/news');

        const draftRow = page.locator('tbody tr').filter({ hasText: title });
        await expect(draftRow).toBeVisible();
        await expect(draftRow).toContainText('下書き');
        // 公開できない状態では公開ページへのリンクを出さない
        await expect(
            draftRow.locator('[data-testid="row-public-link"]'),
        ).toHaveCount(0);
        // タイトルからの編集導線は下書きでも使える
        await expect(
            draftRow.locator('[data-testid="row-title-link"]'),
        ).toBeVisible();
    });

    test('F-UX3: 編集画面から公開ページを開ける', async ({ page }) => {
        await page.goto('/admin/news');

        // 公開済みの行を選ぶ（下書きには公開ページが無いため）
        const publishedRow = page
            .locator('tbody tr')
            .filter({ has: page.locator('[data-testid="row-public-link"]') })
            .first();
        await publishedRow.locator('[data-testid="row-title-link"]').click();
        await page.waitForURL('**/edit');

        const link = page.locator('[data-testid="form-public-link"]');
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('target', '_blank');
    });

    test('F-UX4: グローバルナビから公開サイトへ行ける', async ({ page }) => {
        await page.goto('/dashboard');

        const link = page.locator('[data-testid="view-site-link"]');
        await expect(link).toBeVisible();
        await expect(link).toHaveText(/サイトを見る/);
        await expect(link).toHaveAttribute('href', '/');
        await expect(link).toHaveAttribute('rel', /noopener/);
    });

    test('F-UX5: ライト / ダーク / システムを切り替えられる', async ({
        page,
    }) => {
        await page.goto('/dashboard');

        const toggle = page.locator('[data-testid="theme-toggle"]');
        await expect(toggle).toBeVisible();

        await toggle.click();
        await page.locator('[data-testid="theme-option-dark"]').click();
        await expect(page.locator('html')).toHaveClass(/dark/);

        // reload しても維持される
        await page.reload();
        await expect(page.locator('html')).toHaveClass(/dark/);

        await toggle.click();
        await page.locator('[data-testid="theme-option-light"]').click();
        await expect(page.locator('html')).not.toHaveClass(/dark/);

        // 公開サイトのテーマには影響しない
        await page.goto('/');
        await expect(page.locator('html')).not.toHaveClass(/dark/);
    });

    test('F-UX5: テーマ切り替えはキーボードで操作できる', async ({ page }) => {
        await page.goto('/dashboard');

        const toggle = page.locator('[data-testid="theme-toggle"]');
        await toggle.focus();
        await expect(toggle).toBeFocused();
        await expect(toggle).toHaveAttribute('aria-label', /管理画面の色/);
    });
});

test.describe('管理画面 モバイルナビゲーション（F-UX9）', () => {
    test.use({
        storageState: 'tests/e2e/.auth/admin.json',
        viewport: { width: 390, height: 844 },
    });

    test('ハンバーガーで drawer を開閉できる', async ({ page }) => {
        await page.goto('/dashboard');

        const trigger = page.locator('[data-testid="sidebar-trigger"]');
        await expect(trigger).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-label', /メニュー/);
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await expect(trigger).toHaveAttribute('aria-controls', 'app-sidebar');

        await trigger.click();
        await expect(page.locator('#app-sidebar')).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');

        // Escape で閉じる
        await page.keyboard.press('Escape');
        await expect(page.locator('#app-sidebar')).toBeHidden();
    });

    test('画面遷移すると drawer が閉じる', async ({ page }) => {
        await page.goto('/dashboard');
        await page.locator('[data-testid="sidebar-trigger"]').click();
        await expect(page.locator('#app-sidebar')).toBeVisible();

        await page
            .locator('#app-sidebar')
            .getByRole('link', { name: 'ニュース' })
            .click();
        await page.waitForURL('**/admin/news');
        await expect(page.locator('#app-sidebar')).toBeHidden();
    });

    test('横スクロールが発生しない', async ({ page }) => {
        await page.goto('/dashboard');

        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        );

        expect(overflow).toBe(false);
    });
});

test.describe('メディア UX（F-UX6 / F-UX7）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('サムネイルから専用の編集ページへ入る（modal ではない）', async ({
        page,
    }) => {
        await page.goto('/admin/media');

        const link = page.locator('[data-testid="media-name-link"]').first();

        if ((await link.count()) === 0) {
            test.skip(true, 'メディアが無い');
        }

        await link.click();
        await page.waitForURL(/\/admin\/media\/\d+$/);

        await expect(
            page.locator('[data-testid="media-preview"]'),
        ).toBeVisible();
        await expect(page.locator('#alt')).toBeVisible();
        await expect(
            page.locator('[data-testid="media-replace-input"]'),
        ).toBeVisible();
        // modal は使わない
        await expect(page.locator('[role="dialog"]')).toHaveCount(0);
    });
});
