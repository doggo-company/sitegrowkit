import { expect, test } from '@playwright/test';

/**
 * 触らず保存しても公開日時が動かない（WP 一周 5 周目 2026-09-03 / 効くもの ①）。
 *
 * ## ここでしか見られないもの
 *
 * この欠陥は **サーバーだけを見ても、画面だけを見ても出てこない**。
 * サーバーは終始一貫していた（DB は `19:29:46` で正しい）。
 * 壊れていたのは**往復**である —
 *
 * 1. サーバーが ISO8601（UTC・末尾 Z）で流す
 * 2. 画面が `slice(0, 16)` で切って `2026-09-03T10:29` を欄に入れる
 * 3. 送り返すと、サーバーは時間帯を持たない文字を **JST として**読む
 * 4. `10:29 JST` で保存される。**押すたびに 9 時間ずつ過去へ戻る**
 *
 * Feature 試験は 1 と 3 しか見ない。vitest は 2 しか見ない。
 * **1〜4 を通して押すのはここだけ。**
 */
test.describe('公開日時（認証済み）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('触らずに保存を 2 回押しても、公開日時が動かない', async ({ page }) => {
        const title = `公開日時の往復 ${Date.now()}`;

        await page.goto('/admin/news/create');
        await page.fill('#title', title);
        await page.fill('#body', '本文');

        // Radix の Select なので開いて選ぶ（`selectOption` は効かない）
        await page.click('#status');
        await page.getByRole('option', { name: '公開' }).click();

        // **公開日時は空のまま**。PR #6 がその時刻を入れる
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/news');

        await page
            .locator('[data-testid="row-title-link"]', { hasText: title })
            .first()
            .click();
        await page.waitForURL('**/admin/news/*/edit');

        const editUrl = page.url();
        const field = page.locator('input[type="datetime-local"]').first();

        const stamped = await field.inputValue();

        expect(
            stamped,
            '公開を選んだのに日時が入っていない（PR #6 の自動押印）',
        ).not.toBe('');

        /*
         * 🔴 **ここが本体。** 欄に触らず保存する、を 2 回。
         * 直す前は 1 回目で 9 時間、2 回目でさらに 9 時間戻っていた。
         */
        for (let attempt = 1; attempt <= 2; attempt++) {
            await page.goto(editUrl);
            await page.click('button[type="submit"]');
            await page.waitForURL('**/admin/news');

            await page.goto(editUrl);

            await expect(
                page.locator('input[type="datetime-local"]').first(),
                `${attempt} 回目の「触らず保存」で公開日時が動いた`,
            ).toHaveValue(stamped);
        }
    });

    /**
     * 押印された日時が、**サーバーの時間帯の「いま」**であること。
     *
     * 往復の試験（上）は「動かない」ことしか見ない。**最初から 9 時間ずれた値で
     * 固定されていても通ってしまう**ので、絶対値をここで押さえる。
     * 時間帯は画面が受け取っているもの（`HandleInertiaRequests` の共有）を使う。
     */
    test('自動で入る公開日時が、サーバーの時間帯の「いま」になっている', async ({
        page,
    }) => {
        const title = `押印の絶対値 ${Date.now()}`;

        await page.goto('/admin/news/create');
        await page.fill('#title', title);
        await page.fill('#body', '本文');
        await page.click('#status');
        await page.getByRole('option', { name: '公開' }).click();
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/news');

        await page
            .locator('[data-testid="row-title-link"]', { hasText: title })
            .first()
            .click();
        await page.waitForURL('**/admin/news/*/edit');

        const stamped = await page
            .locator('input[type="datetime-local"]')
            .first()
            .inputValue();

        /*
         * 共有 props は `data-page` ではなく **inline の script** に入る
         * （この版の Inertia は CSP の nonce 付きで埋める）。
         */
        const timeZone = await page.evaluate(() => {
            const tag = [...document.querySelectorAll('script')].find((s) =>
                s.textContent?.includes('"component"'),
            );

            if (tag?.textContent == null) {
                return '';
            }

            const props = JSON.parse(tag.textContent).props ?? {};

            return typeof props.timezone === 'string' ? props.timezone : '';
        });

        expect(
            timeZone,
            '画面がサーバーの時間帯を受け取っていない（共有 props の timezone）',
        ).not.toBe('');

        const nowThere = new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date());

        // `2026-09-03, 19:29` → `2026-09-03T19:29`
        const expected = nowThere.replace(', ', 'T');

        const minutesApart =
            Math.abs(
                new Date(`${stamped}:00Z`).getTime() -
                    new Date(`${expected}:00Z`).getTime(),
            ) / 60000;

        expect(
            minutesApart,
            `押印が ${stamped}、サーバーの時間帯のいまが ${expected}`,
        ).toBeLessThan(5);
    });
});
