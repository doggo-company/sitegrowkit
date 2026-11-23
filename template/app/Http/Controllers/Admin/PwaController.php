<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Admin PWA の manifest / offline fallback。どちらも秘匿情報を含まないため未認証で配信する
 * （ブラウザは manifest / SW を資格情報なしで取得するため、auth を掛けるとインストール不能になる）。
 * scope は /admin のみ — 公開サイトへ Service Worker を波及させない。
 */
final class PwaController
{
    public function manifest(SettingsStore $settings): JsonResponse
    {
        $siteName = (string) $settings->get('site_name', config('app.name'));

        return response()->json([
            'name' => "{$siteName} 管理",
            'short_name' => '管理',
            'description' => "{$siteName} の管理画面",
            'start_url' => '/admin',
            // manifest scope は "/"（SW の scope /admin とは独立）。
            // /admin に限定すると、未認証起動時の /login リダイレクトが scope 外となり、
            // iOS がアプリ内ブラウザ表示へ切替えて以後 standalone に戻らない（実機で確認した罠）。
            // SW は /admin scope のままのため公開サイトへの影響はない（ADR-0007 の意図は維持）。
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#18181b',
            'icons' => [
                ['src' => '/admin-pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/admin-pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/admin-pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * SW 本体は public/admin-pwa/sw.js に置き、/admin/sw.js として配信する。
     * scope を /admin（トレイリングスラッシュなし = /admin 自身も制御下）へ広げるため
     * Service-Worker-Allowed ヘッダーが必要 — 静的配信ではなくルート経由にしている理由。
     */
    public function serviceWorker(): Response
    {
        return response((string) file_get_contents(public_path('admin-pwa/sw.js')), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/admin',
            // 更新検知を妨げないため SW 自体はキャッシュさせない
            'Cache-Control' => 'no-cache, no-store, max-age=0',
        ]);
    }

    public function offline(): View
    {
        return view('admin.offline');
    }
}
