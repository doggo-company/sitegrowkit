import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * 投稿タイプ（Content Type Builder）の画面 — Phase 9.4 / STAGE #17A。
 *
 * ## ここで作成まで実行しない理由
 *
 * 適用は **リポジトリへファイルを書く**（`content/schemas/` と `modules/`）。
 * E2E がそれを実行すると、実行するたびに作業ツリーが汚れて元に戻らない。
 * 生成・rollback・冪等性は隔離ディレクトリと隔離 DB を使う
 * `tests/Feature/ContentTypes/` の責務であり、ここは
 *
 * - 画面が開くこと
 * - **確認するまで何も起きない**こと（preview で止まること）
 * - XSS payload が文字列として表示され、実行されないこと
 * - console error 0 / axe critical・serious 0
 *
 * だけを、実ブラウザで確かめる。
 */
test.describe('投稿タイプ', () => {
    // 管理画面なので `auth.setup.ts` が保存したログイン状態を使う
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('一覧が開き、既存の投稿タイプが読み取り専用で並ぶ', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });

        await page.goto('/admin/content-types');

        await expect(
            page.getByRole('heading', { name: '投稿タイプ' }),
        ).toBeVisible();
        await expect(page.getByTestId('content-type-row')).not.toHaveCount(0);
        await expect(
            page.getByText('コード管理（読み取り専用）').first(),
        ).toBeVisible();

        // 投稿一覧への導線
        await expect(
            page.getByTestId('content-type-entries-link').first(),
        ).toBeVisible();

        expect(errors).toEqual([]);
    });

    test('作成画面が開き、標準フィールドが提案される', async ({ page }) => {
        const errors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });

        await page.goto('/admin/content-types/create');

        await expect(
            page.getByRole('heading', { name: '投稿タイプを追加' }),
        ).toBeVisible();

        // title / slug / body / status / published_at の 5 件が初期提案
        await expect(page.getByTestId('builder-field')).toHaveCount(5);

        expect(errors).toEqual([]);
    });

    test('確認するまで何も作られない（preview で止まる）', async ({ page }) => {
        await page.goto('/admin/content-types/create');

        await page.fill('#label', 'E2E 確認用');
        await page.fill('#id', 'e2e_preview_only');
        await page.getByRole('button', { name: '内容を確認する' }).click();

        // 計画（何が作られるか）が出る
        const plan = page.getByTestId('content-type-plan');
        await expect(plan).toBeVisible();
        await expect(plan).toContainText('e2e_preview_only');
        await expect(plan).toContainText('/admin/e2e_preview_only');

        // 確認ダイアログが出る。**ここで承認しない**
        const dialog = page.getByTestId('operation-confirmation');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('作成します');

        await page.getByRole('button', { name: 'キャンセル' }).click();
        await expect(dialog).toBeHidden();

        // 承認しなかったので、投稿タイプは増えていない
        await page.goto('/admin/content-types');
        await expect(page.getByText('e2e_preview_only')).toHaveCount(0);

        // 管理画面 URL も生えていない
        const response = await page.goto('/admin/e2e_preview_only');
        expect(response?.status()).toBe(404);
    });

    test('入力した HTML は文字列として表示され、実行されない', async ({
        page,
    }) => {
        await page.goto('/admin/content-types/create');

        // window へ痕跡を残そうとする payload を入れる
        await page.fill('#label', '<img src=x onerror="window.__xss=1">');
        await page.fill('#id', 'e2e_xss_check');
        await page.getByRole('button', { name: '内容を確認する' }).click();

        const plan = page.getByTestId('content-type-plan');
        await expect(plan).toBeVisible();

        // 文字列としてそのまま見える（消さない・書き換えない）
        await expect(plan).toContainText('<img src=x onerror=');

        // DOM に img 要素として入っていない = 実行経路が無い
        expect(await plan.locator('img').count()).toBe(0);
        expect(await page.evaluate(() => '__xss' in window)).toBe(false);

        await page.getByRole('button', { name: 'キャンセル' }).click();
    });

    test('axe critical / serious が 0', async ({ page }) => {
        for (const url of [
            '/admin/content-types',
            '/admin/content-types/create',
        ]) {
            await page.goto(url);

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
                .analyze();

            const blocking = results.violations.filter((violation) =>
                ['critical', 'serious'].includes(violation.impact ?? ''),
            );

            expect(
                blocking.map((violation) => `${url}: ${violation.id}`),
            ).toEqual([]);
        }
    });
});
