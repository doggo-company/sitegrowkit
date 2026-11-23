import { defineConfig, devices } from '@playwright/test';

/**
 * 隔離ブラウザ検証（STAGE #18A / Correction 2-7）。
 *
 * 標準 E2E（`playwright.config.ts`）とは **別の設定**にしてある。
 *
 * | | 標準 | 隔離 |
 * | --- | --- | --- |
 * | 前提 | `migrate:fresh` 済みの固定状態 | 任意の状態（使い捨てプロジェクト） |
 * | 内容 | 公開サイト・管理画面の回帰と視覚 parity | 利用者を増やす一連の流れ |
 * | 実行 | `pnpm e2e` | 明示的に `--config=playwright.isolated.config.ts` |
 *
 * 混ぜると、こちらが増やした利用者やログイン試行が
 * 標準スイートの前提を壊す。だから global setup も storageState も持たない。
 *
 * ```
 * APP_URL=http://localhost:8181 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts
 * ```
 */
export default defineConfig({
    testDir: './tests/e2e/isolated',
    testMatch: /.*\.isolated\.spec\.ts/,
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
            name: 'isolated',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 800 },
            },
        },
    ],
});
