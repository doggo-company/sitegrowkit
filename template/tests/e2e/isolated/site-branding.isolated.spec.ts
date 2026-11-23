import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 配布後の新品サイトで、画像付き記事を作成して公開する業務を通す。
function isolatedTarget(): { appUrl: string; composeCwd: string } {
    const appUrl = process.env.APP_URL;
    const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

    if (!appUrl || !composeCwd) {
        throw new Error(
            'APP_URL と AGENTCMS_COMPOSE_CWD を明示してください（使い捨てプロジェクト専用の spec です）',
        );
    }

    let parsed: URL;

    try {
        parsed = new URL(appUrl);
    } catch {
        throw new Error(`APP_URL を URL として読めません: ${appUrl}`);
    }

    if (parsed.protocol !== 'http:') {
        throw new Error(
            `手元の使い捨て以外へは向けられません（http のみ）: ${appUrl}`,
        );
    }

    if (!['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname)) {
        throw new Error(`手元のホストではありません: ${parsed.hostname}`);
    }

    if (parsed.port === '' || parsed.port === '8180') {
        throw new Error(`使い捨て専用の port を明示してください: ${appUrl}`);
    }

    if (resolve(composeCwd) === resolve(process.cwd())) {
        throw new Error(`repo 自身を対象にできません: ${composeCwd}`);
    }

    for (const marker of [
        '.agentcms/local/admin-credentials.json',
        'docker-compose.yml',
        '.env',
    ]) {
        if (!existsSync(join(composeCwd, marker))) {
            throw new Error(
                `生成プロジェクトに見えません（${marker} が無い）: ${composeCwd}`,
            );
        }
    }

    return { appUrl, composeCwd };
}

const { composeCwd } = isolatedTarget();

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

// サイト設定から画像を選び、実際の公開ヘッダーとブラウザアイコンへ反映する。
for (const width of [1280, 390]) {
    test.describe(`ブランド設定 ${width}px`, () => {
        test.use({ viewport: { width, height: 850 } });
        test('ロゴとアイコンの選択・保存・再読み込み・解除ができる', async ({ page }) => {
            await login(page);
            const name = `会社ロゴ ${Date.now()}`;
            await page.goto('/admin/media');
            await page.setInputFiles('#media-file', 'public/brand/sitegrowkit-mark.png');
            await page.fill('#media-name', name);
            await page.fill('#media-alt', '検証用ロゴ');
            await page.locator('[data-testid="media-upload-form"] button[type="submit"]').click();
            await expect(page.getByTestId('media-grid')).toContainText(name);
            await page.goto('/admin/site');
            const siteName = await page.locator('#site_name').inputValue();

            for (const key of ['site_logo_id', 'site_icon_id']) {
                await page.locator(`#${key}-label`).locator('..').getByTestId('media-picker-open').click();
                await page.getByRole('dialog').locator('[data-testid^="media-choice-"]', { hasText: name }).click();
                await expect(page.getByRole('dialog')).toHaveCount(0);
            }

            await page.getByRole('button', { name: '保存', exact: true }).click();
            await expect(page.getByTestId('media-picker-dialog')).toHaveCount(0);
            await page.waitForLoadState('networkidle');
            await page.reload();
            await expect(page.locator('#site_logo_id-label').locator('..').getByTestId('media-picker-current')).toBeVisible();
            const expectedUrl = await page.locator('#site_logo_id-label').locator('..').getByTestId('media-picker-current').getAttribute('src');
            expect(expectedUrl).toBeTruthy();

            for (const url of ['/about', '/blog']) {
                await page.goto(url);
                const logo = page.locator('header .site-brand__image');
                await expect(logo).toHaveAttribute('src', expectedUrl!);
                await expect(logo).toHaveAttribute('alt', siteName);
                await expect(logo).toBeVisible();
                expect(await logo.evaluate((node: HTMLImageElement) => node.complete && node.naturalWidth > 0)).toBe(true);
                await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', expectedUrl!);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            }

            await page.goto('/admin/site');

            for (const key of ['site_logo_id', 'site_icon_id']) {
                await page.locator(`#${key}-label`).locator('..').getByTestId('media-picker-clear').click();
            }

            await page.getByRole('button', { name: '保存', exact: true }).click();
            await page.waitForLoadState('networkidle');
            await page.goto('/about');
            await expect(page.locator('header .site-brand__image')).toHaveCount(0);
            await expect(page.locator('header')).toContainText(siteName);
            await expect(page.locator('link[rel="icon"]')).toHaveCount(0);
        });
    });
}
