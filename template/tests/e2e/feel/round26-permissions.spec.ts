import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';

test.use({
    trace: 'off',
    viewport: { width: 1366, height: 768 },
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

function tinker(code: string): string {
    try {
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
            {
                cwd: process.env.AGENTCMS_FEEL_PROJECT ?? process.cwd(),
                encoding: 'utf8',
                timeout: 30000,
                stdio: ['pipe', 'pipe', 'pipe'],
            },
        ).trim();
    } catch {
        // 引数に一時的な招待トークンを含むので、子プロセス例外をそのまま出さない。
        throw new Error('round25 の試験準備に失敗しました（artisan）');
    }
}

test('編集者のサイト設定はメニューに無く、直接開くと読むだけ', async ({
    browser,
}, info) => {
    const email = `editor26-${Date.now()}@example.test`;
    const password = `Aa1!${randomBytes(18).toString('hex')}`;
    tinker(
        `$u = new \\App\\Models\\User; $u->forceFill(['name'=>'編集者','email'=>'${email}','email_verified_at'=>now(),'password'=>\\Illuminate\\Support\\Facades\\Hash::make('${password}'),'role'=>'editor'])->save();`,
    );
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();

    try {
        await page.goto('/login');
        await page.locator('input[type=email]').fill(email);
        await page.locator('input[type=password]').fill(password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/dashboard');
        await page.screenshot({
            path: info.outputPath('editor-dashboard.png'),
            fullPage: true,
        });

        await expect(page.locator('a[href="/admin/site"]')).toHaveCount(0);
        await expect(page.locator('a[href="/admin/menus"]')).toHaveCount(0);

        for (const width of [1366, 390]) {
            await page.setViewportSize({ width, height: 844 });
            await page.goto('/admin/site');
            await page.screenshot({
                path: info.outputPath(`editor-site-${width}.png`),
                fullPage: true,
            });
            await expect(
                page.getByText(
                    'この設定はオーナー・制作者・管理者だけが変えられます',
                    {
                        exact: true,
                    },
                ),
            ).toBeVisible();

            for (const input of await page
                .locator(
                    'main form input, main form textarea, main form select',
                )
                .all()) {
                await expect(input).toBeDisabled();
            }

            await expect(page.getByRole('textbox').first()).toBeVisible();
            await expect(page.locator('main [inert]')).toHaveCount(0);
            await expect(
                page.locator('main form button[type=submit]:visible'),
            ).toHaveCount(0);
        }

        await page.goto('/admin/taxonomies');
        await expect(
            page.locator('main a[href="/admin/taxonomies/create"]'),
        ).toHaveCount(0);
        await page.goto('/admin/taxonomies/create');
        await expect(page.getByTestId('admin-read-only-notice')).toBeVisible();
        await expect(page.getByTestId('taxonomy-label')).toBeDisabled();
        await expect(
            page.locator('main form button[type=submit]:visible'),
        ).toHaveCount(0);
        await page.screenshot({
            path: info.outputPath('editor-taxonomy-create.png'),
            fullPage: true,
        });
        await page.goto('/admin/notifications');
        await expect(page.getByTestId('admin-read-only-notice')).toHaveCount(0);
    } finally {
        await context.close();
    }
});

test('閲覧者は記事の次ページと本文を読めるが変更できない', async ({
    browser,
}, info) => {
    const email = `viewer26-${Date.now()}@example.test`;
    const password = `Aa1!${randomBytes(18).toString('hex')}`;
    tinker(
        `$u = new \\App\\Models\\User; $u->forceFill(['name'=>'閲覧者','email'=>'${email}','email_verified_at'=>now(),'password'=>\\Illuminate\\Support\\Facades\\Hash::make('${password}'),'role'=>'viewer'])->save(); for($i=0;$i<25;$i++){\\Illuminate\\Support\\Facades\\DB::table('post')->insert(['title'=>'閲覧順序確認 '.$i,'slug'=>'viewer26-'.\\Illuminate\\Support\\Str::random(15),'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);}`,
    );
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();

    try {
        await page.goto('/login');
        await page.locator('input[type=email]').fill(email);
        await page.locator('input[type=password]').fill(password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/dashboard');
        await page.goto('/admin/post');
        await page.locator('main a[href*="page=2"]').first().click();
        await expect(page).toHaveURL(/page=2/);
        await page.screenshot({
            path: info.outputPath('viewer-page2.png'),
            fullPage: true,
        });
        await page.getByTestId('row-title-link').first().click();
        await expect(page).toHaveURL(/\/admin\/post\/\d+\/edit/);
        await expect(page.getByTestId('admin-read-only-notice')).toBeVisible();

        for (const input of await page
            .locator('main form input, main form textarea')
            .all()) {
            await expect(input).toBeDisabled();
        }

        await expect(
            page.locator('main form button[type=submit]:visible'),
        ).toHaveCount(0);
        await page.screenshot({
            path: info.outputPath('viewer-entry.png'),
            fullPage: true,
        });
        const bodyId = Number(
            tinker(
                `echo \\Illuminate\\Support\\Facades\\DB::table('post')->insertGetId(['title'=>'読む本文の確認','slug'=>'readonly-richtext-${Date.now()}','body'=>'<p>読む本文の確認</p>','status'=>'draft','created_at'=>now(),'updated_at'=>now()]);`,
            ),
        );
        const richtextWrites: string[] = [];
        page.on('request', (request) => {
            if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(request.method())) {
                richtextWrites.push(
                    request.method() + ' ' + new URL(request.url()).pathname,
                );
            }
        });
        await page.goto(`/admin/post/${bodyId}/edit`);
        const tiptap = page.locator('main .tiptap');
        await expect(tiptap).toHaveCount(1);
        await expect(tiptap).toHaveAttribute('contenteditable', 'false');
        await expect(tiptap).toContainText('読む本文の確認');
        await tiptap.click();
        await page.keyboard.type('書き換えない');
        await page.keyboard.press('Backspace');
        await page.waitForTimeout(1200);
        await expect(tiptap).toContainText('読む本文の確認');
        await expect(tiptap).not.toContainText('書き換えない');
        expect(richtextWrites).toEqual([]);
        await page.screenshot({
            path: info.outputPath('viewer-richtext.png'),
            fullPage: true,
        });
        await page.goto('/admin/media');
        await expect(page.getByTestId('media-upload-form')).toHaveCount(0);
        const mediaTab = page.getByTestId('media-tab-image');

        if (await mediaTab.count()) {
            await mediaTab.click();
        }

        await page.goto('/admin/pages');
        await expect(page.getByTestId('page-title-link').first()).toBeVisible();
        await page.getByTestId('page-title-link').first().click();
        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit/);
        await expect(page.getByTestId('admin-read-only-notice')).toBeVisible();
        const editorId = Number(
            tinker(
                "echo \\AgentCms\\Core\\Pages\\Page::query()->where('managed_by','document')->whereNotNull('draft_version_id')->whereNull('trashed_at')->value('id');",
            ),
        );
        expect(editorId).toBeGreaterThan(0);
        const writes: string[] = [];
        page.on('request', (request) => {
            if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(request.method())) {
                writes.push(
                    request.method() + ' ' + new URL(request.url()).pathname,
                );
            }
        });
        await page.goto(`/admin/pages/${editorId}/editor`);
        await expect(page.getByTestId('admin-read-only-notice')).toBeVisible();
        await expect(page.locator('main [contenteditable="true"]')).toHaveCount(
            0,
        );
        await page.locator('main').click({ position: { x: 10, y: 10 } });
        await page.keyboard.type('書き換えない確認');
        await page.keyboard.press('Backspace');
        await page.waitForTimeout(1200);
        expect(writes).toEqual([]);
        await page.screenshot({
            path: info.outputPath('viewer-page-editor.png'),
            fullPage: true,
        });
    } finally {
        await context.close();
    }
});

test('rc33 dashboard permission links and forbidden notice', async ({
    browser,
}, info) => {
    for (const role of ['editor', 'owner']) {
        const email = `rc33-${role}-${Date.now()}@example.test`;
        const password = `Aa1!${randomBytes(18).toString('hex')}`;
        tinker(
            `$u = new \\App\\Models\\User; $u->forceFill(['name'=>'${role}','email'=>'${email}','email_verified_at'=>now(),'password'=>\\Illuminate\\Support\\Facades\\Hash::make('${password}'),'role'=>'${role}'])->save();`,
        );
        const context = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const page = await context.newPage();

        try {
            await page.goto('/login');
            await page.locator('input[type=email]').fill(email);
            await page.locator('input[type=password]').fill(password);
            await page.locator('button[type=submit]').click();
            await page.waitForURL('**/dashboard');

            if (role === 'editor') {
                const step = page.getByTestId('getting-started-item').nth(4);
                await page.screenshot({
                    path: info.outputPath('editor-dashboard-before-click.png'),
                    fullPage: true,
                });
                console.log(
                    'RC33_DASHBOARD',
                    JSON.stringify({
                        step: await step.innerText(),
                        links: await step.locator('a').count(),
                    }),
                );
                expect.soft(await step.locator('a').count()).toBe(0);
                await expect.soft(step).toContainText('権限がありません');
                const link = step.locator('a');

                if (await link.count()) {
                    await link.click();
                    await expect(page).toHaveURL(/\/admin\/readiness/);
                }
            }

            for (const path of role === 'editor'
                ? ['/admin/readiness', '/admin/exports']
                : ['/admin/content-types']) {
                const response = await page.goto(path);
                await page.screenshot({
                    path: info.outputPath(
                        `${role}-${path.split('/').pop()}-403.png`,
                    ),
                    fullPage: true,
                });
                console.log(
                    'RC33_FORBIDDEN',
                    JSON.stringify({
                        role,
                        path,
                        status: response?.status(),
                        notice: await page
                            .getByTestId('admin-read-only-notice')
                            .count(),
                        body: await page.locator('main').innerText(),
                    }),
                );
                expect.soft(response?.status()).toBe(403);
                await expect
                    .soft(page.getByTestId('admin-read-only-notice'))
                    .toHaveCount(0);
                await expect(
                    page.getByRole('heading', {
                        name: 'この画面は見られません',
                    }),
                ).toBeVisible();
            }

            if (role === 'editor') {
                for (const width of [1366, 390]) {
                    await page.setViewportSize({ width, height: 844 });
                    await page.goto('/dashboard');
                    await page.screenshot({
                        path: info.outputPath(`editor-dashboard-${width}.png`),
                        fullPage: true,
                    });
                    await page.goto('/admin/site');
                    await page.screenshot({
                        path: info.outputPath(`editor-readonly-${width}.png`),
                        fullPage: true,
                    });
                    await expect(
                        page.getByTestId('admin-read-only-notice'),
                    ).toBeVisible();
                    await expect(
                        page.getByRole('textbox').first(),
                    ).toBeDisabled();
                }
            }
        } finally {
            await context.close();
        }
    }
});
