import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { execSync } from 'node:child_process';
import { defineConfig, type PluginOption } from 'vite';

// Product Contract: host PHP は Docker Golden Path では NOT REQUIRED（Phase 7 RC2 Blocker 3）。
// wayfinder の TS 生成は `agentcms dev` / `agentcms verify` が Docker PHP runtime 内で実行し、
// 生成済み artifacts（resources/js/{actions,routes,wayfinder}）を build が利用する。
// host に php がある開発環境（HMR で再生成したい場合）だけ plugin を有効化する。
function hostPhpAvailable(): boolean {
    try {
        execSync(
            process.platform === 'win32' ? 'where php' : 'command -v php',
            { stdio: 'ignore' },
        );

        return true;
    } catch {
        return false;
    }
}

const useWayfinderPlugin =
    process.env.AGENTCMS_SKIP_WAYFINDER !== '1' && hostPhpAvailable();

if (!useWayfinderPlugin) {
    console.log(
        '[agentcms] wayfinder plugin skipped (no host php) — using pre-generated artifacts',
    );
}

export default defineConfig({
    plugins: [
        laravel({
            // app.* は管理画面（Inertia）、public.css は公開サイト（Blade + プレーン CSS）
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                'resources/css/public.css',
                // サイトの枠に記事の一覧・詳細を載せるときの部品（UX 一周 2026-09-28）
                'resources/css/site-parts.css',
                // PageDocument の描画用（Phase 9.1D）
                'resources/css/document.css',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        ...(useWayfinderPlugin
            ? [
                  wayfinder({
                      formVariants: true,
                  }) as PluginOption,
              ]
            : []),
    ],
});
