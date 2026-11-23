import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * トップページに指定したページが**出せなくなった後**も、サイト設定が使えること（R-5 / 監査 指摘 1）。
 *
 * ## なぜ e2e でしか見えないのか
 *
 * 判断そのもの（候補に無い id を「選ばない」へ落とす）は純関数に切り出してあり、
 * `resources/js/lib/__tests__/page-choice.test.ts` が固定している。
 * **見えないのは配線**である — この repo に component 試験の仕組みは無く、
 * PHP 側の試験は「画面が null を送ってきたら」から始まるので、
 * **画面が判断を忘れても緑のまま**になる。
 *
 * ▷ **見張っているのは `site/Edit.vue` の配線**である。
 *   ccb の canary 実測で分かったこと: 守りは 2 枚あり、**上流（`Edit.vue` がフォームの
 *   初期値を落とす）が効いている限り、下流（`SchemaFieldInput` の見せ方）は出番が無い**。
 *   つまりこの spec が赤にできるのは上流側だけで、**下流だけを壊しても緑のまま**である。
 *   下流は「上流が抜けたときの二重の守り」として残してある。
 *
 * 起票時の症状はこうだった: 指定したページを出せない状態にすると、
 * Select が**空白**になり、送る値が古い id のままなので規則が断り、
 * **サイト名のような無関係な項目まで保存できなくなる**。画面は英語で
 * 「選択された値は正しくありません」としか言わない。
 *
 * ▷ ここで見るのは 3 つ:
 *   1. Select が**空白ではなく「選ばない」**を見せる
 *   2. **無関係な項目（サイト名）が保存できる**
 *   3. 何が起きたかが**日本語で画面に出ている**
 *
 * ## 標準スイートへは入れない
 *
 * ページを作って公開してゴミ箱へ入れ、サイト設定を書き換える。
 * **使い捨てプロジェクト専用**の隔離レーンに置く（標準レーンの global setup は
 * `migrate:fresh` を走らせるため、稼働中の環境へ向けてはならない）。
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/front-page.isolated.spec.ts
 * ```
 */

function isolatedTarget(): { appUrl: string; composeCwd: string } {
    const appUrl = process.env.APP_URL;
    const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

    if (!appUrl || !composeCwd) {
        throw new Error(
            'APP_URL と AGENTCMS_COMPOSE_CWD を明示してください（使い捨てプロジェクト専用の spec です）',
        );
    }

    let parsed: URL;

    try {
        parsed = new URL(appUrl);
    } catch {
        throw new Error(`APP_URL を URL として読めません: ${appUrl}`);
    }

    if (parsed.protocol !== 'http:') {
        throw new Error(
            `手元の使い捨て以外へは向けられません（http のみ）: ${appUrl}`,
        );
    }

    if (!['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname)) {
        throw new Error(`手元のホストではありません: ${parsed.hostname}`);
    }

    if (parsed.port === '' || parsed.port === '8180') {
        throw new Error(`使い捨て専用の port を明示してください: ${appUrl}`);
    }

    if (resolve(composeCwd) === resolve(process.cwd())) {
        throw new Error(`repo 自身を対象にできません: ${composeCwd}`);
    }

    for (const marker of [
        '.agentcms/local/admin-credentials.json',
        'docker-compose.yml',
        '.env',
    ]) {
        if (!existsSync(join(composeCwd, marker))) {
            throw new Error(
                `生成プロジェクトに見えません（${marker} が無い）: ${composeCwd}`,
            );
        }
    }

    return { appUrl, composeCwd };
}

const { composeCwd } = isolatedTarget();

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

/** 公開できるページを 1 枚つくる（section > heading があれば公開できる） */
async function createPublishedPage(page: Page, slug: string): Promise<string> {
    const title = `トップ候補 ${slug}`;

    await page.goto('/admin/pages/create');
    await page.fill('#page-title', title);
    await page.fill('#page-slug', slug);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/pages/*/edit');

    await page.getByTestId('page-editor-link').click();
    await page.getByTestId('palette-section').click();
    await page.getByTestId('layer-section').click();
    await page.getByTestId('palette-heading').click();
    await page.getByTestId('layer-heading').first().click();
    await page.getByTestId('inspector-prop-text').fill('ページのトップ');

    await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
    await page.waitForURL('**/admin/pages/*/edit');

    await page.getByTestId('page-publish-button').click();
    await expect(page.getByTestId('page-status-badge')).toHaveText('公開中');

    return title;
}

const NOTICE = 'トップページに指定したページがゴミ箱にあります';

test('トップに指定したページを出せなくしても、サイト設定は使えたままになる', async ({
    page,
}) => {
    await login(page);

    const slug = `front-${Date.now()}`;
    const title = await createPublishedPage(page, slug);
    const pageUrl = page.url();

    // ── トップページに指定する ──────────────────────────
    await page.goto('/admin/site');

    /*
     * **生成直後のサイト設定は空である。** `site_name` は必須なので、
     * 埋めずに保存すると**この spec は必ず落ちる**（見たいものへ辿り着けない）。
     * 人が最初にやる順序（名前を入れる → 保存する）と同じにしておく。
     */
    const siteName = `テストサイト ${Date.now()}`;

    await page.fill('#site_name', siteName);
    await page.locator('#front_page_id').click();
    await page.getByRole('option', { name: title }).click();
    await page.getByRole('button', { name: '保存' }).click();

    // 保存されたことを、**読み直して**確かめる（画面の表示だけでは足りない）
    await page.goto('/admin/site');
    await expect(page.locator('#front_page_id')).toContainText(title);

    // ── 出せない状態にする ────────────────────────────
    await page.goto(pageUrl);
    await page.getByTestId('page-trash-button').click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'ゴミ箱へ移す' })
        .click();

    /*
     * **ゴミ箱へ移すと一覧へ戻る。** 遷移を待たずに badge を見ると、
     * まだ表示されている前の画面か、一覧の**別のページ**の badge を読んでしまう。
     */
    await page.waitForURL('**/admin/pages');
    await page.goto(pageUrl);
    await expect(page.getByTestId('page-status-badge')).toHaveText('ゴミ箱');

    // ── ここからが監査 指摘 1 の本体 ────────────────────
    await page.goto('/admin/site');

    // (3) 何が起きたかが日本語で出ている
    await expect(page.getByText(NOTICE)).toBeVisible();

    /*
     * (1) **空白ではなく「選ばない」。**
     *
     * 候補に無い id をそのまま Select へ渡すと、Radix は該当する項目を見つけられず
     * **何も表示しない**。利用者からは「壊れている」のか「未設定」なのかも分からない。
     */
    await expect(page.locator('#front_page_id')).toContainText('選ばない');

    /*
     * (2) **無関係な項目が保存できる。**
     *
     * ここが落ちると、トップページと関係の無い作業まで止まる。
     * 画面が「選ばない」を見せている以上、送る値も null でなければならない。
     */
    const renamed = `サイト名 ${Date.now()}`;

    await page.fill('#site_name', renamed);
    await page.getByRole('button', { name: '保存' }).click();

    await page.goto('/admin/site');
    await expect(page.locator('#site_name')).toHaveValue(renamed);

    // トップページの指定は外れている（注意書きが予告したとおりの結果になる）
    await expect(page.getByText(NOTICE)).toHaveCount(0);
    await expect(page.locator('#front_page_id')).toContainText('選ばない');
});
