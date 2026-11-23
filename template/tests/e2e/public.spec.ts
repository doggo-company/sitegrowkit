import { expect, test } from '@playwright/test';

const PAGES = [
    { path: '/', name: 'home' },
    { path: '/about', name: 'about' },
    { path: '/service', name: 'service' },
    { path: '/works', name: 'works' },
    { path: '/news', name: 'news' },
    { path: '/contact', name: 'contact' },
];

test.describe('公開サイト', () => {
    for (const page of PAGES) {
        test(`${page.name} が表示され console error がない`, async ({
            page: p,
        }, testInfo) => {
            const errors: string[] = [];
            p.on('console', (msg) => {
                if (msg.type() === 'error') {
errors.push(msg.text());
}
            });

            const response = await p.goto(page.path);
            expect(response?.status()).toBe(200);
            await expect(p.locator('main')).toBeVisible();

            // Visual Regression の基礎: Desktop / Mobile スクリーンショット取得
            await p.screenshot({
                path: `tests/e2e/__screenshots__/${testInfo.project.name}/${page.name}.png`,
                fullPage: true,
            });

            expect(errors, `console errors on ${page.path}`).toStrictEqual([]);
        });
    }

    test('broken-link check — サイト内リンクがすべて 200', async ({
        page,
        request,
    }) => {
        const seen = new Set<string>();

        for (const { path } of PAGES) {
            await page.goto(path);
            const hrefs = await page.$$eval('a[href]', (as) =>
                as.map((a) => (a as HTMLAnchorElement).href),
            );

            for (const href of hrefs) {
                const url = new URL(href);

                if (url.origin !== new URL(page.url()).origin) {
continue;
}

                if (
                    url.pathname.startsWith('/admin') ||
                    url.pathname.startsWith('/login')
                ) {
continue;
}

                if (seen.has(url.pathname)) {
continue;
}

                seen.add(url.pathname);

                const res = await request.get(url.pathname);
                expect(res.status(), `broken link: ${url.pathname}`).toBe(200);
            }
        }

        expect(seen.size).toBeGreaterThan(5);
    });

    test('sitemap.xml のURLがすべて 200', async ({ request }) => {
        const sitemap = await request.get('/sitemap.xml');
        expect(sitemap.status()).toBe(200);
        const body = await sitemap.text();
        const locs = [...body.matchAll(/<loc>([^<]+)<\/loc>/g)].map(
            (m) => new URL(m[1]).pathname,
        );
        expect(locs.length).toBeGreaterThan(5);

        for (const path of locs) {
            const res = await request.get(path);
            expect(res.status(), `sitemap url: ${path}`).toBe(200);
        }
    });

    test('CSRF — トークン無しの POST /contact は 419', async ({ request }) => {
        const res = await request.post('/contact', {
            form: {
                name: 'x',
                email: 'x@example.com',
                subject: 'other',
                message: 'x',
            },
        });
        expect(res.status()).toBe(419);
    });
});
