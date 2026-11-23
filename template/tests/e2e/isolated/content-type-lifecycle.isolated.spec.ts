import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 投稿タイプの無効化・復元・並べ替えが **画面として成立しているか**
 * （Phase 9.7 / STAGE 20C）。
 *
 * Feature テストは経路・認可・楽観ロック・監査を担保する。ここで見たいのはそれではなく、
 * 実際にログインして辿り着けるか — 無効にすると管理メニューから消えるか、
 * 一覧には残って戻せるか、**キーボードだけで並べ替えられるか**、
 * 並びが変わったことが読み上げられるか、表示名に仕込んだ script が実行されないか、まで。
 *
 * 標準スイート（`playwright.config.ts`）へは入れない。この spec は
 * 投稿タイプの状態と並び順を書き換えるので、件数と並びを前提にした回帰を壊す。
 *
 * 並べ替えは 1 件では試せないが、**足りなければこの spec が管理画面から 2 件目を作る**
 * （確認 → 適用の正規の経路。裏から DB や schema を差し込まない）。
 * つまり生成直後の site をそのまま対象にしてよい。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8183 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/content-type-lifecycle.isolated.spec.ts
 * ```
 */

/** 表示名へ仕込む script。**文字として出る**ことを確かめるための餌 */
const XSS_LABEL = '<script>window.__xss = true;</script>ブログ';

/**
 * **使い捨てのプロジェクトでしか動かさない。**
 *
 * この spec は投稿タイプを無効にし、並び順を書き換える。
 * isolated config の既定（`APP_URL` 未指定で 8180、`AGENTCMS_COMPOSE_CWD` 未指定で
 * カレント）に乗ると、**稼働中のローカル環境をそのまま壊す**。
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

function artisan(code: string): string {
    return execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute', code],
        { cwd: composeCwd, encoding: 'utf8' },
    );
}

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

/**
 * 1 つの型の行。**表示文字で探さない** — 「投稿一覧」ボタンがどの行にもあるので、
 * 「投稿」で絞ると別の型の行を掴む。内部 ID で引く。
 */
function rowFor(page: Page, contentTypeId: string) {
    return page.locator(`[data-content-type="${contentTypeId}"]`);
}

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

test.beforeEach(async () => {
    // ログインの回数制限をならす（実装は正しいのに throttle で落ちるのを避ける）
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    // 毎回同じ地点から始める。設定行を消せば「既定のまま」に戻る
    artisan('\\Illuminate\\Support\\Facades\\DB::table("content_types")->delete();');
});

test('無効にすると管理メニューから消え、一覧からは戻せる', async ({ page }) => {
    await login(page);
    await page.goto('/admin/content-types');

    const sidebarLink = page.getByRole('link', { name: '投稿', exact: true });
    await expect(sidebarLink).toBeVisible();

    await rowFor(page, 'post').getByTestId('content-type-archive').click();

    // ブラウザの confirm ではなく、サーバーが出した確認を通る
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('元に戻せます');
    await dialog.getByTestId('operation-confirm-button').click();

    await expect(
        rowFor(page, 'post').getByTestId('content-type-status'),
    ).toContainText('無効');

    // 日常運用の導線からは外れるが、一覧には残る
    await expect(page.getByRole('link', { name: '投稿', exact: true })).toHaveCount(0);

    await rowFor(page, 'post').getByTestId('content-type-restore').click();

    await expect(page.getByRole('link', { name: '投稿', exact: true })).toBeVisible();
});

/**
 * 2 件目の投稿タイプを **管理画面の正規の経路**（確認 → 適用）で作る。
 *
 * 生成直後のサイトは「投稿」1 つなので、並べ替えはそのままでは試せない。
 * かといって DB や schema を裏から差し込んで数を増やすと、**利用者が通る道**を
 * 通らないまま「並べ替えられた」と言うことになる。作るところから画面で行う。
 */
async function createContentTypeThroughAdmin(
    page: Page,
    id: string,
    label: string,
): Promise<void> {
    await page.goto('/admin/content-types/create');

    await page.locator('#label').fill(label);
    await page.locator('#id').fill(id);

    // まず確認。ここまででファイルも DB も変わっていない
    await page.getByRole('button', { name: '内容を確認する' }).click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();

    await dialog.getByTestId('operation-confirm-button').click();

    await page.waitForURL(/\/admin\/content-types$/);
    await expect(rowFor(page, id)).toBeVisible();
}

test('キーボードだけで並べ替えられ、結果が読み上げられる', async ({ page }) => {
    await login(page);

    /*
     * **飛ばさない。** 1 件しか無いなら 2 件目を作ってから試す（STAGE 20F 是正）。
     * 「件数が足りないので確認できませんでした」は、確認したことにならない。
     */
    await page.goto('/admin/content-types');

    if ((await page.getByTestId('content-type-row').count()) < 2) {
        await createContentTypeThroughAdmin(page, 'essay', 'コラム');
    }

    const rows = page.getByTestId('content-type-row');
    await expect(rows).toHaveCount(2);

    const secondLabel = (await rows.nth(1).locator('td').first().innerText()).trim();

    // マウスを使わない。Tab で「上へ」まで到達し、Enter で動かす
    const moveUp = rows.nth(1).getByTestId('content-type-move-up');
    await moveUp.focus();
    await expect(moveUp).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('content-types-live')).toContainText(
        '1 番目に移動しました',
    );

    const firstLabel = await page
        .getByTestId('content-type-row')
        .first()
        .locator('td')
        .first()
        .innerText();

    expect(firstLabel.trim()).toBe(secondLabel);

    // 並びは保存されている（読み込み直しても同じ）
    await page.reload();
    await expect(
        page.getByTestId('content-type-row').first().locator('td').first(),
    ).toContainText(secondLabel);
});

test('設定画面では内部 ID を変えられず、項目編集はカスタムフィールドへ送られる', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/content-types/post/edit');

    // 内部 ID は表示だけ（入力欄が無い）
    await expect(page.getByTestId('content-type-id')).toContainText('post');
    await expect(page.locator('input[name="id"]')).toHaveCount(0);

    // 基本項目は読み取り専用で、項目を足す UI はこの画面に無い
    // アイキャッチ・抜粋を含む標準項目は、ここでは構造を変更しない。
    await expect(page.getByTestId('content-type-base-field')).toHaveCount(7);
    await expect(page.getByTestId('content-type-field-groups-link')).toBeVisible();

    await page.getByTestId('content-type-label').fill(XSS_LABEL);
    await page.getByTestId('content-type-public-slug').fill('blog');
    await page.getByRole('button', { name: '保存する' }).click();

    await page.waitForURL(/\/admin\/content-types$/);

    // 表示名の script は **文字として**出る
    await expect(page.getByText(XSS_LABEL).first()).toBeVisible();
    expect(await page.evaluate(() => '__xss' in window)).toBe(false);
    await expect(rowFor(page, 'post').getByTestId('content-type-slug')).toContainText(
        'blog',
    );
});

test('投稿タイプの画面に a11y の critical / serious が無い', async ({ page }) => {
    await login(page);

    for (const path of ['/admin/content-types', '/admin/content-types/post/edit']) {
        await page.goto(path);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const blocking = results.violations.filter((violation) =>
            ['critical', 'serious'].includes(violation.impact ?? ''),
        );

        expect(
            blocking.map((violation) => `${path}: ${violation.id}`),
            JSON.stringify(blocking, null, 2),
        ).toEqual([]);
    }
});
