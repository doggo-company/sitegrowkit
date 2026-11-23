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

for (const scenario of [
    {
        name: 'PC',
        width: 1280,
        height: 800,
        dpr: 1,
        image: 'public/brand/sitegrowkit-mark.png',
    },
    {
        name: 'スマホ',
        width: 375,
        height: 812,
        dpr: 2,
        image: 'public/imported/images/hero-bg.png',
    },
]) {
    test.describe(scenario.name, () => {
        test.use({
            viewport: { width: scenario.width, height: scenario.height },
            deviceScaleFactor: scenario.dpr,
        });
        test('画像付き記事を公開し、抜粋・SNS画像・使用中保護まで反映する', async ({
            page,
        }) => {
            execFileSync(
                'docker',
                [
                    'compose',
                    'exec',
                    '-T',
                    'app',
                    'php',
                    'artisan',
                    'cache:clear',
                ],
                { cwd: composeCwd },
            );
            await login(page);
            // 投稿型そのものの公開を、管理画面の明示操作で有効にする。
            await page.goto('/admin/content-types/post/edit');
            await page.getByTestId('content-type-public-slug').fill('blog');

            for (const key of ['public', 'publicly_queryable', 'has_archive']) {
                await page
                    .getByTestId(`content-type-registration-${key}`)
                    .check();
            }

            await page
                .getByRole('button', { name: '保存する', exact: true })
                .click();
            await page.waitForURL('**/admin/content-types');

            const stamp = Date.now();
            const name = `紹介写真 ${stamp}`;
            await page.goto('/admin/media');
            await page.setInputFiles('#media-file', scenario.image);
            await page.fill('#media-name', name);
            await page.fill('#media-alt', '記事の紹介写真');
            await page
                .locator(
                    '[data-testid="media-upload-form"] button[type="submit"]',
                )
                .click();
            await expect(page.getByTestId('media-grid')).toContainText(name);

            await page.goto('/admin/post/create');
            // 執筆欄を最初の画面内で操作でき、PCでは設定欄と横に並ぶ。
            await expect(page.locator('#body')).toBeInViewport();
            const writing = await page
                .getByRole('region', { name: '記事の内容', exact: true })
                .boundingBox();
            const settings = await page
                .getByRole('region', { name: '公開と表示の設定', exact: true })
                .boundingBox();
            expect(writing).not.toBeNull();
            expect(settings).not.toBeNull();

            if (page.viewportSize()!.width >= 1280) {
                expect(settings!.x).toBeGreaterThan(
                    writing!.x + writing!.width,
                );
                await expect(
                    page.locator('button[type="submit"]'),
                ).toBeInViewport();
            } else {
                expect(settings!.y).toBeGreaterThan(
                    writing!.y + writing!.height,
                );
            }

            await page.fill('#title', `画像付き記事 ${stamp}`);
            await page.fill('#slug', `photo-${stamp}`);
            await page.fill('#excerpt', '一覧で読める短い紹介文');
            await page.fill('#body', '記事の本文です。');
            // 他の画像カスタムフィールドがあっても、アイキャッチの選択欄を指す。
            await page
                .locator('#main_image_id-label')
                .locator('..')
                .getByTestId('media-picker-open')
                .click();
            await page
                .locator('[data-testid^="media-choice-"]', { hasText: name })
                .click();
            await page.click('#status');
            await page
                .getByRole('option', { name: '公開', exact: true })
                .click();
            const now = new Date(Date.now() - 60000);
            await page.fill(
                '#published_at',
                new Date(now.getTime() - now.getTimezoneOffset() * 60000)
                    .toISOString()
                    .slice(0, 16),
            );
            await page.locator('button[type="submit"]').click();
            await page.waitForURL('**/admin/post');
            await page
                .getByTestId('row-title-link')
                .filter({ hasText: `画像付き記事 ${stamp}` })
                .click();
            await expect(page.locator('#excerpt')).toHaveValue(
                '一覧で読める短い紹介文',
            );
            const publicUrl = await page
                .getByTestId('form-public-link')
                .getAttribute('href');
            expect(publicUrl).toBeTruthy();
            await page.goto(publicUrl!);
            const image = page.locator('img.article__featured');
            await expect(image).toBeVisible();
            expect(
                await image.evaluate(
                    (el: HTMLImageElement) =>
                        el.complete && el.naturalWidth > 0,
                ),
            ).toBe(true);

            if (scenario.dpr === 2) {
                // 実ブラウザが原寸 1600px ではなく縮小版を読み込んだことを確かめる。
                await expect
                    .poll(() =>
                        image.evaluate((el: HTMLImageElement) => el.currentSrc),
                    )
                    .toMatch(/-(640|1280)\.(avif|webp)$/);
                expect(
                    await page.evaluate(
                        () =>
                            document.documentElement.scrollWidth <= innerWidth,
                    ),
                ).toBe(true);
            }

            await expect(
                page.locator('meta[property="og:image"]'),
            ).toHaveAttribute('content', /storage\/media/);
            await page.goto('/blog');
            await expect(page.locator('body')).toContainText(
                '一覧で読める短い紹介文',
            );
            await expect(
                page.locator('img.article__thumbnail').first(),
            ).toBeVisible();
            await page.goto('/admin/media');
            const card = page.locator('[data-testid^="media-item-"]', {
                hasText: name,
            });
            await expect(card).toContainText('使用中');
            await expect(card).toContainText('使用中のため削除できません');
            await expect(
                card.getByRole('button', { name: '削除', exact: true }),
            ).toHaveCount(0);
        });
    });
}
