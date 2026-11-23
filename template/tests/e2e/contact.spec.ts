import { expect, test } from '@playwright/test';

const MAILPIT_API = process.env.MAILPIT_URL ?? 'http://localhost:8026';

// Turnstile は Cloudflare 公式テストキー（常に成功）を前提とする（.env.example）
//
// R-12（受付の一本化・2026-08-30）: `/contact` は **CMS のフォーム**が出す
// （`ContactFormSeeder` が識別子 `contact` のフォームを 1 本入れる）。
// 見た目は同梱見本のまま（`resources/views/forms/imported.blade.php`）だが、
// **入力欄の id は項目キーから作られる**（`f-<key>`）ので、旧デザインの
// `#name` / `#type` / `name="privacy"` はもう無い。
test.describe('お問い合わせフォーム', () => {
    test.describe.configure({ mode: 'serial' });

    test('バリデーションエラーが表示される', async ({ page }) => {
        await page.goto('/contact');
        await page.fill('#f-name', 'E2E テスト');
        // email 未入力のまま送信し、サーバー側バリデーションを確認する
        await page.selectOption('#f-subject', 'その他');
        await page.fill('#f-message', 'テスト本文');
        await page.waitForFunction(() => {
            const el = document.querySelector<HTMLInputElement>(
                '[name="cf-turnstile-response"]',
            );

            return el !== null && el.value.length > 0;
        });
        await page.click('button[type="submit"]');
        await expect(page.locator('.form-error').first()).toBeVisible();
    });

    test('送信成功でサンクス表示と Mailpit 2 通', async ({ page, request }) => {
        const before = await (
            await request.get(`${MAILPIT_API}/api/v1/messages?limit=1`)
        ).json();
        const beforeTotal: number = before.total ?? 0;

        await page.goto('/contact');
        await page.fill('#f-name', 'E2E テスト');
        await page.fill('#f-email', 'e2e@example.com');
        await page.selectOption('#f-subject', 'お見積もり');
        await page.fill('#f-message', 'E2E からの送信テストです。');
        await page.waitForFunction(() => {
            const el = document.querySelector<HTMLInputElement>(
                '[name="cf-turnstile-response"]',
            );

            return el !== null && el.value.length > 0;
        });
        await page.click('button[type="submit"]');

        // 送信後は完了画面へ移る（`forms.complete`）。**同じ画面に緑の箱**ではない
        await expect(page.locator('.form-page__complete')).toBeVisible();

        // 管理者通知 + 自動返信の 2 通が Mailpit に届く
        await expect
            .poll(
                async () => {
                    const res = await (
                        await request.get(
                            `${MAILPIT_API}/api/v1/messages?limit=1`,
                        )
                    ).json();

                    return (res.total ?? 0) - beforeTotal;
                },
                { timeout: 10_000 },
            )
            .toBeGreaterThanOrEqual(2);
    });
});
