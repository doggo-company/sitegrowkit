import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 隔離サイトの保守結果と、検査後もログイン状態が維持されることを確認する。
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

// 実コマンドの記録を画面から確認し、古い記録に変われば合格表示を止める。
for (const width of [1280, 390]) {
    test.describe(`定期保守 ${width}px`, () => {
        test.use({ viewport: { width, height: 850 } });
        test('確認結果・未確認・期限切れの表示を区別する', async ({ page }) => {
            const reportPath = join(composeCwd, 'storage/app/private/maintenance/report.json');
            const original = readFileSync(reportPath, 'utf8');

            try {
                await login(page);
                await page.goto('/admin/readiness');
                const report = page.getByTestId('maintenance-report');
                await expect(report).toContainText('PHP依存ライブラリ');
                await expect(report).toContainText('HTTPS証明書');
                await expect(report).toContainText('確認済み');
                await expect(report).toContainText('HTTPSの公開URLが設定されていないため未確認です。');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                const data = JSON.parse(original) as { checked_at: number };
                data.checked_at -= 3 * 86400;
                writeFileSync(reportPath, JSON.stringify(data));
                await page.reload();
                await expect(report).toContainText('再確認が必要');
                await expect(report).not.toContainText('確認済み');
                await expect(report).toContainText('確認から48時間を超えたか');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                await page.goto('/dashboard');
                await expect(page).toHaveURL(/\/dashboard$/);
            } finally {
                writeFileSync(reportPath, original);
            }
        });
    });
}
