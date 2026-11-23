import { expect, test } from '@playwright/test';

/*
 * UX 一周 21 周目の採点（round21-grade.md）: 題名が「定番名＋ありふれた付け足し」だけなら定番名の英語（Y1）。
 * 英語でない外来語は音で、の＋ご は区切る（Y2）。
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
    ['アクセス方法', '/access'],
    ['スタッフ紹介', '/staff'],
    ['鎌倉スタッフ紹介', '/kamakura-staff-shoukai'],
    ['パンとコーヒーのご案内', '/pan-to-coffee-no-go-annai'],
    ['体験レッスンのお申し込み', '/taiken-lesson-no-o-moushikomi'],
];

test('定番名＋付け足しは定番名の英語。英語でない外来語は音、の＋お・の＋ご は区切る', async ({
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
