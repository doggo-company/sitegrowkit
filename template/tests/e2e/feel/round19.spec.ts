import { expect, test } from '@playwright/test';

/*
 * UX 一周 19 周目の採点（round19-grade.md）: 読みはサーバー（PHP・IPADIC）で出す。
 * ブラウザに辞書を送らない・仮の URL を作らない。4G 相当でも、打ったら候補がすぐ出て、作成は待たない。
 * 使われている URL は、候補に出す前に -2 を付けて見せる（U3）。触り心地の門（playwright.feel.config.ts）から回る。
 */

test.use({
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

/** Chrome の 4G 相当（下り 9 Mbps・上り 1.5 Mbps・往復 60ms） */
const FOUR_G = {
    offline: false,
    latency: 60,
    downloadThroughput: 9_000_000 / 8,
    uploadThroughput: 1_500_000 / 8,
};

test('4G 相当・空のキャッシュでも、候補は 1 秒以内・作成は 1 秒以内。URL は読み（仮ではない）', async ({
    page,
    context,
}) => {
    const heavy: string[] = [];
    page.on('request', (r) => {
        if (/reading-dict|kuromoji/.test(r.url())) {
            heavy.push(r.url());
        }
    });
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });

    await page.goto('/admin/pages/create');
    await cdp.send('Network.emulateNetworkConditions', FOUR_G);

    const typed = Date.now();
    await page.getByTestId('page-title-input').fill('大宮佐藤歯科医院');
    await expect(page.getByTestId('page-slug-draft')).toContainText(
        '/oomiya-satou-shika-iin',
        {
            timeout: 5_000,
        },
    );
    const shownIn = Date.now() - typed;
    expect(
        shownIn,
        `打ってから候補まで ${shownIn}ms（1 秒以内）`,
    ).toBeLessThanOrEqual(1000);

    const pressed = Date.now();
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    const createdIn = Date.now() - pressed;
    expect(
        createdIn,
        `「作成する」から次の画面まで ${createdIn}ms（1 秒以内）`,
    ).toBeLessThanOrEqual(1000);

    await expect(page.getByTestId('page-slug-input')).toHaveValue(
        /^oomiya-satou-shika-iin(-\d+)?$/,
    );
    expect(heavy, 'ブラウザに辞書を送っている').toEqual([]);
});

test('U3: 使われている URL は、候補に出す前に -2 を付けて見せ、その URL で作る', async ({
    page,
}) => {
    // 1 枚目（置き換えは題名がその語だけのとき・20 周目 R1）
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill('料金表');
    const draft = page.getByTestId('page-slug-draft');
    await expect(draft).toContainText(/\/price(-\d+)?/);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    const first = await page.getByTestId('page-slug-input').inputValue();

    // 2 枚目: 候補は 1 枚目と違う URL（番号付き）で、作った URL と同じ
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill('料金表');
    await expect(draft).toContainText(/\/price-\d+/);
    const shown = ((await draft.locator('strong').innerText()) ?? '').replace(
        /^\//,
        '',
    );
    expect(shown).not.toBe(first);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await expect(page.getByTestId('page-slug-input')).toHaveValue(shown);
});
