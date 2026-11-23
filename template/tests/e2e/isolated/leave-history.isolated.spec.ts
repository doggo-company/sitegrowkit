import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ storageState: process.env.FEEL_AUTH_STATE });

async function editor(page: Page): Promise<string> {
    await page.goto('/dashboard');
    await page
        .getByRole('link', { name: 'サイトのメニュー', exact: true })
        .first()
        .click();
    await page.waitForURL('**/admin/menus');
    await page.locator('main a[href*="/admin/menus/"]').first().click();
    await page.waitForURL(/\/admin\/menus\/[^/]+$/);

    return page.url();
}

function field(page: Page) {
    return page
        .locator('main input[type=text], main input:not([type])')
        .first();
}

async function cancel(page: Page, url: string): Promise<void> {
    const dialog = page.getByTestId('leave-confirm');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'この画面に残る' }).click();
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(url);
    await expect(field(page)).toHaveValue('履歴の確認中');
}

test('cancel Back repeatedly, then cancel and allow a different screen', async ({
    page,
}) => {
    const url = await editor(page);
    await field(page).fill('履歴の確認中');

    for (let i = 0; i < 3; i++) {
        await page.goBack();
        await cancel(page, url);
    }

    const forms = page
        .getByRole('link', { name: 'フォーム', exact: true })
        .first();
    await forms.click();
    await cancel(page, url);
    await forms.click();
    await page
        .getByTestId('leave-confirm')
        .getByRole('button', { name: '保存せずに移る' })
        .click();
    await expect(page).toHaveURL(/\/admin\/forms$/);
    await expect(page.getByTestId('leave-confirm')).toBeHidden();
});

test('cancel and allow Forward without losing the current form', async ({
    page,
}) => {
    const url = await editor(page);
    await page
        .getByRole('link', { name: 'フォーム', exact: true })
        .first()
        .click();
    await page.waitForURL('**/admin/forms');
    await page.goBack();
    await expect(page).toHaveURL(url);
    await expect(field(page)).toBeVisible();
    await field(page).fill('履歴の確認中');
    await page.goForward();
    await cancel(page, url);
    await page.goForward();
    await expect(page.getByTestId('leave-confirm')).toBeVisible();
    await page
        .getByTestId('leave-confirm')
        .getByRole('button', { name: '保存せずに移る' })
        .click();
    await expect(page).toHaveURL(/\/admin\/forms$/);
    await expect(page.getByTestId('leave-confirm')).toBeHidden();
});

test('cancel a multi-entry Back restores the exact editing entry', async ({
    page,
}) => {
    const url = await editor(page);
    await field(page).fill('履歴の確認中');
    await page.evaluate(() => history.go(-2));
    await cancel(page, url);
    await page
        .getByRole('link', { name: 'フォーム', exact: true })
        .first()
        .click();
    await cancel(page, url);
});

test('allow Back once, then Forward returns to the saved server form', async ({
    page,
}) => {
    const url = await editor(page);
    const original = await field(page).inputValue();
    await field(page).fill('履歴の確認中');
    await page.goBack();
    await expect(page.getByTestId('leave-confirm')).toBeVisible();
    await page
        .getByTestId('leave-confirm')
        .getByRole('button', { name: '保存せずに移る' })
        .click();
    await expect(page).toHaveURL(/\/admin\/menus$/);
    await expect(page.getByTestId('leave-confirm')).toBeHidden();
    await page.goForward();
    await expect(page).toHaveURL(url);
    await expect(field(page)).toHaveValue(original);
    await expect(page.getByTestId('leave-confirm')).toBeHidden();
});

test('a delayed history refresh cannot erase new typing or dismiss its leave prompt', async ({
    page,
}) => {
    const url = await editor(page);
    await page
        .getByRole('link', { name: 'フォーム', exact: true })
        .first()
        .click();
    await page.waitForURL('**/admin/forms');
    let release = () => {};
    let intercepted = () => {};
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    const requestSeen = new Promise<void>((resolve) => {
        intercepted = resolve;
    });
    await page.route('**/admin/menus/*', async (route) => {
        await page.evaluate(() => {
            document.addEventListener(
                'inertia:finish',
                () => {
                    document.documentElement.dataset.refreshFinished = 'true';
                },
                { once: true },
            );
        });
        intercepted();
        await gate;
        await route.continue();
    });
    await page.goBack();
    await requestSeen;
    await expect(field(page)).toBeVisible();
    // Focus as a user does; a second identical sidebar click within 500ms is intentionally guarded.
    await field(page).click();
    await field(page).fill('履歴の確認中');
    await page
        .getByRole('link', { name: 'フォーム', exact: true })
        .first()
        .click();
    await expect(page.getByTestId('leave-confirm')).toBeVisible();
    release();
    await expect(page.locator('html')).toHaveAttribute(
        'data-refresh-finished',
        'true',
    );
    await cancel(page, url);
});

test('non-delivery mail mode stays a blocker with an actionable local guide', async ({
    page,
}) => {
    await page.goto('/admin/readiness');
    const warning = page
        .getByTestId('readiness-blocker')
        .filter({ hasText: 'メールが実際には送られない設定' });
    await expect(warning).toBeVisible();
    await warning.getByTestId('readiness-fix').click();
    await expect(page).toHaveURL(/#mail-settings-guide$/);
    const guide = page.locator('#mail-settings-guide');
    await expect(guide).toBeInViewport();
    await expect(guide).toContainText('MAIL_MAILER');
    await expect(guide).toContainText('通知メールは届きません');
    await expect(warning).toContainText('要対応');
});
