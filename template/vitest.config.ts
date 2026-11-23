import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * 画面を開かずに決まることを試す（Phase 9.6 / P0-C6）。
 *
 * ## `vite.config.ts` に相乗りしない
 *
 * あちらは読み込んだ時点で `execSync` を呼び（host に php があるかを見る）、
 * Laravel / Inertia / Tailwind の plugin を並べる。**単体試験には 1 つも要らない**うえ、
 * 試験の失敗に「build の都合」が混ざる。ここは最小で持つ。
 *
 * ## Playwright との住み分け
 *
 * **vitest は画面を開かずに決まること**（履歴の上限・積み方）、
 * **Playwright は画面を開かないと分からないこと**（押せる・見える・保存される）。
 * **同じことを 2 か所で見ない。**
 */
export default defineConfig({
    resolve: {
        // `@/…` は tsconfig の paths と同じ場所を指す（1 か所で決める）
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        // 配る場所に置く（`template/tests/` の Pest と同じ扱い）
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
