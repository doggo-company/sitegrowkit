import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// 配布したサイトの画面から予約・取り消し・即公開を往復する。
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

function artisan(code: string): string {
    return execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            code,
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    ).trim();
}

for (const width of [1280, 390]) {
    test(`幅${width}: 予約を取り消しても本文が残り、明示的に公開できる`, async ({
        page,
    }) => {
        execFileSync(
            'docker',
            ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
            { cwd: composeCwd },
        );
        await page.setViewportSize({ width, height: 850 });
        await login(page);
        const slug = `scheduled-${width}-${Date.now()}`;
        await createDraftPage(page, slug);
        const editUrl = page.url();
        await page
            .getByTestId('page-publish-at-input')
            .fill('2030-09-10T09:00');
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '公開予約',
        );
        await expect(page.getByTestId('page-published-at')).toContainText(
            '2030-09-10 09:00',
        );
        expect((await page.request.get(`/${slug}`)).status()).toBe(404);
        expect(
            await (await page.request.get('/sitemap.xml')).text(),
        ).not.toContain(`/${slug}`);
        await page.reload();
        await expect(page.getByTestId('page-publish-at-input')).toHaveValue(
            '2030-09-10T09:00',
        );
        await page.getByTestId('page-unpublish-button').click();
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '下書き',
        );
        await expect(page.getByTestId('page-publish-at-input')).toHaveValue('');
        expect((await page.request.get(`/${slug}`)).status()).toBe(404);
        const preview = await page
            .getByTestId('page-preview-link')
            .getAttribute('href');
        expect(await (await page.request.get(preview!)).text()).toContain(
            '予約公開の本文',
        );
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '公開中',
        );
        const published = await page.request.get(`/${slug}`);
        expect(published.status()).toBe(200);
        expect(await published.text()).toContain('予約公開の本文');
        await page.getByTestId('page-unpublish-button').click();
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '下書き',
        );
        expect((await page.request.get(`/${slug}`)).status()).toBe(404);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: `test-results/page-scheduling-${width}.png`,
            fullPage: true,
        });
        expect(page.url()).toBe(editUrl);
    });
}

test('時計が予約時刻に到達すると、操作や再起動なしで公開される', async ({
    page,
}) => {
    test.setTimeout(100_000);
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd },
    );
    await login(page);
    const slug = `clock-${Date.now()}`;
    await createDraftPage(page, slug);
    // 実サーバーの時計・timezone で次の分を選ぶ。DB の公開日時を書き換えて試験を通さない。
    const at = artisan(
        'echo now()->addMinute()->startOfMinute()->format("Y-m-d\\TH:i");',
    );
    await page.getByTestId('page-publish-at-input').fill(at);
    await page.getByTestId('page-publish-button').click();
    await expect(page.getByTestId('page-status-badge')).toHaveText('公開予約');
    expect((await page.request.get(`/${slug}`)).status()).toBe(404);
    await expect
        .poll(async () => (await page.request.get(`/${slug}`)).status(), {
            timeout: 65_000,
            intervals: [1000],
        })
        .toBe(200);
    expect(await (await page.request.get('/sitemap.xml')).text()).toContain(
        `/${slug}`,
    );
    await page.reload();
    await expect(page.getByTestId('page-status-badge')).toHaveText('公開中');
});
