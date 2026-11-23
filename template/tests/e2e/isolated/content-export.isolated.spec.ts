import { existsSync, readFileSync } from 'node:fs';
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

// 実際に別プロセスを起こし、完成したXMLを画面から取得する。
for (const width of [1280, 390]) {
    test.describe(`内容の書き出し ${width}px`, () => {
        test.use({ viewport: { width, height: 850 } });
        test('管理画面から開始し完成ファイルを持ち出せる', async ({ page }, testInfo) => {
            await login(page);
            await page.goto('/admin/backups');
            await page.getByRole('link', { name: '記事・ページだけを書き出す（WordPress交換用XML）' }).click();
            await expect(page.getByRole('heading', { name: '内容の書き出し', exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'XMLを書き出す', exact: true }).click();
            await expect(page.getByRole('link', { name: 'XMLをダウンロード' })).toBeVisible({ timeout: 60000 });
            await page.reload();
            await expect(page.getByText('書き出しが完了しました', { exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            const pending = page.waitForEvent('download');
            await page.getByRole('link', { name: 'XMLをダウンロード' }).click();
            const download = await pending;
            const output = testInfo.outputPath('content.xml');
            await download.saveAs(output);
            const xml = readFileSync(output, 'utf8');
            const result = await page.evaluate((source) => {
                const doc = new DOMParser().parseFromString(source, 'text/xml');

                return { errors: doc.getElementsByTagName('parsererror').length, items: doc.getElementsByTagName('item').length,
                    version: doc.getElementsByTagNameNS('http://wordpress.org/export/1.2/', 'wxr_version')[0]?.textContent };
            }, xml);
            expect(result.errors).toBe(0);
            expect(result.items).toBeGreaterThan(0);
            expect(result.version).toBe('1.2');
            await page.screenshot({ path: testInfo.outputPath(`content-export-${width}.png`), fullPage: true });
        });
    });
}
