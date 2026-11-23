import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Phase 8 — Rendering Robustness Regression（F-6 調査で確認した正しい挙動の固定化）。
// 公開サイトは Blade SSR + content default visible + progressive enhancement
// （登場演出は .hero h1 の CSS animation のみ・最後は必ず見える）という現行アーキテクチャを
// そのまま検証する。reveal 系の新アーキテクチャ（.rv / IntersectionObserver 等）を
// 追加してはならない — このテストは「JS がなくても内容が読める」ことの保証。

const KEY_SELECTORS = [
    'header#site-header',
    '.hero h1',
    '.works-preview .work-card',
    '.news-item, .news-list li',
    'footer#site-footer',
];

async function expectContentVisible(page: Page): Promise<void> {
    for (const selector of KEY_SELECTORS) {
        const el = page.locator(selector).first();
        await expect(el, selector).toBeVisible();

        // 登場演出（hero の CSS animation）は完了を待つ。恒久的に隠れたままなら fail
        await expect
            .poll(
                () =>
                    el.evaluate((node) =>
                        Number(getComputedStyle(node).opacity),
                    ),
                { message: `${selector} opacity`, timeout: 3000 },
            )
            .toBeGreaterThan(0.5);
    }
}

test.describe('R1: JavaScript disabled でも全コンテンツが読める', () => {
    test.use({ javaScriptEnabled: false });

    test('hero / works / news / footer が JS なしで表示される', async ({
        page,
    }) => {
        const response = await page.goto('/');
        expect(response?.status()).toBe(200);
        await expectContentVisible(page);

        // 本文テキストが実際に含まれる（SSR — major content missing なし）
        const body = await page.locator('body').innerText();
        expect(body).toContain('最近の実績');
        expect(body).toContain('お知らせ');
    });
});

test.describe('R2: print media で主要コンテンツが消えない', () => {
    test('print emulation で hidden にならない', async ({ page }) => {
        await page.goto('/');
        // 登場演出の完了を待ってから print media に切り替える
        await expectContentVisible(page);
        await page.emulateMedia({ media: 'print' });

        for (const selector of KEY_SELECTORS) {
            const el = page.locator(selector).first();
            const style = await el.evaluate((node) => {
                const cs = getComputedStyle(node);

                return {
                    opacity: cs.opacity,
                    visibility: cs.visibility,
                    display: cs.display,
                };
            });
            expect(
                Number(style.opacity),
                `${selector} opacity`,
            ).toBeGreaterThan(0.5);
            expect(style.visibility, selector).not.toBe('hidden');
            expect(style.display, selector).not.toBe('none');
        }
    });
});

test.describe('R3: full-page rendering に空白帯がない', () => {
    test('スクロールせずに hero → works → news → footer が描画済み', async ({
        page,
    }) => {
        await page.goto('/');
        // スクロール前の初期状態で below-the-fold を検証する
        // （reveal 待ちで空白になるアーキテクチャならここで落ちる）
        await expectContentVisible(page);

        // work card の画像は全て実体がある（broken <img src=""> なし — F-7 回帰）
        const cards = page.locator('.work-card');
        const count = await cards.count();
        expect(count).toBeGreaterThan(0);

        const emptySrc = await page
            .locator('.work-card img[src=""], .work-card img:not([src])')
            .count();
        expect(emptySrc).toBe(0);

        const naturalWidths = await page
            .locator('.work-card img')
            .evaluateAll((imgs) =>
                imgs.map((img) => (img as HTMLImageElement).naturalWidth),
            );

        for (const w of naturalWidths) {
            expect(w).toBeGreaterThan(0);
        }

        // footer が文書末尾まで描画されている（下部の巨大空白なし）
        const metrics = await page.evaluate(() => {
            const footer = document.querySelector('footer#site-footer')!;
            const rect = footer.getBoundingClientRect();

            return {
                footerBottom: rect.bottom + window.scrollY,
                docHeight: document.body.scrollHeight,
            };
        });
        expect(metrics.docHeight - metrics.footerBottom).toBeLessThan(120);

        // evidence: full-page screenshot（比較ではなく artifact として保存）
        await page.screenshot({
            path: 'tests/e2e/__screenshots__/robustness/full-page.png',
            fullPage: true,
        });
    });
});
