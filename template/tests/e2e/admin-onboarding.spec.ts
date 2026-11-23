import { expect, test } from '@playwright/test';

/**
 * Phase 8.5-E — Dashboard の「はじめに」パネル（いまは「最初の 6 歩」）。
 *
 * これは tutorial engine ではなく、自動判定する 5 歩と、公開前チェックの 1 歩を案内する。
 * サーバ側の状態を持たない（dismiss は localStorage のみ）ことも検証する。
 */
test.describe('管理画面ダッシュボード — はじめに', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    const panel = '[data-testid="getting-started"]';
    const items = '[data-testid="getting-started-item"]';
    const dismiss = '[data-testid="getting-started-dismiss"]';

    test.beforeEach(async ({ page }) => {
        // 「まだ閉じていない利用者」の状態から始める
        await page.goto('/dashboard');
        await page.evaluate(() =>
            window.localStorage.removeItem(
                'agentcms.getting-started.dismissed',
            ),
        );
        await page.reload();
    });

    /**
     * 「はじめに」（4 項目）は「最初の 6 歩」に替わった（`22755aff` ログインした直後に出す）。
     * 試験の文言だけが古いまま残っていた（2026-11-05 にキット本体の環境で流して見つけた）。
     * 項目の名前と順は `App\Support\FirstSteps` が正本。
     */
    test('初期状態で表示され、項目はちょうど 6 つ', async ({ page }) => {
        await expect(page.locator(panel)).toBeVisible();
        await expect(page.locator(`${panel} h3`)).toHaveText('最初の 6 歩');
        await expect(page.locator(items)).toHaveCount(6);

        const titles = [
            'サイトの名前を決める',
            '最初のページか記事を出す',
            'トップページを自分の言葉にする',
            'メニューをこのサイトに合わせる',
            '問い合わせが自分に届くようにする',
            '公開の前にひととおり見る',
        ];

        for (const [index, title] of titles.entries()) {
            await expect(page.locator(items).nth(index)).toContainText(title);
        }
    });

    test('Primary UI にターミナルのコマンドを出さない', async ({ page }) => {
        const text = (await page.locator(panel).textContent()) ?? '';

        for (const forbidden of [
            'agentcms',
            'artisan',
            'composer',
            'pnpm',
            'docker',
            'npm ',
            '$ ',
        ]) {
            expect(text.toLowerCase()).not.toContain(forbidden.toLowerCase());
        }
    });

    test('サイト設定リンクが実在する画面へ遷移する', async ({ page }) => {
        await page.locator(items).nth(0).getByRole('link').click();
        await page.waitForURL('**/admin/site');
        await expect(page.locator('h1')).toBeVisible();
    });

    test('コンテンツリンクが実在する一覧へ遷移する（提供される場合）', async ({
        page,
    }) => {
        const link = page.locator(items).nth(1).getByRole('link');

        if ((await link.count()) === 0) {
            test.skip(true, 'collection を持たない構成では link を出さない');
        }

        const href = await link.getAttribute('href');
        expect(href).toBeTruthy();
        await link.click();
        await page.waitForURL(`**${href}`);
        await expect(page.locator('h1')).toBeVisible();
    });

    test('閉じると非表示になり、再読み込みしても表示されない', async ({
        page,
    }) => {
        await expect(page.locator(panel)).toBeVisible();
        await page.locator(dismiss).click();
        await expect(page.locator(panel)).toHaveCount(0);

        await page.reload();
        await expect(page.locator(panel)).toHaveCount(0);

        // 別画面を経由して戻っても表示されない
        await page.goto('/admin/site');
        await page.goto('/dashboard');
        await expect(page.locator(panel)).toHaveCount(0);
    });

    test('dismiss はサーバへ書き込まない（localStorage のみ）', async ({
        page,
    }) => {
        const mutations: string[] = [];
        page.on('request', (request) => {
            if (request.method() !== 'GET') {
                mutations.push(`${request.method()} ${request.url()}`);
            }
        });

        await page.locator(dismiss).click();
        await expect(page.locator(panel)).toHaveCount(0);
        await page.waitForTimeout(500);

        expect(mutations).toEqual([]);
        const stored = await page.evaluate(() =>
            window.localStorage.getItem('agentcms.getting-started.dismissed'),
        );
        expect(stored).toBe('1');
    });

    test('既存ダッシュボードの情報を壊さない', async ({ page }) => {
        await expect(page.locator(panel)).toBeVisible();
        await expect(
            page.getByRole('heading', { name: '最近のニュース' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'メディア' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'お問い合わせ' }),
        ).toBeVisible();
        // 既存の管理リンク（パネル内の導線や sidebar と混同しないよう厳密に）
        await expect(
            page
                .getByRole('main')
                .getByRole('link', { name: 'サイト設定', exact: true }),
        ).toBeVisible();
    });

    test('console error なしで表示される', async ({ page }) => {
        const errors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
errors.push(message.text());
}
        });
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto('/dashboard');
        await expect(page.locator(panel)).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('キーボードで操作でき、閉じるボタンに accessible name がある', async ({
        page,
    }) => {
        const dismissButton = page.getByRole('button', {
            name: 'はじめにパネルを今後表示しない',
        });
        await expect(dismissButton).toBeVisible();

        // リンクへ Tab で到達でき、focus が当たる
        const siteLink = page.locator(items).nth(0).getByRole('link');
        await siteLink.focus();
        await expect(siteLink).toBeFocused();

        // 閉じるボタンも focus → Enter で閉じられる
        await dismissButton.focus();
        await expect(dismissButton).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator(panel)).toHaveCount(0);
    });

    test('モバイル幅で横スクロールや文字切れが起きない', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');
        await expect(page.locator(panel)).toBeVisible();

        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        );
        expect(overflow).toBe(false);

        const clipped = await page.evaluate((selector) => {
            const root = document.querySelector(selector);

            if (!root) {
return true;
}

            return [...root.querySelectorAll('p')].some(
                (el) => el.scrollWidth > el.clientWidth + 1,
            );
        }, panel);
        expect(clipped).toBe(false);
    });
});
