import { expect, test } from '@playwright/test';

/**
 * フォームの入力項目を変えたときの確認が、**利用者の言葉で出る**
 * （WP 一周 5 周目 2026-09-03）。
 *
 * ## ここでしか見られないもの
 *
 * 言い換えの規則は vitest（`field-confirmation.test.ts`）が持っている。
 * だが実測で出ていたのは
 *
 * > **`field_3`**: 必須項目の追加は、既存データの埋め戻しが必要です
 *
 * だった。**規則は正しく、呼び出し側が渡す名前が足りていなかった** —
 * キーはサーバーが採番するので、画面の新しい行は保存の瞬間までキーを持たない。
 * これは「関数を単体で試す」側からは見えない。
 *
 * ## 確認そのものは正しい振る舞いである
 *
 * 必須の追加は `ConfirmationGate` が止める（オーナー決定）。**そこは変えない。**
 * 直すのは、止めたときに**何と呼ぶか**だけである。
 */
test.describe('フォームの入力項目（認証済み）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('必須を足したときの確認が、キーではなく表示名で出る', async ({
        page,
    }) => {
        const label = `お電話番号 ${Date.now()}`;

        await page.goto('/admin/forms/new');
        await page.getByTestId('form-name').fill(`確認の呼び名 ${Date.now()}`);
        await page.getByTestId('form-save').click();
        await page.waitForURL('**/admin/forms/*');

        await page.getByTestId('form-add-field').click();
        await page
            .getByTestId('field-group-field-label')
            .first()
            .fill(label);

        // **必須にすると確認が要る**（`migration_required`）。ここが出発点
        await page.getByRole('checkbox', { name: '必須にする' }).first().check();

        await page.getByTestId('form-save').click();

        const dialog = page.getByTestId('operation-confirmation');

        await expect(dialog).toBeVisible();

        // 🔴 直す前は `field_1: 必須項目の追加は…` と出ていた
        await expect(dialog).toContainText(label);
        await expect(dialog).not.toContainText(/\bfield_\d+\b/);

        // 承認すれば保存される（確認そのものは正しい振る舞い）
        await dialog.getByRole('button', { name: 'この内容で保存する' }).click();

        await expect(dialog).toBeHidden();

        await page.reload();

        await expect(
            page.getByTestId('field-group-field-label').first(),
        ).toHaveValue(label);
    });
});
