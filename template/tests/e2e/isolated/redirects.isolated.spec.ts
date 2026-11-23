import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 転送と 404 の記録が **画面として成立しているか**（Phase 9.10 / STAGE 23E の受入）。
 *
 * Feature テストは経路・認可・確認・楽観ロックを担保する。ここで見たいのはそれではなく、
 * 実際にブラウザが転送に従うか — 301 が返って本当に移動するか、
 * 管理画面から作ったルールがすぐ効くか、404 の記録から 1 操作で直せるか、
 * シミュレータが**踏まずに**答えるか、まで。
 *
 * 標準スイートへは入れない。この spec は転送ルールと記録を書き換える。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/redirects.isolated.spec.ts
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

/** critical / serious だけを、どの要素かも添えて返す */
async function blocking(builder: AxeBuilder): Promise<string[]> {
    const results = await builder
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    return results.violations
        .filter((violation) =>
            ['critical', 'serious'].includes(violation.impact ?? ''),
        )
        .flatMap((violation) =>
            violation.nodes.map(
                (node) => `${violation.id}: ${node.target.join(' ')}`,
            ),
        );
}

test.beforeEach(async () => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        {
            cwd: composeCwd,
            encoding: 'utf8',
        },
    );

    // 毎回同じ地点から始める
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("redirect_rules")->delete();
        \\Illuminate\\Support\\Facades\\DB::table("not_found_logs")->delete();
    `);
});

test('管理画面で作ったルールがすぐ効く', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    await expect(page.getByTestId('redirect-empty')).toBeVisible();

    await page.getByLabel('転送元のパス').fill('/old-page');
    await page.getByLabel('転送先').fill('/');
    await page.getByTestId('redirect-save').click();

    await expect(page.getByTestId('redirect-row')).toHaveCount(1);

    // **ブラウザが実際に転送に従う**
    await page.goto('/old-page');
    await expect(page).toHaveURL(/\/$/);
});

test('転送は 301 で返る', async ({ page }) => {
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("redirect_rules")->insert([
            "match_type" => "exact", "source_path" => "/gone",
            "source_hash" => hash("sha256", "exact|/gone"),
            "target_url" => "/", "status_code" => 301, "is_enabled" => true,
            "keep_query" => true, "created_reason" => "manual", "hit_count" => 0,
            "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    const response = await page.request.get('/gone', { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    expect(response.headers()['location']).toContain('/');
});

test('ルールが無ければ 404 のまま', async ({ page }) => {
    const response = await page.request.get('/definitely-missing', {
        maxRedirects: 0,
    });

    expect(response.status()).toBe(404);
});

test('管理画面のパスは転送されない', async ({ page }) => {
    // 保存経路は断るので、直接入れて「要求時にも効かない」ことを見る
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("redirect_rules")->insert([
            "match_type" => "prefix", "source_path" => "/admin",
            "source_hash" => hash("sha256", "prefix|/admin"),
            "target_url" => "/", "status_code" => 301, "is_enabled" => true,
            "keep_query" => true, "created_reason" => "manual", "hit_count" => 0,
            "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    // 転送されず、これまでどおりログイン画面へ送られる
    await page.goto('/admin/redirects');
    await expect(page).toHaveURL(/\/login$/);
});

test('シミュレータは踏まずに答える', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    await page.getByLabel('転送元のパス').fill('/old-page');
    await page.getByLabel('転送先').fill('/new-page');
    await page.getByTestId('redirect-save').click();
    await expect(page.getByTestId('redirect-row')).toHaveCount(1);

    await page.getByLabel('確かめる URL').fill('/old-page');
    await page.getByTestId('redirect-simulate').click();

    const result = page.getByTestId('redirect-simulate-result');
    await expect(result).toContainText('/new-page');

    // **踏んでいないのでヒット数は増えない**（301 を覚えさせない）
    await page.reload();
    await expect(page.getByTestId('redirect-row')).toContainText('0');
});

test('301 を選ぶと戻しにくいことを伝える', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    // 既定は 301。注意はその場で出る
    await expect(page.getByTestId('redirect-sticky-warning')).toBeVisible();

    await page.getByLabel('転送の種類').selectOption('302');
    await expect(page.getByTestId('redirect-sticky-warning')).toHaveCount(0);
});

test('危険な転送先は保存できない', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    // `//` はプロトコル相対 URL で、見た目はパスだが外部サイトへ飛ぶ
    await page.getByLabel('転送元のパス').fill('/trap');
    await page.getByLabel('転送先').fill('//example.com');
    await page.getByTestId('redirect-save').click();

    await expect(
        page.getByText(
            '「//」で始まる転送先は使えません（外部サイトへ飛びます）',
        ),
    ).toBeVisible();
    await expect(page.getByTestId('redirect-empty')).toBeVisible();
});

test('止めれば転送されなくなり、動かせば戻る', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    await page.getByLabel('転送元のパス').fill('/toggle-me');
    await page.getByLabel('転送先').fill('/');
    await page.getByTestId('redirect-save').click();
    await expect(page.getByTestId('redirect-row')).toHaveCount(1);

    expect(
        (await page.request.get('/toggle-me', { maxRedirects: 0 })).status(),
    ).toBe(301);

    // 消さずに止められる
    await page.getByTestId('redirect-toggle').click();
    await expect(page.getByText('停止中')).toBeVisible();

    expect(
        (await page.request.get('/toggle-me', { maxRedirects: 0 })).status(),
    ).toBe(404);

    await page.getByTestId('redirect-toggle').click();
    await expect(page.getByText('停止中')).toHaveCount(0);

    expect(
        (await page.request.get('/toggle-me', { maxRedirects: 0 })).status(),
    ).toBe(301);
});

test('404 の記録から 1 操作で直せる', async ({ page }) => {
    // 誰かが壊れたリンクを踏む
    await page.request.get('/broken-link', { maxRedirects: 0 });
    await page.request.get('/broken-link', { maxRedirects: 0 });

    await login(page);
    await page.goto('/admin/not-found');

    const row = page.getByTestId('not-found-row');
    await expect(row).toHaveCount(1);
    // 同じ URL は 1 行にまとまり、回数が増える
    await expect(row).toContainText('2');

    await page.getByTestId('not-found-resolve').click();
    await page.getByRole('textbox', { name: '転送先' }).fill('/');
    // 『対応済み』は絞り込みにもあるため、保存応答を待ってから公開URLを検証する。
    await Promise.all([
        page.waitForResponse(
            (response) =>
                /\/admin\/not-found\/\d+\/resolve$/.test(response.url()) &&
                response.request().method() === 'POST' &&
                response.status() === 302,
        ),
        page.getByTestId('not-found-save').click(),
    ]);

    await expect(page.getByText('対応済み')).toBeVisible();

    // 直したら本当に転送される
    expect(
        (await page.request.get('/broken-link', { maxRedirects: 0 })).status(),
    ).toBe(301);
});

test('管理画面の打ち間違いは記録に残らない', async ({ page }) => {
    await login(page);

    await page.request.get('/admin/nope', { maxRedirects: 0 });
    await page.request.get('/api/v1/nope', { maxRedirects: 0 });

    await page.goto('/admin/not-found');

    await expect(page.getByTestId('not-found-empty')).toBeVisible();
});

test('削除は確認を取ってから', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    await page.getByLabel('転送元のパス').fill('/remove-me');
    await page.getByLabel('転送先').fill('/');
    await page.getByTestId('redirect-save').click();
    await expect(page.getByTestId('redirect-row')).toHaveCount(1);

    await page.getByTestId('redirect-delete').click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    // どれだけ踏まれているかを出す（消す判断が変わる）
    await expect(dialog).toContainText('回転送しています');
    await expect(dialog).toContainText('無効');

    await dialog.getByTestId('operation-confirm-button').click();
    await expect(page.getByTestId('redirect-empty')).toBeVisible();
});

test('キーボードだけでルールを作れる', async ({ page }) => {
    await login(page);
    await page.goto('/admin/redirects');

    await page.getByLabel('転送元のパス').focus();
    await page.keyboard.type('/keyboard-only');
    await page.getByLabel('転送先').focus();
    await page.keyboard.type('/');

    const save = page.getByTestId('redirect-save');
    await save.focus();
    await expect(save).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('redirect-row')).toHaveCount(1);
});

test('リダイレクトの画面に a11y の critical / serious が無い', async ({
    page,
}) => {
    await page.request.get('/for-a11y', { maxRedirects: 0 });

    await login(page);

    await page.goto('/admin/redirects');
    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);

    await page.goto('/admin/not-found');
    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);
});

test('狭い画面でも操作が欠けない', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
    await login(page);
    await page.goto('/admin/redirects');

    await expect(page.getByTestId('redirect-simulate')).toBeVisible();
    await expect(page.getByTestId('redirect-save')).toBeVisible();

    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth >
            document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
});
