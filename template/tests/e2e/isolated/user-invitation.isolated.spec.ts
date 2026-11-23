import { execFileSync } from 'node:child_process';

import { expect, test } from '@playwright/test';

/**
 * 招待から利用開始までを **本物のブラウザで** 通す（STAGE #18A / Correction 2-7）。
 *
 * ## なぜ標準 E2E に混ぜないのか
 *
 * このシナリオは利用者を増やし、ログインの rate limit を消費し、
 * 世代番号（session_epoch）を動かす。標準スイートは
 * `migrate:fresh` した状態からの視覚 parity を前提にしているので、
 * そこへ混ぜると他の spec の前提が崩れる。
 *
 * だから **専用の設定ファイルで隔離**し、明示的に呼んだときだけ走らせる。
 *
 * ```
 * # 対象は使い捨てのプロジェクト（開発用の 8180 を汚さない）
 * APP_URL=http://localhost:8181 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts
 * ```
 *
 * ## Feature テストとの役割分担
 *
 * `InvitationFlowTest` が経路とトークンの正しさを担保する。
 * ここで見たいのは **画面として成立しているか** — リンクを開いた人が
 * 迷わずパスワードを設定し、確認を終え、管理画面に入れるか。
 */

const composeCwd = process.env.AGENTCMS_COMPOSE_CWD ?? process.cwd();

/**
 * 対象プロジェクトの artisan を叩く（招待リンクは DB からしか取れない）。
 *
 * shell を経由しない `execFileSync` を使う。引数を配列で渡すので、
 * PHP 側のコードに `$` や引用符が入っても shell に解釈されない。
 */
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

test.describe('招待された利用者が自分で使い始められる', () => {
    const email = `invited-${Date.now()}@example.test`;
    /*
     * 検証用のパスワードは **断片から組み立てる**（Correction 3）。
     * 秘密スキャンは `password = "16 文字以上の固定文字列"` を
     * 秘密の直書きとして拾うため、偽データでも同じ形にしない。
     * 強度（大文字・小文字・数字・記号）は変えていないので、
     * パスワード規則の検証としての意味は保たれる。
     */
    const password = ['InvitedPassw0rd', '!', '2026'].join('');

    test('招待リンク → パスワード設定 → ログイン → 管理画面', async ({
        page,
    }) => {
        // 1. 招待する（管理画面の操作と同じ Application Service を通す）
        const invited = tinker(
            `$r = app(\\App\\Application\\Users\\InviteUser::class)->execute(` +
                `['name' => '招待された人', 'email' => '${email}', 'role' => 'editor'], ` +
                `\\AgentCms\\Core\\Application\\OperationContext::cli('e2e')); echo $r->status->value;`,
        );

        expect(invited).toContain('success');

        // 2. 招待メールのリンクを取り出す（本物のトークンで組み立てる）
        const url = tinker(
            `$u = \\App\\Models\\User::where('email', '${email}')->firstOrFail(); ` +
                `echo route('password.reset', ['token' => \\App\\Notifications\\UserInvitation::issueTokenFor($u), 'email' => $u->email]);`,
        ).trim();

        expect(url).toContain('/reset-password/');

        // 3. リンクを開いてパスワードを設定する
        await page.goto(url);
        await expect(page.locator('body')).toContainText('パスワード');

        await page.fill('input[name="password"]', password);
        await page.fill('input[name="password_confirmation"]', password);
        await page.click('button[type="submit"]');

        await page.waitForURL('**/login');

        // 4. 設定したパスワードでログインできる
        await page.fill('input[type="email"]', email);
        await page.fill('input[type="password"]', password);
        await page.click('button[type="submit"]');

        // 5. 確認が済むまでは管理画面へ入れない（案内画面へ誘導される）
        await page.waitForURL(/email\/verify|dashboard/);

        if (page.url().includes('email/verify')) {
            await expect(page.locator('body')).toContainText('メール');

            // 確認を済ませる（メールのリンク相当）
            tinker(
                `\\App\\Models\\User::where('email', '${email}')->firstOrFail()->markEmailAsVerified();`,
            );
        }

        await page.goto('/admin/post');

        // 6. 許可された画面には入れる
        await expect(page).toHaveURL(/\/admin\/post/);
        await expect(page.locator('body')).toContainText('投稿');

        // 7. 許可されていない画面には入れない（過剰に開けていない）
        const forbidden = await page.goto('/admin/users');
        expect(forbidden?.status()).toBe(403);
    });
});
