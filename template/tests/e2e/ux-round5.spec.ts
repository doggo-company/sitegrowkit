import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 公開前の UX 一周 5 周目（2026-11-05・犬の幼稚園・PC とタブレット・rc9）の回帰。
 *
 * - R4-3 / R5-3: 打った直後に部品・「ページ設定へ戻る」を押すと効かなかった。押した瞬間に
 *   保存の表示（「保存中…」など）の幅が変わって上の帯が折り返し、押す物が 44px 動いていた
 * - R5-4: PC 幅で「テキスト」を置くと、プレビューに押せる所が無く、打つ欄が画面の端で半分だった
 * - R5-1: リンク先にページの名前を打つと、黙って `#` に戻った
 * - R4-2（5 周目でも残った）: メニュー表の欄をじかに押して打つと、見本の行と混ざった
 *
 * **人の押し方で押す**（座標を押す。playwright の「押せるまで待つ」に頼らない）。
 */
const stamp = Date.now();

async function freshEditor(page: Page, slug: string): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`五周目 ${slug}`);
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

/** 人の押し方: 押し下げと離しのあいだに 80ms（その間に画面が動けば、離した所は別の物になる） */
async function humanClick(page: Page, x: number, y: number): Promise<void> {
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.waitForTimeout(80);
    await page.mouse.up();
}

async function center(page: Page, testId: string): Promise<[number, number]> {
    const box = await page.getByTestId(testId).boundingBox();

    if (!box) {
        throw new Error(`${testId} が見えない`);
    }

    return [box.x + box.width / 2, box.y + box.height / 2];
}

for (const viewport of [
    { width: 1440, height: 900 },
    { width: 820, height: 1180 },
]) {
    test.describe(`UX 一周 5 周目（${viewport.width}×${viewport.height}）`, () => {
        test.describe.configure({ mode: 'serial' });
        test.use({ storageState: 'tests/e2e/.auth/admin.json', viewport });

        test('打った直後に部品を押しても 1 回で置かれる（R4-3）', async ({
            page,
        }) => {
            await freshEditor(page, `round5-insert-${viewport.width}-${stamp}`);

            await page.locator('#prop-text').click();
            await page.keyboard.type('はじめての方へ');
            // 押す場所は打っている間に見た所（人は押す前に見直さない）
            const [x, y] = await center(page, 'palette-text');
            await humanClick(page, x, y);

            await expect(page.getByTestId('layer-text')).toHaveCount(1, {
                timeout: 10_000,
            });
            await saved(page);
            await expect(page.locator('#prop-text')).toHaveValue('');
        });

        test('置いたテキストはプレビューに押せる所が出て、打つ欄が隠れずに出る（R5-4）', async ({
            page,
        }) => {
            await freshEditor(page, `round5-text-${viewport.width}-${stamp}`);
            await page.getByTestId('palette-text').click();
            await saved(page);

            await expect(
                canvas(page).getByText('テキストを入力', { exact: true }),
            ).toBeVisible();
            const field = page.locator('#prop-text');
            await expect(field).toBeInViewport({ ratio: 1 });
        });

        test('打った直後に「ページ設定へ戻る」を押しても移る（R5-3）', async ({
            page,
        }) => {
            await freshEditor(page, `round5-back-${viewport.width}-${stamp}`);

            await page.locator('#prop-text').click();
            await page.keyboard.type('ご利用の流れ');
            // 欄は下の方にあるので、人は上へ戻ってから押す（指で巻くだけ。欄からはまだ離れない）
            await page.mouse.wheel(0, -5000);
            await page.waitForTimeout(300);
            const back = page.getByRole('link', { name: 'ページ設定へ戻る' });
            const box = await back.boundingBox();
            await humanClick(
                page,
                (box?.x ?? 0) + (box?.width ?? 0) / 2,
                (box?.y ?? 0) + (box?.height ?? 0) / 2,
            );

            await page.waitForURL(/\/admin\/pages\/\d+\/edit$/, {
                timeout: 10_000,
            });
        });
    });
}

test.describe('UX 一周 5 周目（リンク先）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('メニュー表の欄をじかに押して何行か打つと、見本の行と混ざらない（R4-2）', async ({
        page,
    }) => {
        await freshEditor(page, `round5-menu-${stamp}`);
        await page.getByTestId('palette-menutable').click();
        await saved(page);

        await page.locator('#prop-items').click();
        await page.keyboard.type('幼稚園 1日コース｜5,500円');
        await page.keyboard.press('Enter');
        await page.keyboard.type('ペットホテル 1泊｜6,600円');
        await saved(page);

        await expect(page.locator('#prop-items')).toHaveValue(
            '幼稚園 1日コース｜5,500円\nペットホテル 1泊｜6,600円',
        );
    });

    test('リンク先にページの名前を打つと、その URL になる（R5-1）', async ({
        page,
    }) => {
        await freshEditor(page, `round5-link-${stamp}`);
        await page.getByTestId('palette-button').click();
        await saved(page);

        const href = page.locator('#prop-href');
        await href.fill('お問い合わせ');
        await href.press('Tab');
        await saved(page);
        await expect(href).toHaveValue('/contact');
    });

    test('使えないリンク先は、欄のすぐ下で言い、打った文字を消さない（R5-1）', async ({
        page,
    }) => {
        await freshEditor(page, `round5-link-bad-${stamp}`);
        await page.getByTestId('palette-button').click();
        await saved(page);

        const href = page.locator('#prop-href');
        await href.fill('見学のページ');
        await href.press('Tab');

        await expect(page.getByTestId('inspector-error-href')).toBeVisible();
        await expect(page.getByTestId('inspector-error-href')).toBeInViewport();
        await expect(href).toHaveValue('見学のページ');
    });
});
