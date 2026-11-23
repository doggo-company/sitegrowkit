import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 投稿タイプ側のコメント利用可否が **画面として成立しているか**
 * （Phase 9.7 / STAGE 20E の受入）。
 *
 * ここで見たいのは Feature テストでは出ない事故である:
 *
 * - 切替を押したときに、**設定の保存フォームが一緒に送信されない**こと
 * - 確認の前に 1 行も書かないこと
 * - ON / OFF が保たれ、OFF にしても他のデータが消えないこと
 * - **受け付けるフォームや route がどこにも無い**こと
 * - 画面に生の `**` が出ないこと
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8182 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/content-type-comments.isolated.spec.ts
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

function artisan(code: string): string {
    return execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            code,
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );
}

function settingsRows(): string {
    return artisan(
        'echo \\Illuminate\\Support\\Facades\\DB::table("content_types")->count();',
    ).trim();
}

function postRows(): string {
    return artisan(
        'echo \\Illuminate\\Support\\Facades\\DB::table("post")->count();',
    ).trim();
}

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

test.beforeEach(() => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    // 毎回「設定行なし = 既定 OFF」から始める
    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("content_types")->delete();',
    );
});

test('確認を経て ON / OFF でき、設定の保存は一緒に飛ばない', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/content-types/post/edit');

    await expect(page.getByTestId('content-type-comments-state')).toContainText(
        '使いません',
    );

    // 送信を数える。切替では **設定保存（PATCH）が飛んではいけない**
    const patched: string[] = [];
    page.on('request', (r) => {
        if (r.method() === 'PATCH') {
            patched.push(r.url());
        }
    });

    await page.getByTestId('content-type-comments-enable').click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    // 実装済みの受付について、承認までは公開されないことを明示する。
    await expect(dialog).toContainText('承認するまで公開ページには出ません');

    // 確認の前は 1 行も書かない
    expect(settingsRows()).toBe('0');

    await dialog.getByTestId('operation-confirm-button').click();
    await page.waitForURL(/\/admin\/content-types$/);

    expect(patched, '切替で設定保存が同時に飛んでいる').toEqual([]);

    // ON が保たれている
    await page.goto('/admin/content-types/post/edit');
    await expect(page.getByTestId('content-type-comments-state')).toContainText(
        '使えます',
    );

    // OFF へ戻す。他のデータは消えない
    const postsBefore = postRows();

    await page.getByTestId('content-type-comments-disable').click();
    const offDialog = page.getByRole('dialog');
    await expect(offDialog).toContainText('削除されません');
    await offDialog.getByTestId('operation-confirm-button').click();
    await page.waitForURL(/\/admin\/content-types$/);

    await page.goto('/admin/content-types/post/edit');
    await expect(page.getByTestId('content-type-comments-state')).toContainText(
        '使いません',
    );
    expect(postRows()).toBe(postsBefore);
});

test('受付の説明が読め、独立したコメント一覧を公開しない', async ({ page }) => {
    await login(page);
    await page.goto('/admin/content-types/post/edit');

    // 説明文に生の ** が出ていない
    const body = (await page.locator('body').innerText()) ?? '';
    expect(body).not.toContain('**');
    expect(body).toContain('届いたコメントは承認するまで公開ページには出ません');

    // 公開側にコメントの受付が無い
    await page.goto('/');
    expect(await page.locator('form[action*="comment"]').count()).toBe(0);

    const response = await page.goto('/comments');
    expect(response?.status()).toBe(404);
});

test('投稿タイプの設定画面に a11y の critical / serious が無い', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/content-types/post/edit');

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const blocking = results.violations.filter((violation) =>
        ['critical', 'serious'].includes(violation.impact ?? ''),
    );

    expect(
        blocking.map((violation) => violation.id),
        JSON.stringify(blocking, null, 2),
    ).toEqual([]);
});
