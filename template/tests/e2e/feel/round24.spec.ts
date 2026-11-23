import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({
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
        throw new Error('round24 の試験準備に失敗しました（artisan）');
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

test('#1 二つのタブ: 読み込み前に確認・コピーでき、読み込み後に打った文を戻せる', async ({
    page,
    context,
}, info) => {
    await editor(page);
    const old = await context.newPage();

    try {
        await old.goto(page.url());
        await old.getByTestId('layer-heading').first().click();
        await page
            .getByTestId('inspector-prop-text')
            .fill('新しいタブで保存した見出し');
        await page.getByTestId('inspector-prop-text').blur();
        await expect(page.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await old
            .getByTestId('inspector-prop-text')
            .fill('古いタブで打った消してはいけない文');
        await old.getByTestId('inspector-prop-text').blur();
        await expect(old.getByTestId('editor-conflict')).toBeVisible();
        await old
            .getByRole('button', { name: '読み込み直す', exact: true })
            .click();
        await expect(old.getByTestId('editor-reload-confirm')).toContainText(
            'この画面で打った文が消えます',
        );
        await expect(old.getByTestId('editor-unsaved-copy-text')).toHaveValue(
            /古いタブで打った消してはいけない文/,
        );
        await expect(old.getByTestId('editor-copy-unsaved')).toBeVisible();
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await old.getByTestId('editor-copy-unsaved').click();
        expect(
            await old.evaluate(() => navigator.clipboard.readText()),
        ).toContain('古いタブで打った消してはいけない文');
        await old.waitForTimeout(300);
        await old.screenshot({ path: info.outputPath('conflict-confirm.png') });
        await old.getByTestId('editor-cancel-reload').click();
        await expect(old.getByTestId('inspector-prop-text')).toHaveValue(
            '古いタブで打った消してはいけない文',
        );
        await old
            .getByRole('button', { name: '読み込み直す', exact: true })
            .click();
        await old.getByTestId('editor-confirm-reload').click();
        await expect(old.getByTestId('editor-recovery')).toContainText(
            '保存されていない文があります',
        );
        await old.screenshot({
            path: info.outputPath('conflict-recovery.png'),
        });
        await old.getByTestId('editor-restore-unsaved').click();
        await old.getByTestId('layer-heading').first().click();
        await expect(old.getByTestId('inspector-prop-text')).toHaveValue(
            '古いタブで打った消してはいけない文',
        );
        await expect(old.getByTestId('editor-save-state')).toContainText(
            '保存済み',
        );
        await old.reload();
        await old.getByTestId('layer-heading').first().click();
        await expect(old.getByTestId('inspector-prop-text')).toHaveValue(
            '古いタブで打った消してはいけない文',
        );
        await expect(old.getByTestId('editor-recovery')).toHaveCount(0);
    } finally {
        await old.close();
    }
});

test('#2 長い題名でもURL空で作れる', async ({ page }, info) => {
    await page.goto('/admin/pages/create');
    const title = '着付け教室のご案内・'.repeat(9) + '着付けの';
    await page.getByTestId('page-title-input').fill(title);
    const draft = page.getByTestId('page-slug-draft');
    await expect(draft).toContainText('kitsuke-kyoushitsu-no-go-annai');
    const slug =
        ((await draft.textContent()) ?? '').match(/\/([a-z0-9-]+)/)?.[1] ?? '';
    expect(slug.length).toBeLessThanOrEqual(200);
    expect(slug).toMatch(/^[a-z0-9]+(?:-[a-z0-9]+)*$/);
    await expect(page.getByTestId('page-slug-input')).toHaveValue('');
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
    await expect(page.getByTestId('page-slug-input')).toHaveValue(slug);
    await page.screenshot({ path: info.outputPath('long-title-created.png') });
});

test('#5 初回パスワード設定の完了表示', async ({ browser }, info) => {
    const email = `feel24-${Date.now()}@example.test`;
    const result = tinker(
        `$r = app(\\App\\Application\\Users\\InviteUser::class)->execute(['name'=>'着付けの先生','email'=>'${email}','role'=>'editor'], \\AgentCms\\Core\\Application\\OperationContext::cli('feel24')); echo $r->status->value;`,
    );
    expect(result).toContain('success');
    const url = tinker(
        `$u = \\App\\Models\\User::where('email','${email}')->firstOrFail(); echo route('password.reset', ['token'=>\\App\\Notifications\\UserInvitation::issueTokenFor($u), 'email'=>$u->email]);`,
    );
    const visitor = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const p = await visitor.newPage();

    try {
        await p.goto(url);
        const password = `Aa1!${randomBytes(18).toString('hex')}`;
        await p.locator('input[name=password]').fill(password);
        await p.locator('input[name=password_confirmation]').fill(password);
        await p.locator('button[type=submit]').click();
        await p.waitForURL('**/login');
        await expect(
            p.getByText('パスワードを設定しました。', { exact: true }),
        ).toBeVisible();
        await expect(
            p.getByText('パスワードを再設定しました。', { exact: true }),
        ).toHaveCount(0);
        await p.screenshot({
            path: info.outputPath('first-password-login.png'),
        });
    } finally {
        await visitor.close();
    }
});

for (const viewport of [
    { width: 1366, height: 768 },
    { width: 390, height: 844 },
]) {
    test(`#6 429でも日本語の案内と入力内容が残る ${viewport.width}`, async ({
        browser,
    }, info) => {
        const visitor = await browser.newContext({
            viewport,
            storageState: { cookies: [], origins: [] },
        });
        const p = await visitor.newPage();

        try {
            await p.goto('/contact');
            await p.locator('#f-name').fill('送信制限の確認');
            await p.locator('#f-email').fill('feel24@example.test');
            await p.locator('#f-subject').selectOption({ index: 1 });
            await p
                .locator('#f-message')
                .fill('消してはいけないお問い合わせの本文');
            // 実際のPOSTで制限に当てる。応答を偽装しない。
            await p.evaluate(async () => {
                const f =
                    document.querySelector<HTMLInputElement>('#f-name')!.form!;

                for (let n = 0; n < 15; n++) {
                    const r = await fetch(f.action, {
                        method: 'POST',
                        body: new FormData(f),
                    });

                    if (r.status === 429) {
                        return;
                    }
                }

                throw new Error('15回の実送信で429を再現できませんでした');
            });
            const response = p.waitForResponse(
                (r) => r.request().method() === 'POST' && r.status() === 429,
            );
            await p.locator('button[type=submit]').click();
            await response;
            await expect(
                p.getByText('少し待ってからもう一度送ってください', {
                    exact: false,
                }),
            ).toBeVisible();
            await expect(p.locator('#f-name')).toHaveValue('送信制限の確認');
            await expect(p.locator('#f-email')).toHaveValue(
                'feel24@example.test',
            );
            await expect(p.locator('#f-message')).toHaveValue(
                '消してはいけないお問い合わせの本文',
            );
            await p.screenshot({
                path: info.outputPath(`contact429-${viewport.width}.png`),
            });
        } finally {
            await visitor.close();
            // この試験で使い切ったフォームの枠だけ片付け、次の通常送信に持ち越さない。
            tinker(
                '$id = \\AgentCms\\Core\\Forms\\Form::where("key", "contact")->value("id"); \\Illuminate\\Support\\Facades\\DB::table(config("cache.stores.database.table", "cache"))->where("key", "like", "%spam:form:".$id.":%")->delete();',
            );
        }
    });
}

test('#4 谷中のURL候補はyanaka', async ({ page }) => {
    await page.goto('/admin/pages/create');
    await page
        .getByTestId('page-title-input')
        .fill('谷中小林着付け教室のこだわり');
    await expect(page.getByTestId('page-slug-draft')).toContainText(
        '/yanaka-kobayashi-kitsuke-kyoushitsu-no-kodawari',
    );
});

test('#3 編集者に保存できない通知先設定のリンクを出さず理由を言う', async ({
    browser,
}, info) => {
    const email = `editor24-${Date.now()}@example.test`;
    const password = `Aa1!${randomBytes(18).toString('hex')}`;
    tinker(
        `$u = new \\App\\Models\\User; $u->forceFill(['name'=>'編集者の先生','email'=>'${email}','email_verified_at'=>now(),'password'=>\\Illuminate\\Support\\Facades\\Hash::make('${password}'),'role'=>'editor'])->save();`,
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
        await expect(
            p.getByRole('link', { name: '通知先を設定する', exact: true }),
        ).toHaveCount(0);
        await expect(
            p.getByText(/オーナーに.*頼んでください/).first(),
        ).toBeVisible();
        await p.screenshot({ path: info.outputPath('editor-dashboard.png') });
    } finally {
        await visitor.close();
    }
});
