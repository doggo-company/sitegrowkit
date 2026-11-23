import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ storageState: process.env.FEEL_AUTH_STATE });

async function open(page: Page): Promise<string> {
    await page.goto('/dashboard');
    await page.getByRole('button', { name: '見本の下書きを開く' }).click();
    await page.waitForURL('**/editor');

    return `/admin/pages/${page.url().match(/pages\/(\d+)/)![1]}/preview?canvas=1`;
}

for (const viewport of [
    { width: 1440, height: 900 },
    { width: 390, height: 844 },
]) {
    test(`A composition and keyboard menu at ${viewport.width}`, async ({
        page,
    }) => {
        await page.setViewportSize(viewport);
        const preview = await open(page);
        await page.goto(preview);
        await expect(page.locator('body')).toHaveClass('site-tpl site-tpl-a');
        await expect(page.locator('main h1')).toHaveText(
            '食パンの朝。クロワッサンの午後。',
        );
        await expect
            .poll(() =>
                page
                    .locator('.doc-cover img')
                    .first()
                    .evaluate((img: HTMLImageElement) => img.naturalWidth),
            )
            .toBeGreaterThan(0);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth),
        ).toBeLessThanOrEqual(viewport.width);

        if (viewport.width === 390) {
            const toggle = page.getByRole('button', {
                name: 'メニュー',
                exact: true,
            });
            await toggle.focus();
            await page.keyboard.press('Enter');
            await expect(toggle).toHaveAttribute('aria-expanded', 'true');
            await page.keyboard.press('Escape');
            await expect(toggle).toHaveAttribute('aria-expanded', 'false');
            await expect(toggle).toBeFocused();
        }

        await page.emulateMedia({ reducedMotion: 'reduce' });
        expect(
            await page
                .locator('.doc-cover')
                .first()
                .evaluate((el) => getComputedStyle(el).transitionDuration),
        ).toBe('0s');
    });
}

test('long Japanese copy saves through the editor and survives reopening', async ({
    page,
}) => {
    const preview = await open(page);
    await page
        .getByRole('region', { name: 'ブロックの階層' })
        .getByRole('button', { name: '大きな写真と見出し', exact: true })
        .first()
        .click();
    const title = page.getByRole('textbox', { name: '見出し', exact: true });
    const original = await title.inputValue();
    const long =
        '毎日の食卓に寄り添い、地域のみなさまと一緒に季節のおいしさを楽しむパンのある暮らし。'.repeat(
            3,
        );
    const savedLong = page.waitForResponse(
        (r) =>
            r.request().method() === 'PATCH' &&
            r.url().endsWith('/document') &&
            r.ok(),
    );
    await title.fill(long);
    await title.blur();
    await savedLong;
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
    await page.goto(preview);
    await expect(page.locator('main h1')).toHaveText(long);

    for (const width of [1440, 390]) {
        await page.setViewportSize({
            width,
            height: width === 390 ? 844 : 900,
        });
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth),
        ).toBeLessThanOrEqual(width);
        const heading = await page.locator('main h1').boundingBox();
        const header = await page.locator('header').boundingBox();
        expect(heading!.y).toBeGreaterThan(header!.height);
        await page.screenshot({
            path: test.info().outputPath(`long-${width}.png`),
        });
    }

    await open(page);
    await page
        .getByRole('region', { name: 'ブロックの階層' })
        .getByRole('button', { name: '大きな写真と見出し', exact: true })
        .first()
        .click();
    await expect(title).toHaveValue(long);
    const savedOriginal = page.waitForResponse(
        (r) =>
            r.request().method() === 'PATCH' &&
            r.url().endsWith('/document') &&
            r.ok(),
    );
    await title.fill(original);
    await title.blur();
    await savedOriginal;
    await expect(page.getByTestId('editor-bottom-save-state')).toBeVisible();
});

test('missing replacement photos retain readable text and usable navigation', async ({
    page,
}) => {
    const preview = await open(page);
    await page.route('**/storage/**', (route) => route.abort());
    await page.goto(preview);
    await expect(page.locator('.doc-cover').first()).toHaveClass(
        /doc-cover--no-photo/,
    );
    await expect(page.locator('main h1')).toBeVisible();
    await expect(page.locator('.doc-cover__photo .starter-image-fallback')).toBeVisible();
    await expect(page.locator('.doc-cover__photo img')).toBeHidden();
    await page.locator('.doc-image').first().scrollIntoViewIfNeeded();
    await expect(page.locator('.starter-image-fallback').first()).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => scrollTo(0, 0));
    await page.getByRole('button', { name: 'メニュー', exact: true }).click();
    await expect(
        page.getByRole('navigation', { name: 'グローバル' }),
    ).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
    ).toBeLessThanOrEqual(390);
});

test('photograph can be replaced in the existing media picker and saved', async ({
    page,
}) => {
    const preview = await open(page);
    await page
        .getByRole('region', { name: 'ブロックの階層' })
        .getByRole('button', { name: '大きな写真と見出し', exact: true })
        .first()
        .click();
    const frame = page.frameLocator('iframe');
    const before = await frame
        .locator('.doc-cover img')
        .first()
        .getAttribute('src');
    await page.getByTestId('media-picker-open').click();
    const dialog = page.getByRole('dialog');
    const cards = dialog
        .getByRole('button')
        .filter({ has: page.locator('img') });
    await expect(cards.first()).toBeVisible();
    const originalChoice = await dialog
        .locator('button.ring-ring')
        .getAttribute('data-testid');
    const savedPhoto = page.waitForResponse(
        (r) =>
            r.request().method() === 'PATCH' &&
            r.url().endsWith('/document') &&
            r.ok(),
    );
    await dialog
        .locator('button[data-testid^="media-choice-"]:not(.ring-ring)')
        .first()
        .click();
    await savedPhoto;
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
    await page.goto(preview);
    await expect(page.locator('.doc-cover img').first()).toBeVisible();
    expect(
        await page.locator('.doc-cover img').first().getAttribute('src'),
    ).not.toBe(before);
    await page.screenshot({
        path: test.info().outputPath('replaced-photo.png'),
    });
    await open(page);
    await page
        .getByRole('region', { name: 'ブロックの階層' })
        .getByRole('button', { name: '大きな写真と見出し', exact: true })
        .first()
        .click();
    await page.getByTestId('media-picker-open').click();
    const restored = page.waitForResponse(
        (r) =>
            r.request().method() === 'PATCH' &&
            r.url().endsWith('/document') &&
            r.ok(),
    );
    await page.getByTestId(originalChoice!).click();
    await restored;
});

test('a bright replacement keeps the white hero copy readable', async ({ page }) => {
    const preview = await open(page);
    await page.getByRole('region', { name: 'ブロックの階層' }).getByRole('button', { name: '大きな写真と見出し', exact: true }).first().click();
    const legacySaved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/document') && r.ok());
    await page.getByRole('combobox', { name: '見せ方', exact: true }).selectOption('full');
    await legacySaved;
    await page.route('**/storage/**', (route) => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1066"><path fill="white" d="M0 0h1600v1066H0z"/></svg>',
    }));
    await page.goto(preview);
    const cover = page.locator('.doc-cover').first();
    const gradient = await cover.evaluate((el) => getComputedStyle(el, '::after').backgroundImage);
    const alpha = [...gradient.matchAll(/rgba\(0, 0, 0, ([\d.]+)\)/g)].map((match) => Number(match[1]));
    expect(alpha.length).toBeGreaterThan(0);
    const channel = 1 - Math.min(...alpha);
    const luminance = channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    expect(1.05 / (luminance + 0.05)).toBeGreaterThanOrEqual(4.5);
    await expect(page.locator('main h1')).toHaveCSS('color', 'rgb(255, 255, 255)');
    await page.screenshot({ path: test.info().outputPath('bright-legacy.png') });
    await open(page);
    await page.getByRole('region', { name: 'ブロックの階層' }).getByRole('button', { name: '大きな写真と見出し', exact: true }).first().click();
    const restored = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith('/document') && r.ok());
    await page.getByRole('combobox', { name: '見せ方', exact: true }).selectOption('editorial');
    await restored;
});
