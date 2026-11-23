import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * 公開前の UX 一周 3 周目（2026-11-05・整骨院の受付の人・rc7）の、画面の位置の話の回帰。
 *
 * - N3: 置いたばかりの見出し・本文に仮の文字が**値として**入り、打つとくっついた
 * - N5: 画像を置くと「右の『画像を選択』から」と出るのに、そのボタンが画面の外だった
 * - M11: サイト設定の下の「保存」を押すと、知らせが画面の外（上）に出て、横の「保存しました」も下へ押し出された
 * - N4: フォームの「保存する」でも同じ（知らせが top=-1672）
 *
 * 業種（M2）と URL（M3）は `tests/Feature/UxRelease20261105Round3Test.php` が見る。
 */
const stamp = Date.now();

async function freshEditor(page: Page, slug: string): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`三周目 ${slug}`);
    await page.getByTestId('page-slug-input').fill(slug);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
    await page.goto(page.url().replace(/\/edit$/, '/editor'));
}

/** 要素の真ん中が、ほかの要素に隠れずに見えているか（巻き直さずに見る） */
async function uncovered(locator: Locator): Promise<boolean> {
    return locator.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const hit = document.elementFromPoint(
            r.left + r.width / 2,
            r.top + r.height / 2,
        );

        return hit !== null && (hit === el || el.contains(hit));
    });
}

function canvas(page: Page) {
    return page.frameLocator('[data-testid="editor-canvas"]').locator('main');
}

test.describe('UX 一周 3 周目', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('置いたばかりの見出しは空で、仮の文字は打てば消え、保存されない（N3）', async ({
        page,
    }) => {
        await freshEditor(page, `round3-n3-${stamp}`);
        await page.getByTestId('canvas-empty-start').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );

        const field = page.locator('#prop-text');
        await expect(field).toHaveValue('');
        await expect(field).toHaveAttribute('placeholder', '見出しを入力');

        // PC が苦手な人の手順: プレビューの仮の文字を押して、そのまま打つ（全部選んだりしない）
        await canvas(page)
            .getByText('見出しを入力', { exact: true })
            .click();
        await expect(field).toBeFocused();
        await page.keyboard.type('腰痛・肩こりの施術');

        await expect(field).toHaveValue('腰痛・肩こりの施術');
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
            { timeout: 10_000 },
        );
        await expect(canvas(page)).toContainText('腰痛・肩こりの施術');
        await expect(canvas(page)).not.toContainText('見出しを入力');

        // 本文も同じ（仮の文字は欄に見えるが、値ではない）
        await page.getByTestId('palette-richtext').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await expect(page.getByTestId('richtext-placeholder')).toHaveText(
            '本文を入力',
        );
        await page.getByTestId('richtext-visual').click();
        await page.keyboard.type('国家資格を持つ施術者が担当します。');
        await expect(page.getByTestId('richtext-placeholder')).toHaveCount(0);
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
            { timeout: 10_000 },
        );

        // 読み直しても、打った文字だけが残っている（仮の文字はくっついていない）
        await page.reload();
        await expect(canvas(page)).toContainText('腰痛・肩こりの施術');
        await expect(canvas(page)).toContainText(
            '国家資格を持つ施術者が担当します。',
        );
        await expect(canvas(page)).not.toContainText('見出し腰痛');
        await expect(canvas(page)).not.toContainText('リッチテキスト');
        await expect(canvas(page)).not.toContainText('本文を入力');
    });

    test('画像を置くと「画像を選択」が画面の中に出て、プレビューの案内から選べる（N5）', async ({
        page,
    }) => {
        await freshEditor(page, `round3-n5-${stamp}`);
        await page.getByTestId('canvas-empty-start').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );

        await page.getByTestId('palette-image').click();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );

        const choose = page
            .getByTestId('inspector-prop-mediaId')
            .getByRole('button', { name: '画像を選択' });
        await expect(choose).toBeInViewport();
        await expect(choose).toBeFocused();
        /*
         * 画面の中にあっても、上に留まっている「追加する」の下に隠れていれば見えない
         * （1440×900 で実際に隠れた）。ボタンの真ん中を押したときに当たるのがボタンかを見る。
         * playwright の click は押す前に自分で巻き直すので、ここでは使わない。
         */
        expect(await uncovered(choose)).toBe(true);

        // 右の欄をいちばん下まで下げてから、プレビューの案内を押す
        await page.getByTestId('editor-delete').scrollIntoViewIfNeeded();
        await page
            .locator('[aria-label="ブロックの追加と操作"]')
            .evaluate((el) => el.scrollTo(0, el.scrollHeight));
        await canvas(page).locator('.doc-image--empty').click();

        await expect(page.getByTestId('media-picker-dialog')).toBeVisible();
    });

    for (const viewport of [
        { width: 1440, height: 900 },
        { width: 390, height: 844 },
    ]) {
        test(`画像を置くと「画像を選択」が隠れずに出る（N5・${viewport.width}×${viewport.height}）`, async ({
            page,
        }) => {
            await page.setViewportSize(viewport);
            await freshEditor(page, `round3-n5-${viewport.width}-${stamp}`);
            await page.getByTestId('canvas-empty-start').click();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
            );
            await page.getByTestId('palette-image').click();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
            );

            const choose = page
                .getByTestId('inspector-prop-mediaId')
                .getByRole('button', { name: '画像を選択' });
            await expect(choose).toBeFocused();
            await expect(choose).toBeInViewport();
            expect(await uncovered(choose)).toBe(true);
        });
    }

    test('サイト設定の下で「保存」を押しても、知らせが画面の中に出て中身がずれない（M11）', async ({
        page,
    }) => {
        await page.goto('/admin/site');
        // 作り直した DB ではサイト名が空のことがある（空だと保存できない）
        const name = page.getByLabel('サイト名');

        if ((await name.inputValue()) === '') {
            await name.fill('ひだまり整骨院');
        }

        const save = page.getByRole('button', { name: '保存', exact: true });
        await save.scrollIntoViewIfNeeded();
        await page.evaluate(() =>
            window.scrollTo(0, document.documentElement.scrollHeight),
        );
        const before = await save.boundingBox();

        await save.click();

        await expect(page.getByTestId('flash-success')).toBeInViewport();
        await expect(page.getByTestId('site-saved')).toBeInViewport();
        const after = await save.boundingBox();
        expect(Math.round(after?.y ?? -1)).toBe(Math.round(before?.y ?? -2));

        // 閉じられる
        await page.getByTestId('flash-close').click();
        await expect(page.getByTestId('flash-success')).toHaveCount(0);
    });

    test('フォームの下で「保存する」を押しても、知らせが画面の中に出る（N4）', async ({
        page,
    }) => {
        // 環境によって同梱のフォーム（contact）が無いので、自分で 1 つ作ってから開く
        await page.goto('/admin/forms/new');
        await page.getByTestId('form-name').fill(`保存の知らせ ${stamp}`);
        await page.getByTestId('form-save').click();
        await page.waitForURL(/\/admin\/forms\/(?!new)[^/]+$/);
        const save = page.getByTestId('form-save').last();
        await save.scrollIntoViewIfNeeded();
        await page.evaluate(() =>
            window.scrollTo(0, document.documentElement.scrollHeight),
        );
        const before = await save.boundingBox();

        await save.click();

        await expect(page.getByTestId('flash-success')).toBeInViewport();
        const after = await save.boundingBox();
        expect(Math.round(after?.y ?? -1)).toBe(Math.round(before?.y ?? -2));
    });
});
