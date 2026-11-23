import { expect, test } from '@playwright/test';

/*
 * UX 一周 20 周目の採点（round20-grade.md）: 置き換え表（定番のページ名 → 英語）は題名がその語だけのとき。
 * ほかの語が付いた題は全部読む（地名・名字を消さない）。カタカナ語は英語の表記にそろえる（JMdict）。
 * 触り心地の門（playwright.feel.config.ts）から回る。
 */

test.use({
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

const EXAMPLES: Array<[string, string]> = [
    ['吉祥寺高橋トリミングサロン', '/kichijouji-takahashi-trimming-salon'],
    ['ネイルサロン', '/nail-salon'],
    ['ペットホテル', '/pet-hotel'],
    ['料金表', '/price'],
];

test('題名を打つと、地名・名字を残した URL の候補が出る。カタカナ語は英語の表記', async ({
    page,
}) => {
    await page.goto('/admin/pages/create');

    for (const [title, url] of EXAMPLES) {
        await page.getByTestId('page-title-input').fill(title);
        await expect(page.getByTestId('page-slug-draft')).toContainText(url, {
            timeout: 5_000,
        });
    }
});
