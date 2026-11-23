import { defineConfig, devices } from '@playwright/test';

/**
 * 視覚回帰だけを回す設定（R-15 Stage 2）。
 *
 * 標準 E2E（`playwright.config.ts`）とは **別の設定**にしてある。
 *
 * | | 標準 | 視覚回帰 |
 * | --- | --- | --- |
 * | 前提 | `globalSetup` が `migrate:fresh` して固定状態を作る | **今の状態のまま**比べる |
 * | 実行環境 | `docker compose exec` が要る | 公開サイトが見えれば何でもよい |
 * | 内容 | 公開サイト・管理画面の回帰 | 取り込み元 HTML と、変換後の見た目の差 |
 *
 * **`globalSetup` を持たない。** 標準側の setup は `docker compose exec` と
 * kit の seeder（`TemplateContentSeeder`）を前提にしていて、
 * 生成プロジェクトでは動かない。視覚回帰は DB を作り直す必要も無い
 * （比較元は取り込み元の static HTML を `file://` で読むので、基準画像も要らない）。
 *
 * ```
 * APP_URL=http://localhost:8180 pnpm exec playwright test --config=playwright.visual.config.ts
 * ```
 *
 * 許容差分は `.agentcms/visual-thresholds.json`（無ければ既定 2%）。
 */
export default defineConfig({
    testDir: './tests/e2e',
    testMatch: /template-visual\.spec\.ts/,
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.APP_URL ?? 'http://localhost:8180',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'visual-desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 800 },
            },
        },
        {
            name: 'visual-mobile',
            dependencies: ['visual-desktop'],
            use: { ...devices['Pixel 7'] },
        },
    ],
});
