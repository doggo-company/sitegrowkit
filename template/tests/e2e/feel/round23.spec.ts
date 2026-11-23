import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/*
 * UX 一周 23 周目の採点（round23-grade.md・写真館）: PC 1366×768 のブロック編集。
 * 触り心地の門（playwright.feel.config.ts）から回る。docs/feel-bar.md。
 */

test.use({
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

async function newPageInEditor(page: Page, slug: string): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`撮影のご案内 ${slug}`);
    await page.getByTestId('page-slug-input').fill(slug);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await page.getByTestId('page-editor-link').click();
    await page.waitForURL(/\/editor$/);
    await page.getByTestId('editor-empty-start').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
}

test('#1: 入力欄に打った直後に「公開する」を 1 回押すと公開される（押した見た目はすぐ）', async ({
    page,
}) => {
    const slug = `typed-${`${Date.now()}`.slice(-7)}`;
    await newPageInEditor(page, slug);

    const publish = page.getByTestId('editor-publish-button');
    await page.locator('#prop-text').click();
    await page.keyboard.type('七五三の撮影をご案内します');
    // 打ち終えてすぐ（欄を離れる保存と同時に）1 回だけ押す
    await publish.click();
    await expect(publish).toHaveAttribute('aria-busy', 'true', {
        timeout: 300,
    });
    await expect(page.getByText('ページを公開しました').first()).toBeVisible({
        timeout: 10_000,
    });
    await expect(publish).toHaveText('公開中');
    const html = await (await page.request.get(`/${slug}`)).text();
    expect(html).toContain('七五三の撮影をご案内します');

    // 公開中のページを直して、打った直後に 1 回押す（「変更を公開する」）
    await page.locator('#prop-text').click();
    await page.keyboard.press('End');
    await page.keyboard.type('（直した）');
    await publish.click();
    await expect(page.getByText('ページを公開しました').first()).toBeVisible({
        timeout: 10_000,
    });
    await expect(publish).toHaveText('公開中');
    expect(await (await page.request.get(`/${slug}`)).text()).toContain(
        '（直した）',
    );
});

test('#7: 画像ブロックを置くと「画像を選択」が右の欄の中に全部見えている', async ({
    page,
}) => {
    const slug = `img-${`${Date.now()}`.slice(-7)}`;
    await newPageInEditor(page, slug);
    await page.getByTestId('palette-image').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
        { timeout: 10_000 },
    );
    const button = page.getByTestId('media-picker-open').first();
    await expect(button).toBeVisible();
    await page.waitForTimeout(400);

    const box = await button.evaluate((el) => {
        const r = el.getBoundingClientRect();
        let clip = { top: 0, bottom: window.innerHeight };

        for (let p = el.parentElement; p; p = p.parentElement) {
            const cs = getComputedStyle(p);

            if (/(auto|scroll|hidden)/.test(cs.overflowY)) {
                const pr = p.getBoundingClientRect();
                clip = {
                    top: Math.max(clip.top, pr.top),
                    bottom: Math.min(clip.bottom, pr.bottom),
                };
            }
        }

        return { top: r.top, bottom: r.bottom, ...{ clip } };
    });
    expect(
        box.top >= box.clip.top - 1 && box.bottom <= box.clip.bottom + 1,
        `「画像を選択」が見切れている（ボタン ${Math.round(box.top)}〜${Math.round(box.bottom)}・見える所 ${Math.round(box.clip.top)}〜${Math.round(box.clip.bottom)}）`,
    ).toBe(true);
});
