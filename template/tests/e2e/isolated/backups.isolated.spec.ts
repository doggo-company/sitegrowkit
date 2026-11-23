import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * backup が **画面として成立しているか**（Phase 9.11 / STAGE 24E の受入）。
 *
 * Feature テストは取得・検証・復元の中身を担保する。ここで見たいのはそれではなく、
 * 実際にコマンドで取った backup が画面に出るか、押す前に「戻せるか」を確かめられるか、
 * 復元すると本当に戻って**ログインが切れる**か、まで。
 *
 * 標準スイートへは入れない。この spec は DB を丸ごと書き換える。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8191 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/backups.isolated.spec.ts
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
        throw new Error(`手元の使い捨て以外へは向けられません（http のみ）: ${appUrl}`);
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

    for (const marker of ['.agentcms/local/admin-credentials.json', 'docker-compose.yml', '.env']) {
        if (!existsSync(join(composeCwd, marker))) {
            throw new Error(`生成プロジェクトに見えません（${marker} が無い）: ${composeCwd}`);
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
    const raw = readFileSync(`${composeCwd}/.agentcms/local/admin-credentials.json`, 'utf8');

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
        .filter((violation) => ['critical', 'serious'].includes(violation.impact ?? ''))
        .flatMap((violation) =>
            violation.nodes.map((node) => `${violation.id}: ${node.target.join(' ')}`),
        );
}


/** backup を 1 つ取る（CLI 経路の非回帰） */
function takeBackup(): void {
    execFileSync('docker', ['compose', 'exec', '-T', '-u', 'app', 'app', 'php', 'artisan', 'agentcms:backup'], {
        cwd: composeCwd,
        encoding: 'utf8',
    });
}

test.beforeEach(async () => {
    execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'], {
        cwd: composeCwd,
        encoding: 'utf8',
    });

    // 毎回同じ地点から始める（backup も投稿も消す）
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("post")->delete();
        foreach (glob(storage_path("app/private/backups/*")) as $path) {
            is_dir($path) ? array_map("unlink", glob($path."/*")) : null;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
    `);
});

test('画面から取得を始め、実際の別プロセスの完了を確認できる', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto('/admin/backups');
    const started = Date.now();
    await page.getByTestId('backup-create').click();
    const run = page.getByTestId('backup-run');
    await expect(run).toBeVisible();
    // HTTP は取得完了を待たずに返り、画面上で続きの状態を確認できる。
    expect(Date.now() - started).toBeLessThan(15000);
    await expect.poll(async () => run.getAttribute('data-run-status'), { timeout: 60000 }).toMatch(/^(done|failed)$/);
    expect(await run.getAttribute('data-run-status'), await run.innerText()).toBe('done');
    await expect(page.getByTestId('backup-row')).toHaveCount(1);
    await page.reload();
    await expect(page.getByTestId('backup-run')).toHaveAttribute('data-run-status', 'done');
    await page.getByTestId('backup-verify').click();
    await expect(page.getByTestId('backup-verified')).toBeVisible();
});

test('取得したbackupが画面に出る', async ({ page }) => {
    await login(page);
    await page.goto('/admin/backups');

    await expect(page.getByTestId('backup-empty')).toBeVisible();

    // CLI でも取得できる。画面には代替手順としてコマンドが出ている
    await expect(page.getByTestId('backup-command')).toContainText('php artisan agentcms:backup');

    takeBackup();

    await page.reload();
    await expect(page.getByTestId('backup-row')).toHaveCount(1);
});

test('押す前に戻せるか確かめられる', async ({ page }) => {
    takeBackup();

    await login(page);
    await page.goto('/admin/backups');

    await page.getByTestId('backup-verify').click();

    // 301 と同じで、復元は取り返しがつかない。押す前に分かるようにする
    await expect(page.getByTestId('backup-verified')).toBeVisible();
});

test('復元すると取得時点に戻り、ログインが切れる', async ({ page }) => {
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("post")->insert([
            "title" => "backup 時点の記事", "slug" => "kept", "body" => "<p>本文</p>",
            "status" => "draft", "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    takeBackup();

    // 古いbackupの世代+1と現在世代が一致しても、復元でログインが切れる。
    artisan(`\\Illuminate\\Support\\Facades\\DB::table("users")->increment("session_epoch");`);

    // そのあとで壊す
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("post")->delete();
        \\Illuminate\\Support\\Facades\\DB::table("post")->insert([
            "title" => "あとから足した記事", "slug" => "later", "body" => "<p>本文</p>",
            "status" => "draft", "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await login(page);
    await page.goto('/admin/backups');

    await page.getByTestId('backup-restore').click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    // 同じ件数でも、戻すと失われる記事を具体的に知らせる。
    await expect(dialog).toContainText('あとから足した記事');
    await expect(dialog).not.toContainText('変更はありません');
    await expect(dialog).toContainText('ログインが切れます');

    await dialog.getByTestId('operation-confirm-button').click();

    // 自分の session も切れているので、ログイン画面へ送られる
    await page.waitForURL(/\/login$/);

    // 本当に戻っている
    const titles = artisan('echo \\Illuminate\\Support\\Facades\\DB::table("post")->pluck("title")->implode(",");');
    expect(titles).toContain('backup 時点の記事');
    expect(titles).not.toContain('あとから足した記事');
});

test('復元すると復元前backupが増えている', async ({ page }) => {
    takeBackup();

    await login(page);
    await page.goto('/admin/backups');
    await expect(page.getByTestId('backup-row')).toHaveCount(1);

    await page.getByTestId('backup-restore').click();
    await page.getByTestId('operation-confirmation').getByTestId('operation-confirm-button').click();
    await page.waitForURL(/\/login$/);

    await login(page);
    await page.goto('/admin/backups');

    // 事故のあとに戻る先を残す（OG6）
    await expect(page.getByTestId('backup-row')).toHaveCount(2);
    await expect(page.getByTestId('backup-pre-restore')).toHaveCount(1);
});

test('backup は web から直接取れない', async ({ page }) => {
    takeBackup();

    const id = artisan(
        'echo basename((string) (glob(storage_path("app/private/backups/*.zip"))[0] ?? ""), ".zip");',
    ).trim();

    expect(id).not.toBe('');

    // **URL を推測されただけで全データが持ち去られる場所に置かない**（SPEC §G.1）
    for (const path of [
        `/storage/backups/${id}.zip`,
        `/backups/${id}.zip`,
        `/storage/app/private/backups/${id}.zip`,
    ]) {
        const response = await page.request.get(path, { maxRedirects: 0 });

        expect(response.status(), path).not.toBe(200);
    }
});

test('持ち出しは認証が要る', async ({ page }) => {
    takeBackup();

    const id = artisan(
        'echo basename((string) (glob(storage_path("app/private/backups/*.zip"))[0] ?? ""), ".zip");',
    ).trim();

    const response = await page.request.get(`/admin/backups/${id}/download`, { maxRedirects: 0 });

    // 未認証では落とせない
    expect(response.status()).toBe(302);
});

test('backup の画面に a11y の critical / serious が無い', async ({ page }) => {
    takeBackup();

    await login(page);
    await page.goto('/admin/backups');

    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);
});

test('狭い画面でも操作が欠けない', async ({ page }) => {
    takeBackup();

    await page.setViewportSize({ width: 390, height: 780 });
    await login(page);
    await page.goto('/admin/backups');

    await expect(page.getByTestId('backup-command')).toBeVisible();
    await expect(page.getByTestId('backup-verify')).toBeVisible();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
});
