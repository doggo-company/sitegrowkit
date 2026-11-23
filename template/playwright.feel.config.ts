import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { defineConfig, devices } from '@playwright/test';

/*
 * 触り心地の門だけを回す（docs/feel-bar.md）。`node scripts/kit-verify.mjs` から呼ばれる。
 *
 * 標準の E2E（playwright.config.ts）と違い、**DB を作り直さない**（global-setup を持たない）。
 * 試験は自分でページ・記事・受信を 1 件ずつ足すだけ。
 *
 * 相手は `AGENTCMS_FEEL_PROJECT`（`agentcms create` で作ったプロジェクト）の .env に書かれた APP_PORT。
 * kit の template/ そのものは見本サイトの作りが違い、受信とメニューの流れを通せない（docs/feel-bar.md）。
 * :8180 に「たまたま居るもの」を見ない（kit-gates.mjs の B-20 と同じ理由）。
 */
const project = resolve(process.env.AGENTCMS_FEEL_PROJECT ?? '.');

function env(key: string): string {
    try {
        const m = new RegExp(`^${key}=(.*)$`, 'm').exec(
            readFileSync(join(project, '.env'), 'utf8'),
        );

        return (m?.[1] ?? '').trim().replace(/^"(.*)"$/, '$1');
    } catch {
        return '';
    }
}

process.env.FEEL_ADMIN_EMAIL ??= env('ADMIN_EMAIL') || 'admin@example.test';
process.env.FEEL_ADMIN_PASSWORD ??= env('ADMIN_PASSWORD');
// 回すたびに別の場所（前の回のログイン状態を読んで緑にならないように。setup と spec が同じ値を読む）
process.env.FEEL_AUTH_STATE ??= `tests/e2e/.auth/feel-admin-${process.pid}.json`;

export default defineConfig({
    testDir: './tests/e2e/feel',
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL:
            process.env.APP_URL ??
            `http://localhost:${env('APP_PORT') || '8180'}`,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'feel-setup',
            testMatch: /feel\.setup\.ts/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'feel',
            dependencies: ['feel-setup'],
            testMatch:
                /(feel|leave|round15|round16|round17|round19|round20|round21|round23|round24|round25|round25-upload|round26-permissions|round26-ordering)\.spec\.ts/,
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
