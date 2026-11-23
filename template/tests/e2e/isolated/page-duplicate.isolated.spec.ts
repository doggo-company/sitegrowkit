import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 複製先だけを編集しても元ページが変わらず、勝手に公開されない。
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

/** 公開できるページを 1 枚つくる（section > heading があれば公開できる） */
async function createDraftPage(page: Page, slug: string): Promise<string> {
    const title = `公開予約の確認 ${slug}`;

    await page.goto('/admin/pages/create');
    await page.fill('#page-title', title);
    await page.fill('#page-slug', slug);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/pages/*/edit');

    await page.getByTestId('page-editor-link').click();
    await page.getByTestId('palette-section').click();
    await page.getByTestId('layer-section').click();
    await page.getByTestId('palette-heading').click();
    await page.getByTestId('layer-heading').first().click();
    await page.getByTestId('inspector-prop-text').fill('予約公開の本文');

    await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
    await page.waitForURL('**/admin/pages/*/edit');

    return title;
}

for (const width of [1280, 390]) {
    test(`幅${width}: ページを下書きへ複製して独立して編集できる`, async ({
        page,
    }) => {
        execFileSync(
            'docker',
            ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
            { cwd: composeCwd },
        );
        await page.setViewportSize({ width, height: 850 });
        await login(page);
        const slug = `copy-${width}-${Date.now()}`;
        const title = await createDraftPage(page, slug);
        const originalUrl = page.url();
        const originalPreview = await page
            .getByTestId('page-preview-link')
            .getAttribute('href');
        await page.getByTestId('page-duplicate-button').click();
        await expect(page).not.toHaveURL(originalUrl);
        await expect(page.locator('#page-title')).toHaveValue(
            `${title}（コピー）`,
        );
        await expect(page.locator('#page-slug')).toHaveValue(`${slug}-copy`);
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '下書き',
        );
        const copyUrl = page.url();
        await page.reload();
        await expect(page.locator('#page-slug')).toHaveValue(`${slug}-copy`);
        expect((await page.request.get(`/${slug}-copy`)).status()).toBe(404);
        await page.getByTestId('page-editor-link').click();
        await page.getByTestId('layer-heading').first().click();
        await expect(page.getByTestId('inspector-prop-text')).toHaveValue(
            '予約公開の本文',
        );
        await page
            .getByTestId('inspector-prop-text')
            .fill('複製した側だけの本文');
        await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
        await expect(page).toHaveURL(copyUrl);
        const copyPreview = await page
            .getByTestId('page-preview-link')
            .getAttribute('href');
        expect(await (await page.request.get(copyPreview!)).text()).toContain(
            '複製した側だけの本文',
        );
        const originalHtml = await (
            await page.request.get(originalPreview!)
        ).text();
        expect(originalHtml).toContain('予約公開の本文');
        expect(originalHtml).not.toContain('複製した側だけの本文');
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
    });
}
