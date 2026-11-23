import { readFileSync } from 'node:fs';

import { expect, test as setup } from '@playwright/test';

// 認証情報は project ごとに生成される（共有既定パスワードは存在しない — Phase 7）。
// 優先順: 環境変数 → project の .env（playwright は template/project root で実行される）
function envFromDotenv(key: string): string | undefined {
    try {
        const content = readFileSync('.env', 'utf8');
        const m = new RegExp(`^${key}=(.*)$`, 'm').exec(content);

        return m ? (m[1] ?? '').trim().replace(/^"(.*)"$/, '$1') : undefined;
    } catch {
        return undefined;
    }
}

const ADMIN_EMAIL =
    process.env.ADMIN_EMAIL ??
    envFromDotenv('ADMIN_EMAIL') ??
    'admin@example.test';
const ADMIN_PASSWORD =
    process.env.ADMIN_PASSWORD ?? envFromDotenv('ADMIN_PASSWORD') ?? '';

// ログインは 1 回だけ行い storageState を再利用する（Fortify の rate limit を消費しないため）
setup('管理者としてログイン', async ({ page }) => {
    if (ADMIN_PASSWORD === '') {
        throw new Error(
            'ADMIN_PASSWORD が見つかりません（環境変数にも .env にもありません）。`agentcms dev` を実行してから E2E を実行してください。',
        );
    }

    await page.goto('/login');
    await page.fill('input[type="email"]', ADMIN_EMAIL);
    await page.fill('input[type="password"]', ADMIN_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
    await expect(page.locator('body')).toContainText('ダッシュボード');

    await page.context().storageState({ path: 'tests/e2e/.auth/admin.json' });
});
