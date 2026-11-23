import { expect, test } from '@playwright/test';

/*
 * UX 一周 15 周目の採点（round15-grade.md）で出たもののうち、画面で守れるもの。
 * 触り心地の門（playwright.feel.config.ts）から回る。docs/feel-bar.md。
 */

test.use({
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

test.describe('UX 一周 15 周目の採点', () => {
    test('G2: 通知先の欄が空のときに届く先を、フォームの画面とダッシュボードで同じに言う', async ({
        page,
    }) => {
        await page.goto('/admin/forms/contact');
        const notify = page.locator('#notify_to');
        test.skip(
            (await notify.inputValue()).trim() !== '',
            '通知先の欄が埋まっている（空のときの表示は見られない）',
        );
        const fallback = page.getByTestId('form-notify-fallback');
        await expect(
            fallback,
            '空なのに、どこへ届くか書いていない',
        ).toBeVisible();
        const address = (await fallback.locator('strong').innerText()).trim();
        expect(address).toContain('@');

        await page.goto('/dashboard');
        const body = await page.locator('main').innerText();

        if (body.includes('フォーム「お問い合わせ」の通知先')) {
            expect(
                body,
                'ダッシュボードとフォームの画面で、届く先が違う',
            ).toContain(address);
        }
    });

    test('G3: 公開前チェックの行には、どれにも直しに行ける所がある', async ({
        page,
    }) => {
        await page.goto('/admin/readiness');
        const rows = page.getByTestId('readiness-blocker');
        const count = await rows.count();

        for (let i = 0; i < count; i++) {
            const row = rows.nth(i);
            await expect(
                row.getByTestId('readiness-fix'),
                `押す所の無い行: ${(await row.innerText()).slice(0, 60)}`,
            ).toHaveCount(1);
        }
    });

    test('T4・T8: 項目の「上へ」でフォーカスを失わず、「項目を追加」で新しい欄へ連れて行く', async ({
        page,
    }) => {
        await page.goto('/admin/forms/contact');
        const up = page.getByTestId('field-group-move-field-up');
        await expect(up.nth(1)).toBeEnabled();
        await up.nth(1).click();
        const focused = await page.evaluate(() => {
            const el = document.activeElement;

            return el
                ? `${el.tagName}:${el.getAttribute('data-testid') ?? ''}`
                : 'null';
        });
        expect(
            focused,
            '「上へ」を押したあと、フォーカスがボタンから外れた',
        ).toMatch(/^BUTTON:field-group-move-field-(up|down)$/);

        await page.getByTestId('form-add-field').click();
        const labels = page.getByTestId('field-group-field-label');
        const added = labels.last();
        await expect(added, '足した欄にフォーカスが無い').toBeFocused();
        await expect(added, '足した欄が画面の外').toBeInViewport();
    });

    test('T7: 別の画面へ移ったら、前の画面の知らせは残らない', async ({
        page,
    }) => {
        const stamp = `${Date.now()}`.slice(-7);
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`知らせ ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`toast-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await expect(
            page.getByText('ページを作成しました').first(),
        ).toBeVisible();

        await page
            .getByRole('link', { name: 'ダッシュボード', exact: true })
            .first()
            .click();
        await page.waitForURL(/\/dashboard$/);
        await expect(
            page.getByText('ページを作成しました'),
            '前の画面の知らせが残っている',
        ).toHaveCount(0, { timeout: 1500 });
    });
});

test.describe('UX 一周 15 周目の採点（スマホ）', () => {
    test.use({
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
    });

    test('T6: ブロック編集の上の帯は、スマホで 3 段まで（キャンバスを下へ押し出さない）', async ({
        page,
    }) => {
        const stamp = `${Date.now()}`.slice(-7);
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`帯 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`bar-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL(/\/editor$/);
        await expect(page.getByTestId('editor-empty-start')).toBeVisible();

        // 上の帯の高さ = 画面の外枠の上端から、最初の欄（階層）の上端まで
        const toolbar = await page.evaluate(() => {
            const root = document.querySelector('.page-editor');
            const layers = document
                .querySelector('[data-testid=editor-empty]')
                ?.closest('section');

            return root && layers
                ? Math.round(
                      layers.getBoundingClientRect().top -
                          root.getBoundingClientRect().top,
                  )
                : -1;
        });
        expect(toolbar).toBeGreaterThan(0);
        // 題の行と操作の帯で 3 段（44px のボタン＋間）まで
        expect(
            toolbar,
            `上の帯が ${toolbar}px（3 段の目安 240px を超えた）`,
        ).toBeLessThanOrEqual(240);
    });
});
