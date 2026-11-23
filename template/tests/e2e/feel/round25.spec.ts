import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

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

async function editor(page: Page): Promise<void> {
    await page.goto('/admin/pages/create');
    await page.getByTestId('page-title-input').fill(`競合の確認 ${Date.now()}`);
    await page.getByTestId('page-slug-input').fill(`conflict-${Date.now()}`);
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await page.getByTestId('page-editor-link').click();
    await page.getByTestId('editor-empty-start').click();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
}

async function conflict(page: Page, old: Page): Promise<void> {
    await editor(page);
    await old.goto(page.url());
    await old.getByTestId('layer-heading').first().click();
    await page.getByTestId('inspector-prop-text').fill('新しい保存内容');
    await page.getByTestId('inspector-prop-text').blur();
    await expect(page.getByTestId('editor-save-state')).toContainText(
        '保存済み',
    );
    await old.getByTestId('inspector-prop-text').fill('古いタブで残したい文');
    await old.getByTestId('inspector-prop-text').blur();
    await expect(old.getByTestId('editor-conflict')).toBeVisible();
}

test('#4 競合中は保存状態も保存できていませんと示す', async ({
    page,
    context,
}, info) => {
    const old = await context.newPage();

    try {
        await conflict(page, old);
        await old.screenshot({
            path: info.outputPath('conflict-status-before-assert.png'),
        });
        await expect(old.getByTestId('editor-save-state')).toHaveText(
            '保存できていません',
        );
        await expect(old.getByTestId('inspector-prop-text')).toBeDisabled();
        await old.waitForTimeout(1000);
        await expect(old.getByTestId('editor-save-state')).toHaveText(
            '保存できていません',
        );
        await old.screenshot({ path: info.outputPath('conflict-status.png') });
    } finally {
        await old.close();
    }
});

for (const width of [1366, 390]) {
    test(`#2 復元とコピーの操作の間に余白がある ${width}`, async ({
        page,
        context,
    }, info) => {
        await page.setViewportSize({ width, height: 844 });
        const old = await context.newPage();
        await old.setViewportSize({ width, height: 844 });

        try {
            await conflict(page, old);
            await old
                .getByRole('button', { name: '読み込み直す', exact: true })
                .click();
            await old.getByTestId('editor-confirm-reload').click();
            const recovery = old.getByTestId('editor-recovery');
            await expect(recovery).toBeVisible();
            const restore = await old
                .getByTestId('editor-restore-unsaved')
                .boundingBox();
            const copy = await recovery
                .getByRole('button', { name: '文をコピーする' })
                .boundingBox();
            expect(restore).not.toBeNull();
            expect(copy).not.toBeNull();
            const gap = Math.max(
                copy!.x - (restore!.x + restore!.width),
                copy!.y - (restore!.y + restore!.height),
            );
            await old.screenshot({
                path: info.outputPath(`recovery-before-assert-${width}.png`),
            });
            expect(gap).toBeGreaterThanOrEqual(12);
            await old.screenshot({
                path: info.outputPath(`recovery-${width}.png`),
            });
            await old.getByTestId('editor-restore-unsaved').click();
            await old.getByTestId('layer-heading').first().click();
            await expect(old.getByTestId('inspector-prop-text')).toHaveValue(
                '古いタブで残したい文',
            );
        } finally {
            await old.close();
        }
    });
}

test('#3 編集者が直接開くフォームは理由つきで読むだけ', async ({
    browser,
}, info) => {
    const email = `editor25-${Date.now()}@example.test`;
    const password = `Aa1!${randomBytes(18).toString('hex')}`;
    tinker(
        `$u = new \\App\\Models\\User; $u->forceFill(['name'=>'編集者','email'=>'${email}','email_verified_at'=>now(),'password'=>\\Illuminate\\Support\\Facades\\Hash::make('${password}'),'role'=>'editor'])->save();`,
    );
    const visitor = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const p = await visitor.newPage();

    try {
        await p.goto('/login');
        await p.locator('input[type=email]').fill(email);
        await p.locator('input[type=password]').fill(password);
        await p.locator('button[type=submit]').click();
        await p.waitForURL('**/dashboard');
        await p.goto('/admin/forms/contact');
        await p.screenshot({
            path: info.outputPath('form-before-assert.png'),
            fullPage: true,
        });
        await expect(
            p.getByText('この設定はオーナー・制作者・管理者だけが変えられます', {
                exact: true,
            }),
        ).toBeVisible();

        for (const input of await p
            .locator(
                'form input, form textarea, form button[role=combobox], form button[role=checkbox]',
            )
            .all()) {
            await expect(input).toBeDisabled();
        }

        await expect(p.getByTestId('form-save')).toHaveCount(0);
        await p.screenshot({
            path: info.outputPath('editor-read-only-form.png'),
            fullPage: true,
        });
    } finally {
        await visitor.close();
    }
});
