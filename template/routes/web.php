<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Auth\OneTimeLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

/*
 * セットアップ直後の「管理画面を開く」用の 1 回きりのログインリンク（UX 一周 3 周目 N1）。
 * **ローカル（APP_ENV=local）以外では 404**。判定は controller と `App\Support\OneTimeLogin` が持つ。
 */
Route::get('login/once/{token}', OneTimeLoginController::class)
    ->middleware('throttle:10,1')
    ->name('login.once');

require __DIR__.'/public.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// 同一オリジンの操作面（Admin / MCP / WebMCP が共有する /api/v1 — Phase 9.1B）
require __DIR__.'/api-v1.php';

/*
 * agentcms figma apply — Figma import 画面の route（ADR-0011）。
 *
 * **kit の合成 fixture 専用**なので、生成 project には配られない（SPEC §C.5）。
 * 在るときだけ読み込む。
 */
if (file_exists(__DIR__.'/figma.php')) {
    require __DIR__.'/figma.php';
}
