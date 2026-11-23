import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 日常編集トラック（Phase 9.15 / P0-B）を**実際に押して**確かめる。
 *
 * ## ここでしか見られないもの
 *
 * Feature 試験は「どの状態で何が渡るか」を、vitest は「時間の判断」を見ている。
 * どちらも **画面が組み立てるもの**は見ていない —
 * ボタンが本当に出ているか、押したら本当に飛ぶか、値が本当に入るか。
 *
 * 過去に e2e だけが捕まえた欠陥（binding の field 欠落・CSRF）と同じ場所である。
 */
// 新品プロジェクトの投稿でも、kit のニュースでも同じ利用者操作を検証する。
const contentType = process.env.AGENTCMS_E2E_CONTENT_TYPE ?? 'news';

test.describe('日常編集（認証済み）', () => {
    test.use({
        storageState:
            process.env.AGENTCMS_E2E_AUTH_STATE ?? 'tests/e2e/.auth/admin.json',
    });

    /** 下書きを 1 本作り、その編集画面の URL を返す */
    async function createDraft(page: Page, title: string): Promise<string> {
        await page.goto(`/admin/${contentType}/create`);
        await page.fill('#title', title);
        // 本文は見たまま編集（contenteditable）。文字を打てば段落になる
        await page.fill('#body', '本文');

        // 新規記事は最初から下書き。タイトルと本文だけで保存できる。
        await expect(page.locator('#status')).toContainText('下書き');

        await page.click('button[type="submit"]');

        // 一覧まで戻ってきたことを確かめる（検証で弾かれたら作成画面に留まる）
        await page.waitForURL(`**/admin/${contentType}`);
        await expect(page.locator('table')).toContainText(title);

        await page
            .locator('[data-testid="row-title-link"]', { hasText: title })
            .first()
            .click();
        await page.waitForURL(`**/admin/${contentType}/*/edit`);

        return page.url();
    }

    test('28A: 下書きをプレビューで開ける', async ({ page, context }) => {
        await createDraft(page, `プレビュー確認 ${Date.now()}`);

        const link = page.locator('[data-testid="form-preview-link"]');
        await expect(link).toBeVisible();

        // 新しいタブで開く導線なので、開いた先を掴む
        const [preview] = await Promise.all([
            context.waitForEvent('page'),
            link.click(),
        ]);

        await preview.waitForLoadState();
        await expect(preview.locator('body')).toContainText('プレビュー確認');
        await preview.close();
    });

    /**
     * 28B: **触ってから待つと、版が 1 つ増える。**
     *
     * fake timer ではなく実時間で 1 本だけ通す。
     * `setInterval` が本当に張られているか・送信先が合っているかは、ここでしか出ない。
     */
    test('28B: しばらく編集していると自動保存される', async ({ page }) => {
        // 自動保存の間隔は 30 秒。**既定の試験時間（30 秒）では必ず足りない**
        test.setTimeout(120_000);

        const editUrl = await createDraft(page, `自動保存確認 ${Date.now()}`);

        const autosave = page.waitForResponse(
            (response) =>
                response.url().includes('/autosave') &&
                response.request().method() === 'POST',
            { timeout: 60_000 },
        );

        await page.fill('#title', '書きかけの見出し');

        const response = await autosave;
        expect(response.status()).toBe(200);

        // **記事そのものは書き換わらない**（開き直しても元のまま）
        await page.goto(editUrl);
        await expect(page.locator('#title')).not.toHaveValue(
            '書きかけの見出し',
        );
    });

    test('28C: 自動保存があると案内が出て、復元できる', async ({ page }) => {
        test.setTimeout(120_000);

        const editUrl = await createDraft(page, `復帰確認 ${Date.now()}`);

        const autosave = page.waitForResponse(
            (response) =>
                response.url().includes('/autosave') &&
                response.request().method() === 'POST',
            { timeout: 60_000 },
        );

        await page.fill('#title', '自動保存された見出し');
        await autosave;

        await page.goto(editUrl);

        const notice = page.locator('[data-testid="autosave-notice"]');
        await expect(notice).toBeVisible();

        await notice.getByRole('button', { name: '復元する' }).click();

        // **復元は確認を通る**（`requireAlways`）。token 無しで送ると何も起きない
        await page
            .getByRole('button', { name: '自動保存の内容へ戻す' })
            .click();

        // **復元が終わるまで待つ。** 終わる前に読み込むと、戻る前の内容が見える
        await page.waitForURL(`**/admin/${contentType}`);

        await page.goto(editUrl);
        await expect(page.locator('#title')).toHaveValue(
            '自動保存された見出し',
        );
    });

    /**
     * R02: **見たまま編集で付けた書式が、公開側にも残る**（2026-09-09）。
     *
     * allowlist との整合は PHP 側（`RichtextToolbarTest`）が見ているが、
     * 「ボタンが本当に TipTap へ効き、HTML として保存され、公開で描かれるか」はここでしか出ない。
     */
    test('R02: 見たまま編集の太字が公開側にも残る', async ({ page }) => {
        const title = `書式確認 ${Date.now()}`;
        const editUrl = await createDraft(page, title);

        const body = page.locator('#body');
        await expect(body).toHaveAttribute('role', 'textbox');
        await body.fill('大事な話');
        await body.selectText();

        await page
            .locator('[data-testid="richtext-toolbar"]')
            .getByRole('button', { name: '太字' })
            .click();

        // 見たままの中で太字になっている
        await expect(body.locator('strong')).toHaveText('大事な話');

        // HTML へ切り替えると、保存される形が見える（値の正本は 1 つ）
        await page.getByTestId('richtext-mode-html').click();
        await expect(page.locator('#body')).toHaveValue(
            '<p><strong>大事な話</strong></p>',
        );

        // 見たままへ戻しても失われない（保存往復）
        await page.getByTestId('richtext-mode-visual').click();
        await expect(page.locator('#body strong')).toHaveText('大事な話');

        // 公開して、公開ページに残ることまで見る
        await page.click('#status');
        await page.getByRole('option', { name: '公開' }).click();

        const now = new Date(Date.now() - 60_000);
        await page.fill(
            '#published_at',
            new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
                .toISOString()
                .slice(0, 16),
        );

        await page.click('button[type="submit"]');
        await page.waitForURL(`**/admin/${contentType}`);

        await page.goto(editUrl);
        const publicUrl = await page
            .getByTestId('form-public-link')
            .getAttribute('href');
        expect(publicUrl).toBeTruthy();
        await page.goto(publicUrl!);
        await page.waitForLoadState();

        await expect(page.locator('.article__body strong')).toHaveText(
            '大事な話',
        );
    });

    /**
     * R02 / R08: **リンク先に `javascript:` は入らない**。
     * 断り方も「無効です」ではなく、何なら通るかを言う。
     */
    test('R02: リンク先に javascript: を入れると断られる', async ({ page }) => {
        await createDraft(page, `リンク確認 ${Date.now()}`);

        const body = page.locator('#body');
        await body.fill('公式サイト');
        await body.selectText();

        await page
            .locator('[data-testid="richtext-toolbar"]')
            .getByRole('button', { name: 'リンク' })
            .click();

        const form = page.getByTestId('richtext-link-form');
        await form.getByLabel('リンク先').fill('javascript:alert(1)');
        await form.getByRole('button', { name: 'リンクを付ける' }).click();

        await expect(page.getByTestId('richtext-link-error')).toContainText(
            'http://',
        );
        await expect(body.locator('a')).toHaveCount(0);

        // 正しい URL なら付く。scheme を落として打っても https が補われる
        await form.getByLabel('リンク先').fill('example.com/news');
        await form.getByRole('button', { name: 'リンクを付ける' }).click();

        await expect(body.locator('a')).toHaveAttribute(
            'href',
            'https://example.com/news',
        );
    });

    /**
     * R02: **公開で表示されない要素を、黙って落とさない**。
     *
     * 見たまま編集の対応範囲はサーバーの sanitize（`Purifier::ALLOWED`）と同じなので、
     * `<table>` は **HTML のまま保存してもサーバーが取り除く**（実ブラウザで実測）。
     * だから「HTML なら残る」とは言わず、**取り除かれる前に何が在るかを見せる**。
     */
    test('R02: 公開で表示されない要素は、取り除く前に知らせる', async ({
        page,
    }) => {
        const editUrl = await createDraft(page, `表つき ${Date.now()}`);

        await page.getByTestId('richtext-mode-html').click();
        await page
            .locator('textarea#body')
            .fill('<table><tr><td>売上</td></tr></table><p>本文</p>');

        // 見たままへ押しても切り替わらず、何が取り除かれるかを帯で言う
        await page.getByTestId('richtext-mode-visual').click();
        await expect(page.getByTestId('richtext-unsupported')).toContainText(
            'table',
        );
        await expect(page.locator('textarea#body')).toBeVisible();

        // 保存するとサーバーが取り除き、中の文字は残る
        await page.click('button[type="submit"]');
        await page.waitForURL(`**/admin/${contentType}`);
        await page.goto(editUrl);

        await expect(page.locator('#body')).toHaveAttribute('role', 'textbox');
        await expect(page.locator('#body')).toContainText('売上');
        await expect(page.locator('#body')).toContainText('本文');
    });

    /**
     * R02: **貼り付けた script は本文に入らない**（TipTap が schema で読み直す）。
     * 表など公開で出ない要素は、文字だけ残したことを帯で知らせる。
     */
    test('R02: 貼り付けた script は入らず、落ちたものは知らせる', async ({
        page,
    }) => {
        await createDraft(page, `貼り付け ${Date.now()}`);

        const body = page.locator('#body');
        await body.click();

        await page.evaluate(() => {
            const data = new DataTransfer();
            data.setData(
                'text/html',
                '<p onclick="alert(1)">貼り付け</p><table><tr><td>表</td></tr></table>',
            );
            document.getElementById('body')?.dispatchEvent(
                new ClipboardEvent('paste', {
                    clipboardData: data,
                    bubbles: true,
                    cancelable: true,
                }),
            );
        });

        await expect(page.getByTestId('richtext-paste-notice')).toContainText(
            'table',
        );

        await page.getByTestId('richtext-mode-html').click();
        const html = await page.locator('textarea#body').inputValue();
        expect(html).not.toMatch(/onclick|<script/);
        expect(html).not.toContain('<table');
    });

    /**
     * R07: **スマホ幅でも書式ボタンと本文に届く**（横はみ出し無し・ボタンは 40px 以上）。
     */
    test('R07: 375px でも見たまま編集の操作に届く', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await createDraft(page, `スマホ ${Date.now()}`);

        const scrollWidth = await page.evaluate(
            () => document.documentElement.scrollWidth,
        );
        expect(scrollWidth).toBeLessThanOrEqual(375);

        const bold = page
            .locator('[data-testid="richtext-toolbar"]')
            .getByRole('button', { name: '太字' });
        const box = await bold.boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(40);

        await page.locator('#body').fill('スマホから');
        await bold.click();
        await expect(page.locator('#body')).toContainText('スマホから');
    });
});
