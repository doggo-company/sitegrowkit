import { execFileSync } from 'node:child_process';
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

// 保存済みの内容を複製し、未保存の変更を置き去りにしない。
for (const width of [1280, 390]) {
    test(`幅${width}: 記事を下書きへ複製して独立して編集できる`, async ({
        page,
    }) => {
        execFileSync(
            'docker',
            ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
            { cwd: composeCwd },
        );
        await page.setViewportSize({ width, height: 850 });
        await login(page);
        const title = `記事の複製 ${width}-${Date.now()}`;
        await page.goto('/admin/post/create');
        await page.fill('#title', title);
        await page.fill('#body', '元の本文を残します。');
        await page.fill('#excerpt', '複製でも残る抜粋');
        await page.locator('button[type="submit"]').click();
        await page.waitForURL('**/admin/post');
        await page
            .getByTestId('row-title-link')
            .filter({ hasText: title })
            .click();
        await page.waitForURL('**/admin/post/*/edit');
        const originalUrl = page.url();
        const duplicate = page.getByRole('button', {
            name: '下書きへ複製',
            exact: true,
        });
        await expect(duplicate).toBeEnabled();
        await page.fill('#title', `${title}未保存`);
        await expect(duplicate).toBeDisabled();
        await page.fill('#title', title);
        await expect(duplicate).toBeEnabled();
        await duplicate.click();
        await expect(page).not.toHaveURL(originalUrl);
        await expect(page.locator('#title')).toHaveValue(`${title}（コピー）`);
        await expect(page.locator('#status')).toContainText('下書き');
        await expect(page.locator('#published_at')).toHaveValue('');
        const copyUrl = page.url();
        await page.reload();
        await expect(page.locator('#body')).toContainText(
            '元の本文を残します。',
        );
        await expect(page.locator('#excerpt')).toHaveValue('複製でも残る抜粋');
        await page.fill('#body', 'コピーだけを書き換えます。');
        await page.locator('button[type="submit"]').click();
        await page.waitForURL('**/admin/post');
        await page.goto(originalUrl);
        await expect(page.locator('#body')).toContainText(
            '元の本文を残します。',
        );
        await page.goto(copyUrl);
        await expect(page.locator('#body')).toContainText(
            'コピーだけを書き換えます。',
        );
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
    });
}
