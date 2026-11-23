import { expect, test } from '@playwright/test';

test.describe('mobile navigation', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('Escape closes and returns keyboard focus', async ({ page }) => {
        await page.goto('/');
        const toggle = page.getByRole('button', { name: 'メニュー', exact: true });
        await toggle.click();
        await page.getByRole('link', { name: 'メニュー', exact: true }).focus();
        await page.keyboard.press('Escape');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(toggle).toBeFocused();
        await expect(page.getByRole('navigation', { name: 'グローバル' })).toBeHidden();
    });

    test('same-page selection closes without obstructing the destination', async ({ page }) => {
        await page.goto('/');
        const toggle = page.getByRole('button', { name: 'メニュー', exact: true });
        await toggle.click();
        await page.getByRole('link', { name: 'メニュー', exact: true }).click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    test('outside click and resizing clear expanded state', async ({ page }) => {
        await page.goto('/');
        const toggle = page.getByRole('button', { name: 'メニュー', exact: true });
        await toggle.click();
        await page.locator('footer').click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.setViewportSize({ width: 375, height: 812 });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    test('tabbing out closes without trapping or stealing focus', async ({ page }) => {
        await page.goto('/');
        const toggle = page.getByRole('button', { name: 'メニュー', exact: true });
        await toggle.click();
        await page.getByRole('link', { name: 'お問い合わせ', exact: true }).focus();
        await page.keyboard.press('Tab');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(toggle).not.toBeFocused();
    });

    test('mobile list retains touch targets without desktop gaps', async ({ page }) => {
        await page.goto('/');
        await page.getByRole('button', { name: 'メニュー', exact: true }).click();
        await expect(page.locator('#global-nav > ul')).toHaveCSS('row-gap', '0px');
        const box = await page.getByRole('link', { name: 'メニュー', exact: true }).boundingBox();
        expect(box?.height).toBeGreaterThanOrEqual(44);
    });
});

test('navigation stays reachable with JavaScript disabled', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 812 } });
    const page = await context.newPage();

    try {
        await page.goto(baseURL ?? 'http://127.0.0.1:8875');
        await expect(page.getByRole('button', { name: 'メニュー', exact: true })).toBeHidden();
        await expect(page.getByRole('link', { name: 'メニュー', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'お店のこと', exact: true })).toBeVisible();
    } finally {
        await context.close();
    }
});

test('long Japanese name fits at 320px and keeps the menu target', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.goto('/');
    await page.locator('.logo').evaluate((element) => {
 element.textContent = '地域のみなさまの暮らしを支えるこもれび整骨院'; 
});
    const toggle = page.getByRole('button', { name: 'メニュー', exact: true });
    await expect(toggle).toBeVisible();
    const box = await toggle.boundingBox();
    expect(box?.width).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('reduced motion removes the submenu arrow transition', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    const duration = await page.locator('.gnav__toggle').evaluate((el) => getComputedStyle(el, '::before').transitionDuration);
    expect(duration).toBe('0s');
});
