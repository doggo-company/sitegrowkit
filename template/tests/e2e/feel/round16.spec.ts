import { expect, test } from '@playwright/test';

/*
 * UX 一周 16 周目の採点（round16-grade.md）で出たもののうち、画面で守れるもの。
 * 触り心地の門（playwright.feel.config.ts）から回る。docs/feel-bar.md。
 */

test.use({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

test('N1: スマホの下の帯の保存の表示は、切れずに全部読める（2 行まで）', async ({
    page,
}) => {
    const stamp = `${Date.now()}`.slice(-7);
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`保存表示 ${stamp}`);
    await page.getByTestId('page-slug-input').fill(`save-${stamp}`);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await page.getByTestId('page-editor-link').click();
    await page.waitForURL(/\/editor$/);
    await page.getByTestId('editor-empty-start').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );

    // 公開してから直す → いちばん長い「保存済み（公開ページにはまだ出ていません）」
    await page.getByTestId('editor-publish-button-bottom').tap();
    await expect(page.getByText('ページを公開しました').first()).toBeVisible();
    await page.locator('#prop-text').click();
    await page.keyboard.type('直した');
    await page.locator('h1').first().click();

    const state = page.getByTestId('editor-bottom-save-state');
    await expect(state).toHaveText(
        '保存済み（公開ページにはまだ出ていません）',
        {
            timeout: 10_000,
        },
    );

    const box = await state.evaluate((el) => {
        const cs = getComputedStyle(el);
        const line = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;

        return {
            clipped:
                el.scrollWidth > el.clientWidth + 1 ||
                el.scrollHeight > el.clientHeight + 1,
            ellipsis:
                cs.textOverflow === 'ellipsis' && cs.overflow !== 'visible',
            lines: Math.round(el.getBoundingClientRect().height / line),
        };
    });
    expect(box.clipped || box.ellipsis, '保存の表示が切れている').toBe(false);
    expect(
        box.lines,
        `保存の表示が ${box.lines} 行（2 行まで）`,
    ).toBeLessThanOrEqual(2);
});

/*
 * 直し 4 回目: ブロックを置くと最初の欄へ焦点が移る（37fe80a5・そのまま打てるように）。
 * 焦点だけで帯を隠していたので、スマホ幅で置いた直後に「公開する」も保存の表示も消えていた（N1 が赤）。
 * 置いた直後は帯が見えていて押せる。打ち始めたら隠れ（15 周目 T1）、欄を離れたら戻る。
 */
test('N1-2: 置いた直後も下の「公開する」が見えていて、打ち始めたら隠れる', async ({
    page,
}) => {
    const stamp = `${Date.now()}`.slice(-7);
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`置いた直後 ${stamp}`);
    await page.getByTestId('page-slug-input').fill(`placed-${stamp}`);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await page.getByTestId('page-editor-link').click();
    await page.waitForURL(/\/editor$/);
    await page.getByTestId('editor-empty-start').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );

    // 焦点は最初の欄に入っている（そのまま打てる約束は保つ）
    await expect
        .poll(() => page.evaluate(() => document.activeElement?.id ?? ''))
        .toMatch(/^prop-/);
    // それでも帯は出ていて、「公開する」が画面の中にある
    const publish = page.getByTestId('editor-publish-button-bottom');
    await expect(publish).toBeInViewport();
    await expect(publish).toBeEnabled();
    // 焦点の入った欄は、帯の裏に隠れず見えている（打てる場所が見える）
    expect(
        await page.evaluate(() => {
            const field = document.activeElement as HTMLElement | null;
            const bar = document.querySelector(
                '[data-testid=editor-bottom-bar]',
            );

            if (!field || !bar) {
                return false;
            }

            const f = field.getBoundingClientRect();

            return f.top >= 0 && f.bottom <= bar.getBoundingClientRect().top;
        }),
        '焦点の入った欄が下の帯の裏に隠れている',
    ).toBe(true);

    // 打ち始めたら帯は隠れる（入力欄を隠さない）
    await page.keyboard.type('季節の花束');
    await expect(page.getByTestId('editor-bottom-bar')).toHaveCount(0);
    await expect
        .poll(() => page.evaluate(() => document.activeElement?.id ?? ''))
        .toMatch(/^prop-/);

    // 欄を離れたら戻る
    await page.locator('h1').first().click();
    await expect(publish).toBeInViewport();
});
