import { expect, test } from '@playwright/test';

// Phase 5 Template Import — 変換後サイトの機能検証（spec §59）。
// 前提: agentcms template apply 済み + TemplateContentSeeder 投入済み。

const PAGES = [
    { path: '/', h1: '自然体のデザインで、ブランドを育てる。' },
    { path: '/about', h1: 'About' },
    { path: '/service', h1: 'Service' },
    { path: '/works', h1: 'Works' },
    { path: '/contact', h1: 'Contact' },
];

test.describe('Template Import — 変換後サイト', () => {
    for (const { path, h1 } of PAGES) {
        test(`${path} が import された design で表示される`, async ({
            page,
        }) => {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            const h1Text = await page.locator('h1').innerText();
            expect(h1Text.replace(/\s+/g, '')).toBe(h1.replace(/\s+/g, ''));

            // 共通 header / footer（layout 共通化の確認）
            await expect(page.locator('header#site-header')).toBeVisible();
            await expect(page.locator('footer#site-footer')).toBeVisible();
            await expect(
                page.locator('header#site-header .gnav a'),
            ).toHaveCount(4);

            // import された CSS が実際に適用されている（class 保持 + スタイル反映）
            const btnCount = await page.locator('.btn').count();
            const btnColor =
                btnCount > 0
                    ? await page
                          .locator('.btn')
                          .first()
                          .evaluate(
                              (el) => getComputedStyle(el).backgroundColor,
                          )
                    : null;

            if (btnColor !== null) {
                // --green: #2e7d32（CTA 内の白抜きボタンは除く）
                expect(['rgb(46, 125, 50)', 'rgb(255, 255, 255)']).toContain(
                    btnColor,
                );
            }
        });
    }

    test('Works が CMS から表示される（6 件 + Media 画像）', async ({
        page,
    }) => {
        await page.goto('/works');
        const cards = page.locator('.work-card');
        await expect(cards).toHaveCount(6);
        await expect(cards.first().locator('h3')).toHaveText(
            '花屋のオンラインストア',
        );
        await expect(cards.first().locator('.work-card-meta')).toHaveText(
            'EC / 2026',
        );
        // Media Entity 経由の画像が実際に表示されている
        const img = cards.first().locator('img');
        await expect(img).toHaveAttribute('src', /storage\/media\/imported-/);
        const naturalWidth = await img.evaluate(
            (el) => (el as HTMLImageElement).naturalWidth,
        );
        expect(naturalWidth).toBe(320);
    });

    test('トップに works preview 4 件と news 3 件（CMS 由来）', async ({
        page,
    }) => {
        await page.goto('/');
        await expect(page.locator('.works-preview .work-card')).toHaveCount(4);
        const news = page.locator('.news-item');
        await expect(news).toHaveCount(3);
        await expect(news.first()).toContainText('夏季休業のお知らせ');
        await expect(news.first()).toContainText('2026.07.01');

        // news は href="#" プレースホルダーから実リンクへ変換されている
        const href = await news.first().locator('a').getAttribute('href');
        expect(href).toContain('/news/imported-news-1');
        const res = await page.request.get(href!);
        expect(res.status()).toBe(200);
    });

    test('anchor リンクが保持される（/service#plans）', async ({ page }) => {
        await page.goto('/');
        await page.click('a[href$="/service#plans"]');
        await expect(page).toHaveURL(/\/service#plans$/);
        await expect(page.locator('#plans')).toBeVisible();
    });

    test('responsive nav が既存 JS のまま動作する（モバイル）', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');
        const toggle = page.locator('.nav-toggle');
        const nav = page.locator('#global-nav');
        await expect(toggle).toBeVisible();
        await expect(nav).toBeHidden();
        await toggle.click();
        await expect(nav).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await toggle.click();
        await expect(nav).toBeHidden();
    });

    test('スクロールで header へ is-scrolled が付与される（既存 JS 保持）', async ({
        page,
    }) => {
        await page.goto('/service');
        await page.evaluate(() => window.scrollTo(0, 300));
        await expect(page.locator('header#site-header')).toHaveClass(
            /is-scrolled/,
        );
    });

    test('metadata が保持される（title / description / OGP）', async ({
        page,
    }) => {
        await page.goto('/');
        await expect(page).toHaveTitle('Green Leaf Design | ホーム');
        const description = page.locator('meta[name="description"]');
        await expect(description).toHaveAttribute(
            'content',
            /植物のように育つウェブサイト/,
        );
        await expect(
            page.locator('meta[property="og:site_name"]'),
        ).toHaveAttribute('content', 'Green Leaf Design');
        // canonical は旧ドメイン絶対 URL から現在の URL へ変換される
        await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
            'href',
            /localhost:8180/,
        );
    });

    test('mailto / tel / 外部リンクは変換されない', async ({ page }) => {
        await page.goto('/about');
        await expect(
            page.locator('footer a[href="tel:075-000-0000"]'),
        ).toHaveCount(1);
        await expect(
            page.locator('footer a[href="mailto:hello@greenleaf.example"]'),
        ).toHaveCount(1);
    });
});
