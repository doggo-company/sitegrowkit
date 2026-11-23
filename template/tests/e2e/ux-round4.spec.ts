import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 4 周目（2026-11-05・ヘアサロンの店長・スマホ中心・rc8）の回帰。
 *
 * - R4-1: メニュー表・営業時間で、プレビューの行を押すと「表の題」の欄に飛んだ
 * - R4-2: 見本の文字（〇〇曜日・ボタン）が値で入っていて、押して打つとくっついた
 * - R4-3: 欄に打った直後に部品を押すと、1 回目は何も置かれなかった
 * - R4-4: メニューを保存しても「保存するまで消えません／元に戻す」が残った
 * - R4-5: 見本と同じ名前でページを作っても、何も言われなかった
 *
 * **人の押し方で押す。** playwright の `click()` は押せるようになるまで待つので、
 * R4-3 のように「押した瞬間に押せない」ものは通ってしまう。`page.mouse` で座標を押す。
 */
const stamp = Date.now();

async function freshEditor(page: Page, slug: string): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`四周目 ${slug}`);
    await page.getByTestId('page-slug-input').fill(slug);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit/);
    await page.goto(page.url().replace(/\/edit$/, '/editor'));
    await page.getByTestId('canvas-empty-start').click();
    await saved(page);
}

async function saved(page: Page): Promise<void> {
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
        { timeout: 10_000 },
    );
}

function canvas(page: Page) {
    return page.frameLocator('[data-testid="editor-canvas"]').locator('main');
}

/** 人の押し方（押せるまで待たない） */
async function press(page: Page, testId: string): Promise<void> {
    const box = await page.getByTestId(testId).boundingBox();

    if (!box) {
        throw new Error(`${testId} が見えない`);
    }

    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
}

test.describe('UX 一周 4 周目', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('メニュー表の 1 品を押すと品名と値段の欄へ移り、見本が選ばれて打つと置き換わる（R4-1・R4-2）', async ({
        page,
    }) => {
        await freshEditor(page, `round4-menu-${stamp}`);
        await page.getByTestId('palette-menutable').click();
        await saved(page);

        await canvas(page)
            .getByText('〇〇（品名・コース名）', { exact: true })
            .first()
            .click();
        const items = page.locator('#prop-items');
        await expect(items).toBeFocused();
        // 置いたままの見本は全部選ばれるので、打った行だけが残る（5 周目 R4-2）
        await page.keyboard.type('カット｜4,400円');
        await saved(page);

        await expect(items).toHaveValue('カット｜4,400円');
        await expect(page.locator('#prop-caption')).toHaveValue('');
    });

    test('営業時間の定休日とボタンの文言も、押して打つと置き換わる（R4-2）', async ({
        page,
    }) => {
        await freshEditor(page, `round4-hours-${stamp}`);
        await page.getByTestId('palette-hours').click();
        await saved(page);

        await canvas(page).getByText('〇〇曜日', { exact: true }).click();
        await expect(page.locator('#prop-closed')).toBeFocused();
        await page.keyboard.type('月曜日');
        await saved(page);
        await expect(page.locator('#prop-closed')).toHaveValue('月曜日');

        await page.getByTestId('palette-button').click();
        await saved(page);
        // 欄をじかに押しても同じ（プレビューからでなくても）
        await page.locator('#prop-label').click();
        await page.keyboard.type('予約の問い合わせをする');
        await saved(page);
        await expect(page.locator('#prop-label')).toHaveValue(
            '予約の問い合わせをする',
        );
    });

    test('欄に打った直後に部品を押しても、1 回で置かれる（R4-3）', async ({
        page,
    }) => {
        await freshEditor(page, `round4-insert-${stamp}`);

        await page.locator('#prop-text').click();
        await page.keyboard.type('カットとカラー');
        // 打ち終えて、すぐ部品を押す（人の手で 0.15 秒）
        await page.waitForTimeout(150);
        await press(page, 'palette-text');

        await expect(page.getByTestId('layer-text')).toHaveCount(1, {
            timeout: 10_000,
        });
        await saved(page);
        await expect(canvas(page)).toContainText('カットとカラー');
    });

    test('メニューを保存すると「保存するまで消えません」が消える（R4-4）', async ({
        page,
    }) => {
        await page.goto('/admin/menus/header');
        // キット本体の E2E 環境はメニューを入れない（MenuSeeder を流さない）。項目が無ければ見られない
        test.skip(
            (await page.getByTestId('menu-remove-0').count()) === 0,
            'ヘッダーのメニューに項目が無い環境',
        );
        await page.getByTestId('menu-remove-0').click();
        await expect(page.getByTestId('menu-removed-notice')).toBeVisible();

        await page.getByTestId('menu-save').click();
        await expect(page.getByTestId('flash-success')).toBeVisible();
        await expect(page.getByTestId('menu-removed-notice')).toHaveCount(0);
    });

    test('見本と同じ名前を打つと、見本と入れ替わると言う（R4-5）', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        const notice = page.getByTestId('page-replaces-sample');
        await page.getByTestId('page-title-input').fill('ご予約について');
        await expect(notice).toHaveCount(0);

        // 業種を選ぶ前の /service の見本の名前は「サービス」
        await page.getByTestId('page-title-input').fill('サービス');
        await expect(notice).toContainText('/service');
        await page.getByTestId('page-slug-input').fill('my-own-url');
        await expect(notice).toHaveCount(0);
    });
});
