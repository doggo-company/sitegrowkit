import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 管理画面から利用者を増やせること／自分では消せないこと（STAGE #18A 追加要件 A・B）。
 *
 * Feature テストは経路と通知の正しさを担保する。ここで見たいのは
 * **画面として成立しているか** — オーナーが一覧から招待画面へ入り、
 * フォームを送り、一覧に反映され、招待された本人がリンクから使い始められるか。
 * あわせて、本人の設定画面に **アカウント削除の入口が無い**ことも見る。
 *
 * 実行方法とこの spec を標準スイートへ入れない理由は
 * `user-invitation.isolated.spec.ts` の docblock を参照。
 */

const composeCwd = process.env.AGENTCMS_COMPOSE_CWD ?? process.cwd();

/** 対象プロジェクトの artisan を叩く（shell を経由しない） */
function tinker(php: string): string {
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
            php,
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );
}

/** プロジェクトが生成した管理者の認証情報（Git 管理外） */
function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );
    const parsed = JSON.parse(raw) as { email: string; password: string };

    return parsed;
}

/*
 * ログインの回数制限をならす。
 *
 * この spec は test ごとに管理者でログインするので、
 * 連続実行すると Fortify の throttle に当たって
 * 「実装は正しいのにログインできない」で落ちる。
 * 使い捨てのプロジェクトに対してだけ実行するので、
 * 制限の記録（cache）を消してから始める。
 */
test.beforeAll(() => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );
});

async function loginAsOwner(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

test.describe('管理画面から利用者を招待できる', () => {
    const invitee = `created-${Date.now()}@example.test`;

    test('一覧 → 招待 → 一覧反映 → 本人がリンクから利用開始', async ({
        page,
    }) => {
        await loginAsOwner(page);

        // 1. 一覧に「利用者を招待」の導線が **見えている**
        await page.goto('/admin/users');
        const invite = page.getByRole('link', { name: '利用者を招待' });
        await expect(invite).toBeVisible();

        // 2. 招待画面へ入る。パスワード欄は **無い**
        await invite.click();
        await page.waitForURL('**/admin/users/create');
        await expect(page.locator('input[type="password"]')).toHaveCount(0);

        // 3. 送信する
        await page.fill('#name', 'ブラウザ招待');
        await page.fill('#email', invitee);
        await page.click('button[type="submit"]');

        await page.waitForURL('**/admin/users');

        // 4. 一覧に出ている
        await page.goto(`/admin/users?q=${encodeURIComponent(invitee)}`);
        await expect(page.locator('body')).toContainText('ブラウザ招待');

        // 5. 招待された本人がリンクからパスワードを設定して入れる
        const url = tinker(
            `$u = \\App\\Models\\User::where('email', '${invitee}')->firstOrFail(); ` +
                `echo route('password.reset', ['token' => \\App\\Notifications\\UserInvitation::issueTokenFor($u), 'email' => $u->email]);`,
        ).trim();

        expect(url).toContain('/reset-password/');

        const password = ['CreatedPassw0rd', '!', '2026'].join('');

        await page.context().clearCookies();
        await page.goto(url);
        await page.fill('input[name="password"]', password);
        await page.fill('input[name="password_confirmation"]', password);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/login');

        await page.fill('input[type="email"]', invitee);
        await page.fill('input[type="password"]', password);
        await page.click('button[type="submit"]');

        await page.waitForURL(/email\/verify|dashboard/);
        expect(page.url()).toMatch(/email\/verify|dashboard/);
    });
});

test.describe('代理作成（手動受け渡し）', () => {
    const invitee = `manual-${Date.now()}@example.test`;

    test('URL は 1 回だけ表示され、離脱後は一覧へ戻る', async ({ page }) => {
        await loginAsOwner(page);

        await page.goto('/admin/users/create');
        await page.fill('#name', '手動さん');
        await page.fill('#email', invitee);
        await page.check('input[name="deliveryMethod"][value="manual"]');
        await page.click('button[type="submit"]');

        // 秘密を含まない固定 URL へ来る
        await page.waitForURL('**/admin/users/setup-link');
        expect(page.url()).not.toContain('reset-password');
        expect(page.url()).not.toContain(invitee);

        // 危険性・期限・再表示不可が読める
        await expect(page.locator('body')).toContainText(
            'パスワードを設定できます',
        );
        await expect(page.locator('body')).toContainText('有効期限');
        await expect(page.locator('body')).toContainText(
            '二度と表示できません',
        );

        const setupUrl = await page.inputValue('#setup-link-url');
        expect(setupUrl).toContain('/reset-password/');

        // コピーの結果が文字で伝わる（読み上げにも届く領域）
        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.click('#copy-button');
        await expect(page.locator('#copy-status')).toContainText('コピー');

        // 離脱してから戻る / 再読み込みしても二度と出ない
        await page.click('text=利用者一覧へ戻る');
        await page.waitForURL('**/admin/users');

        await page.goto('/admin/users/setup-link');
        await page.waitForURL('**/admin/users');
        await expect(page.locator('#setup-link-url')).toHaveCount(0);

        // 受け取った URL で本人がパスワードを設定できる
        const password = ['ManualPassw0rd', '!', '2026'].join('');

        await page.context().clearCookies();
        await page.goto(setupUrl);
        await page.fill('input[name="password"]', password);
        await page.fill('input[name="password_confirmation"]', password);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/login');

        await page.fill('input[type="email"]', invitee);
        await page.fill('input[type="password"]', password);
        await page.click('button[type="submit"]');

        // メールは確認していないので、確認画面へ誘導される
        await page.waitForURL(/email\/verify|dashboard/);
        expect(page.url()).toMatch(/email\/verify|dashboard/);
    });
});

test.describe('失敗は画面に見える', () => {
    /**
     * 実際に起こりうる失敗を **画面の操作で** 踏む。
     *
     * 招待中の利用者の詳細を開いたまま、別の管理者がその人を無効化する。
     * 開きっぱなしの画面にはまだ発行ボタンが残っているので、押すと拒否される。
     * このとき利用者に **見える形（エラートースト）** で理由が出ること。
     */
    test('開いたままの画面から発行するとエラートーストが出る', async ({
        page,
    }) => {
        const email = `stale-${Date.now()}@example.test`;

        await loginAsOwner(page);

        // 招待中の利用者を作る
        const id = tinker(
            `$r = app(\\App\\Application\\Users\\InviteUser::class)->execute(` +
                `['name' => '掲示中', 'email' => '${email}', 'role' => 'editor'], ` +
                `\\AgentCms\\Core\\Application\\OperationContext::cli('e2e')); ` +
                `echo \\App\\Models\\User::where('email', '${email}')->firstOrFail()->id;`,
        ).trim();

        await page.goto(`/admin/users/${id}`);

        const issue = page.getByRole('button', {
            name: 'パスワード設定 URL を発行',
        });
        await expect(issue).toBeVisible();

        // 別の管理者が無効化した（画面はまだ古いまま）
        tinker(
            `\\App\\Models\\User::findOrFail(${id})->forceFill(['is_active' => false])->save();`,
        );

        await issue.click();

        // 押しても黙って何も起きない、にはしない
        await expect(page.locator('body')).toContainText(
            '無効化された利用者にはパスワード設定 URL を発行できません',
        );
    });
});

test.describe('受け渡し画面に script を差し込めない', () => {
    test('表示名の script は実行されず、console error も出ない', async ({
        page,
    }) => {
        const errors: string[] = [];

        page.on('console', (message) => {
            /*
             * 資源の取得失敗（例: Inertia のハード遷移が返す 409）は
             * ブラウザが console error として出すが、
             * ここで見たいのは **script が動いたか / CSP に弾かれたか**。
             * 通信の状態コードは別のテストが見ている。
             */
            if (
                message.type() === 'error' &&
                !message.text().includes('Failed to load resource')
            ) {
                errors.push(message.text());
            }
        });
        page.on('pageerror', (error) => errors.push(error.message));

        await loginAsOwner(page);

        await page.goto('/admin/users/create');
        await page.fill(
            '#name',
            '</script><script>window.__e2eXss__=1;</script>',
        );
        await page.fill('#email', `xss-${Date.now()}@example.test`);
        await page.check('input[name="deliveryMethod"][value="manual"]');
        await page.click('button[type="submit"]');

        await page.waitForURL('**/admin/users/setup-link');

        // 仕込んだ script は動いていない
        const injected = await page.evaluate(
            () => (window as unknown as Record<string, unknown>).__e2eXss__,
        );
        expect(injected).toBeUndefined();

        // script 要素は正規の 1 本だけ（仕込まれた分は要素になっていない）
        await expect(page.locator('script')).toHaveCount(1);

        /*
         * その 1 本が **実際に動く**ことを確かめる。
         * ブラウザは CSP 適用後に nonce 属性を DOM から隠すので、
         * 属性を読んでも空になる。nonce が効いているかは
         * 「script が動いたか」でしか見分けられない。
         */
        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.click('#copy-button');
        await expect(page.locator('#copy-status')).toContainText('コピー');

        // 画面は壊れていない（CSP 違反や JS エラーが出ていない）
        expect(errors).toEqual([]);
        await expect(page.locator('#setup-link-url')).toBeVisible();
    });
});

test.describe('本人はアカウントを削除できない', () => {
    test('プロフィール設定に削除の入口が無い', async ({ page }) => {
        await loginAsOwner(page);

        await page.goto('/settings/profile');
        await expect(page.locator('body')).toContainText('プロフィール設定');

        // 削除の見出しもボタンも出ない
        await expect(page.getByText('アカウントを削除')).toHaveCount(0);
        await expect(page.getByRole('button', { name: /削除/ })).toHaveCount(0);
    });
});
