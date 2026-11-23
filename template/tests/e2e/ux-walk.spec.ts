import { expect, test } from '@playwright/test';

/**
 * UX 一周（WP から来た人の目・2026-09-28）で止まった所の恒久回帰。
 *
 * 画面に出ているかを見る（DB が正しくても、画面に出ていなければ利用者には無いのと同じ）。
 * 自分で作ったページだけを操作するので、他の spec の実行順に依存しない。
 */
const stamp = Date.now();

test.describe('UX 一周の回帰', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('空のページの Canvas に、何をすればよいかが出る', async ({ page }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`空 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-empty-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        await page.goto(page.url().replace(/\/edit$/, '/editor'));

        const empty = page.getByTestId('canvas-empty');
        await expect(empty).toBeVisible();
        await expect(empty).toContainText('このページはまだ空です');

        // Canvas の入口から見出しを置くと、案内は消える。最初の見出しは「要修正」にならない
        await page.getByTestId('canvas-empty-start').click();
        await expect(empty).toBeHidden();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await expect(page.getByTestId('a11y-panel')).toHaveCount(0);
    });

    test('サイト名を空で保存すると、ボタンの横で失敗を言い、直す欄へ移る', async ({
        page,
    }) => {
        await page.goto('/admin/site');
        const name = page.locator('#site_name');
        const original = await name.inputValue();

        await name.fill('');
        await page.getByRole('button', { name: '保存' }).click();

        await expect(page.getByTestId('save-error-summary')).toContainText(
            '保存できませんでした',
        );
        await expect(name).toBeFocused();
        await expect(name).toBeInViewport();

        await name.fill(original);
        await page.getByRole('button', { name: '保存' }).click();
        await expect(page.getByTestId('site-saved')).toBeVisible();
    });

    test('URL を空のまま日本語の題で作ると、意味の分かる URL が付く', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill('料金のご案内');
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);

        await expect(page.locator('main')).toContainText('price');
    });
});

test.describe('UX 一周 第 1 周の回帰', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('編集画面の選択肢と画面幅が日本語で、本文は見たまま編集できる', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`第1周 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-r1-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        await page.goto(page.url().replace(/\/edit$/, '/editor'));

        await expect(page.getByTestId('breakpoint-mobile')).toHaveText(
            'スマホ',
        );

        await page.getByTestId('canvas-empty-start').click();
        await expect(page.locator('#style-textAlign option')).toContainText([
            '指定しない',
            '左寄せ',
            '中央',
            '右寄せ',
        ]);

        await page.getByTestId('palette-richtext').click();
        const inspector = page.locator("[aria-label='ブロックの追加と操作']");
        await expect(inspector.locator('[contenteditable=true]')).toHaveCount(
            1,
        );

        // 画像: 置いた直後は「未設定」。選ぶ画面からそのまま上げられ、説明が代替テキストに入る
        await page.getByTestId('palette-image').click();
        await expect(
            page.getByTestId('a11y-finding-image-missing'),
        ).toContainText('未設定');
        await inspector.getByRole('button', { name: '画像を選択' }).click();
        await page.getByTestId('media-picker-upload-alt').fill('お店の入口');
        await page.getByTestId('media-picker-upload-file').setInputFiles({
            name: 'shop.png',
            mimeType: 'image/png',
            // 1x1 の PNG
            buffer: Buffer.from(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==',
                'base64',
            ),
        });
        await expect(page.locator('#prop-alt')).toHaveValue('お店の入口');
        await expect(page.getByTestId('a11y-panel')).toHaveCount(0);
        expect(
            await inspector.evaluate((e) => e.scrollWidth - e.clientWidth),
        ).toBeLessThanOrEqual(0);

        // ボタン: リンク先は候補から選べる
        await page.getByTestId('palette-button').click();
        await expect(
            page.locator('datalist option[value="/contact"]'),
        ).toHaveCount(1);
    });

    test('公開前チェックは直すものを先に出し、サーバーの記録は畳む', async ({
        page,
    }) => {
        await page.goto('/admin/readiness');
        await expect(
            page.getByTestId('server-admin-reports'),
        ).not.toHaveAttribute('open', '');
        await expect(page.getByText('署名と構造契約').first()).toBeHidden();
    });

    test('メニューに足すと、まだ無い公開中のページから始まる', async ({
        page,
    }) => {
        // 前提: メニューにまだ無い、公開中のページを 1 つ作る
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`メニュー用 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-menu-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();
        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await page.goto(edit);
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-status-badge')).toContainText(
            '公開中',
        );

        await page.goto('/admin/menus/header');
        const selects = page.locator('main select');
        const before = await selects.count();
        await page.getByRole('button', { name: '項目を追加' }).click();
        // 足した項目の最初の選択（種類）。公開中のページがあれば「ページ」で始まる
        const type = selects.nth(before);
        await expect(type.locator('option:checked')).toHaveText('ページ');
    });
});

test.describe('UX 一周 第 2 周の回帰', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('投稿を公開サイトに出していないとき、記事の画面から1クリックで出せる', async ({
        page,
    }) => {
        // 前提: 公開サイトに出さない設定にする
        await page.goto('/admin/content-types/post/edit');
        await page.getByTestId('content-type-registration-public').uncheck();
        await page.getByRole('button', { name: '保存する' }).click();
        await expect(
            page.getByText('投稿タイプの設定を保存しました'),
        ).toBeVisible();

        await page.goto('/admin/post/create');
        await page.getByTestId('public-type-quick-start').click();
        await expect(page).toHaveURL(/\/admin\/post\/create$/);
        await expect(page.getByTestId('public-type-notice')).toHaveCount(0);
    });

    test('「公開サイトに出す」を入れると、空の公開スラッグに見えていた値が入る', async ({
        page,
    }) => {
        await page.goto('/admin/content-types/post/edit');
        await page.getByTestId('content-type-registration-public').uncheck();
        await page.locator('#public-slug').fill('');
        await page.getByTestId('content-type-registration-public').check();
        await expect(page.locator('#public-slug')).toHaveValue('blog');
    });

    test('スマホ幅の編集画面で、画面幅の切り替えが画面の外へはみ出さない', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`スマホ ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-r2-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        await page.goto(page.url().replace(/\/edit$/, '/editor'));
        const outside = await page
            .locator('[aria-label=画面幅] > *')
            .evaluateAll(
                (els) =>
                    els.filter(
                        (e) =>
                            e.getBoundingClientRect().right > window.innerWidth,
                    ).length,
            );
        expect(outside).toBe(0);
    });
});

test.describe('UX 一周 第 4 周の回帰', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('複製しても階層に英語の識別名が並ばず、選択肢は日本語で出る', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`第4周 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-r4-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        await page.goto(page.url().replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.getByTestId('palette-container').click();
        await expect(page.locator('#prop-layout option')).toContainText([
            '指定しない',
            '縦に積む',
            '横に並べる',
        ]);
        await expect(page.locator('#prop-gap option')).toContainText([
            '指定しない',
            '広い（区切りの間）',
            'ふつう',
            '狭い',
        ]);

        const inspector = page.locator("[aria-label='ブロックの追加と操作']");
        await inspector.getByRole('button', { name: '複製' }).click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await expect(
            page.locator("[aria-label='ブロックの階層']"),
        ).not.toContainText('container-2');
    });
});

test.describe('UX 一周 第 7 周の回帰', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('列に並べた写真がスマホで豆粒にならない', async ({ page }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`第7周 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`ux-r7-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
        const edit = page.url();
        await page.goto(edit.replace(/\/edit$/, '/editor'));
        await page.getByTestId('canvas-empty-start').click();
        await page.getByTestId('palette-container').click();
        await page.locator('#prop-layout').selectOption('grid');
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await page.locator('#prop-columns').fill('3');
        await page.locator('#prop-columns').press('Tab');
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        const layers = page.locator("[aria-label='ブロックの階層']");
        const inspector = page.locator("[aria-label='ブロックの追加と操作']");

        for (let i = 0; i < 3; i++) {
            await layers
                .getByText('レイアウト', { exact: true })
                .first()
                .click();
            await page.getByTestId('palette-image').click();
            await inspector.getByRole('button', { name: '画像を選択' }).click();
            await page.locator('[data-testid^=media-choice-]').first().click();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
            );
        }

        await page.goto(edit);
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-status-badge')).toContainText(
            '公開中',
        );

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`/ux-r7-${stamp}`);
        const widths = await page
            .locator('main .doc-image img')
            .evaluateAll((els) =>
                els.map((e) => e.getBoundingClientRect().width),
            );
        expect(widths).toHaveLength(3);

        for (const width of widths) {
            expect(width).toBeGreaterThan(120);
        }
    });
});
