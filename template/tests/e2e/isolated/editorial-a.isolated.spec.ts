import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ storageState: process.env.FEEL_AUTH_STATE });

async function open(page: Page): Promise<string> {
    await page.goto('/dashboard');
    await page.getByRole('button', { name: '見本の下書きを開く' }).click();
    await page.waitForURL('**/editor');

    return `/admin/pages/${page.url().match(/pages\/(\d+)/)![1]}/preview?canvas=1`;
}
async function selectCover(page: Page): Promise<void> {
    await page.getByRole('region', { name: 'ブロックの階層' }).getByRole('button', { name: '大きな写真と見出し', exact: true }).first().click();
}
async function save(page: Page, name: string, value: string): Promise<void> {
    const input = page.getByRole('textbox', { name, exact: true });

    if (await input.inputValue() === value) {
return;
}

    const response = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/document') && r.ok());
    await input.fill(value);
    await input.blur();
    await response;
}
async function noOverflow(page: Page, width: number): Promise<void> {
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
}

for (const width of [1440, 390]) {
    test(`shop identity and useful actions appear in the first fold at ${width}`, async ({ page }) => {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
        await page.goto(await open(page));
        await expect(page.locator('.doc-cover__eyebrow')).toHaveText('パン屋 麦と日々');
        await expect(page.locator('.doc-cover__eyebrow')).toBeInViewport();
        await expect(page.getByRole('link', { name: 'パンを見る', exact: true })).toBeInViewport();
        await expect(page.locator('.doc-cover').getByRole('link', { name: 'お問い合わせ', exact: true })).toBeInViewport();
        await noOverflow(page, width);
    });
}

test('keyboard product action focuses a stable editable section', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(await open(page));
    const products = page.getByRole('link', { name: 'パンを見る', exact: true });
    await products.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#bread-menu$/);
    await expect(page.locator('#bread-menu')).toBeFocused();
    await expect(page.locator('#bread-menu h2')).toBeInViewport();
    const heading = await page.locator('#bread-menu h2').boundingBox();
    const header = await page.locator('header').boundingBox();
    expect(heading!.y).toBeGreaterThanOrEqual(header!.y + header!.height + 16);
    await expect(page.locator('#bread-menu .doc-menutable tr')).toHaveCount(4);
    await page.screenshot({ path: test.info().outputPath('product-anchor.png') });
});

for (const [name, width, height, fill] of [
    ['portrait', 300, 1200, '#b8864a'],
    ['panorama', 2400, 300, '#cbb288'],
    ['bright', 1600, 1066, '#ffffff'],
] as const) {
    test(`a ${name} replacement does not overlap or recolour the copy`, async ({ page }) => {
        const preview = await open(page);
        await page.route('**/storage/**', (route) => route.fulfill({ contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><path fill="${fill}" d="M0 0h${width}v${height}H0z"/></svg>` }));
        await page.goto(preview);

        for (const screen of [1440, 390]) {
            await page.setViewportSize({ width: screen, height: screen === 390 ? 844 : 900 });
            const copy = await page.locator('.doc-cover__copy').boundingBox();
            const photo = await page.locator('.doc-cover__photo').boundingBox();

            if (screen === 1440) {
expect(copy!.x + copy!.width).toBeLessThanOrEqual(photo!.x);
} else {
expect(copy!.y + copy!.height).toBeLessThanOrEqual(photo!.y);
}

            await noOverflow(page, screen);
            await expect(page.locator('main h1')).toHaveCSS('color', 'rgb(41, 37, 32)');
            expect(await page.locator('.doc-cover__photo figcaption').evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(14);
            await page.screenshot({ path: test.info().outputPath(`${name}-${screen}.png`) });
        }
    });
}

test('short copy and omitted optional text save without leaving decorative holes', async ({ page }) => {
    const preview = await open(page);
    await selectCover(page);
    const names = ['見出し', '見出しの上の小さな文字（なくても可）', '短い説明（なくても可）', '写真の説明（なくても可）'];
    const before = await Promise.all(names.map((name) => page.getByRole('textbox', { name, exact: true }).inputValue()));

    try {
        for (let index = 0; index < names.length; index++) {
await save(page, names[index], index === 0 ? 'パン。' : '');
}

        await page.goto(preview);
        await expect(page.locator('main h1')).toHaveText('パン。');
        await expect(page.locator('.doc-cover__eyebrow, .doc-cover__lead, .doc-cover__photo figcaption')).toHaveCount(0);
        await page.setViewportSize({ width: 390, height: 844 });
        await noOverflow(page, 390);
        await expect(page.getByRole('link', { name: 'パンを見る', exact: true })).toBeInViewport();
        await page.screenshot({ path: test.info().outputPath('short-copy.png') });
    } finally {
        await open(page); await selectCover(page);

        for (let index = 0; index < names.length; index++) {
await save(page, names[index], before[index]);
}
    }
});

test('200 percent text stays within the viewport and reaches products', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(await open(page));
    await page.evaluate(() => {
 document.documentElement.style.fontSize = '200%'; 
});
    await noOverflow(page, 390);
    await page.getByRole('link', { name: 'パンを見る', exact: true }).click();
    await expect(page.locator('#bread-menu')).toBeFocused();
    const heading = await page.locator('#bread-menu h2').boundingBox();
    const header = await page.locator('header').boundingBox();
    expect(heading!.y).toBeGreaterThanOrEqual(header!.y + header!.height + 16);
    await noOverflow(page, 390);
    await page.screenshot({ path: test.info().outputPath('text-200.png') });
});

test('removing the photograph through the CMS keeps identity and actions', async ({ page }) => {
    const preview = await open(page); await selectCover(page);
    await page.getByTestId('media-picker-open').click();
    const original = await page.getByRole('dialog').locator('button.ring-ring').getAttribute('data-testid');
    await page.keyboard.press('Escape');
    const cleared = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/document') && r.ok());
    await page.getByTestId('media-picker-clear').click(); await cleared;

    try {
        await page.goto(preview);
        await expect(page.locator('.doc-cover__photo')).toHaveCount(0);
        await expect(page.locator('main h1')).toBeVisible();
        await page.setViewportSize({ width: 390, height: 844 });
        await noOverflow(page, 390);
        await expect(page.getByRole('link', { name: 'パンを見る', exact: true })).toBeInViewport();
        await page.screenshot({ path: test.info().outputPath('no-photo.png') });
    } finally {
        await open(page); await selectCover(page);
        await page.getByTestId('media-picker-open').click();
        const restored = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/document') && r.ok());
        await page.getByTestId(original!).click(); await restored;
    }
});
