import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

// Figma Import（Phase 6 / ADR-0011）の実装検証。
// - Viewport Matching: Figma Frame 幅（Desktop 1440 / Mobile 375）へ合わせる（SPEC §73）
// - Structural Visual Verification: 要素数 / 可視性 / responsive / asset / a11y（SPEC §76）
// - Screenshot は __screenshots__/figma-*/ へ保存（synthetic fixture のため
//   Figma render とのピクセル比較はしない — 実測は Live smoke test / Phase 6.1）

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 375, height: 812 };

test.beforeAll(() => {
    // global-setup の migrate:fresh 後に Figma Design Fixture データを投入する
    const cwd = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
    execSync(
        "docker compose exec -T app php artisan db:seed --class='Database\\Seeders\\FigmaContentSeeder' --force",
        { cwd, stdio: 'inherit' },
    );
});

test.describe('figma import — home screen', () => {
    test('desktop 1440: semantic 構造と CMS 統合が機能する', async ({
        page,
    }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/figma/home');

        // Semantic Web（SPEC §29）: header / nav / main / footer / 見出し階層
        await expect(page.locator('header.fig-header')).toBeVisible();
        await expect(page.locator('nav.fig-nav')).toBeVisible();
        await expect(page.locator('main#main')).toBeVisible();
        await expect(page.locator('footer.fig-footer')).toBeVisible();
        await expect(page.locator('h1')).toHaveCount(1);
        await expect(page.locator('h1')).toHaveText('Design that ships');

        // CMS First: works カードは Figma 直書きではなく CMS 由来。
        // DB には Green Leaf（Phase 5 seed）と Figma seed が共存し、
        // home は published_at 降順の 3 件のみ表示する（Runtime Content Source は CMS）
        const cards = page.locator('.fig-card');
        await expect(cards).toHaveCount(3);
        const titles = page.locator('.fig-card-title');

        for (let i = 0; i < 3; i++) {
            await expect(titles.nth(i)).not.toBeEmpty();
        }

        // CSS token が適用されている（accent ボタン）
        const btn = page.locator('.fig-btn-primary').first();
        await expect(btn).toBeVisible();
        const bg = await btn.evaluate(
            (el) => getComputedStyle(el).backgroundColor,
        );
        expect(bg).toBe('rgb(15, 76, 70)'); // --fig-color-accent #0f4c46

        // grid layout が適用されている（Auto Layout → CSS Grid candidate）
        const grid = page.locator('.fig-grid').first();
        const display = await grid.evaluate(
            (el) => getComputedStyle(el).display,
        );
        expect(display).toBe('grid');

        await page.screenshot({
            path: 'tests/e2e/__screenshots__/figma-home-desktop.png',
            fullPage: true,
        });
    });

    test('mobile 375: responsive で縦積みになる', async ({ page }) => {
        await page.setViewportSize(MOBILE);
        await page.goto('/figma/home');
        await expect(page.locator('h1')).toBeVisible();

        // fig-row は mobile で flex-direction: column（Figma Mobile variant の意図）
        const hero = page.locator('.fig-sec-hero .fig-row').first();

        if ((await hero.count()) > 0) {
            const dir = await hero.evaluate(
                (el) => getComputedStyle(el).flexDirection,
            );
            expect(dir).toBe('column');
        }

        // カードが横にはみ出していない
        const card = page.locator('.fig-card').first();
        const box = await card.boundingBox();
        expect(box?.width ?? 0).toBeLessThanOrEqual(MOBILE.width);

        await page.screenshot({
            path: 'tests/e2e/__screenshots__/figma-home-mobile.png',
            fullPage: true,
        });
    });
});

test.describe('figma import — works screen', () => {
    test('desktop: 一覧が CMS から描画される', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/figma/works');
        // published works 全件（Green Leaf 6 + Figma 3）が CMS から描画される
        const count = await page.locator('.fig-card').count();
        expect(count).toBeGreaterThanOrEqual(3);
        // Figma Design Fixture seed が CMS 経由で表示されている
        await expect(
            page.locator('.fig-card', { hasText: 'WORKS A' }),
        ).toHaveCount(1);
        await page.screenshot({
            path: 'tests/e2e/__screenshots__/figma-works-desktop.png',
            fullPage: true,
        });
    });

    test('mobile: 描画とスクリーンショット', async ({ page }) => {
        await page.setViewportSize(MOBILE);
        await page.goto('/figma/works');
        await expect(page.locator('.fig-card').first()).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/__screenshots__/figma-works-mobile.png',
            fullPage: true,
        });
    });
});

test.describe('figma import — links / assets / a11y', () => {
    test('内部リンクが 200 を返す（broken links）', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/figma/home');
        const hrefs = await page
            .locator('a[href]')
            .evaluateAll((els) =>
                els
                    .map((el) => el.getAttribute('href') ?? '')
                    .filter(
                        (h) =>
                            h.startsWith('/') ||
                            h.startsWith('http://localhost'),
                    ),
            );
        expect(hrefs.length).toBeGreaterThan(0);

        for (const href of [...new Set(hrefs)]) {
            const res = await request.get(href);
            expect(res.status(), `broken link: ${href}`).toBe(200);
        }
    });

    test('画像 asset が実際に読み込まれる（Reference-Based acquisition）', async ({
        page,
    }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/figma/home');
        const images = page.locator('main img');
        const count = await images.count();
        expect(count).toBeGreaterThanOrEqual(1); // hero (static) + works media

        for (let i = 0; i < count; i++) {
            const loaded = await images
                .nth(i)
                .evaluate(
                    (el) =>
                        (el as HTMLImageElement).complete &&
                        (el as HTMLImageElement).naturalWidth > 0,
                );
            expect(loaded, `image #${i} not loaded`).toBe(true);
        }
    });

    test('アクセシビリティ基礎（SPEC §30）', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/figma/home');
        // lang / img alt / nav ラベル / 装飾 vector の aria-hidden
        expect(await page.locator('html').getAttribute('lang')).toBeTruthy();
        const imgsWithoutAlt = await page.locator('img:not([alt])').count();
        expect(imgsWithoutAlt).toBe(0);
        expect(
            await page.locator('nav.fig-nav').getAttribute('aria-label'),
        ).toBeTruthy();
        const vecNotHidden = await page
            .locator('.fig-vec:not([aria-hidden="true"])')
            .count();
        expect(vecNotHidden).toBe(0);
        // demo 画面は公開ナビへ載せない（noindex）
        expect(
            await page.locator('meta[name="robots"]').getAttribute('content'),
        ).toContain('noindex');
    });
});
