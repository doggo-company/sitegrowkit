import { defineConfig, devices } from '@playwright/test';

// E2E は起動済みの Docker 環境（agentcms dev）に対して実行する
export default defineConfig({
    testDir: './tests/e2e',
    // `tests/e2e/isolated/` は前提が違う（利用者を増やす・rate limit を消費する）ので
    // 標準スイートには入れない。実行は playwright.isolated.config.ts から
    testIgnore: '**/isolated/**',
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL: process.env.APP_URL ?? 'http://localhost:8180',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            // Template Import の parity 検証は seed 直後の状態で最初に実行する
            // （後続の admin CRUD が公開コンテンツを追加し、static fixture との比較が壊れるため）
            name: 'template-desktop',
            testMatch: /template-.*\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 800 },
            },
        },
        {
            name: 'template-mobile',
            dependencies: ['template-desktop'],
            testMatch: /template-visual\.spec\.ts/,
            use: { ...devices['Pixel 7'] },
        },
        {
            // ログインを 1 回だけ行い storageState を保存（rate limit 対策）
            name: 'setup',
            dependencies: ['template-mobile'],
            testMatch: /auth\.setup\.ts/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'desktop',
            dependencies: ['setup'],
            testMatch: /.*\.spec\.ts/,
            // project の testIgnore は top-level を **置き換える**ので、
            // `isolated/` の除外もここで明示する（Correction 2-7）
            testIgnore: [/template-.*\.spec\.ts/, /isolated[\\/]/],
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 800 },
            },
        },
        {
            // mobile は公開サイトの表示・スクリーンショットのみ（管理画面 E2E は desktop で担保）
            name: 'mobile',
            dependencies: ['desktop'],
            testMatch: /public\.spec\.ts/,
            use: { ...devices['Pixel 7'] },
        },
    ],
});
