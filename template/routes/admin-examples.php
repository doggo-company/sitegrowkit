<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\News\Http\AdminNewsController;
use Modules\Works\Http\AdminWorksController;

/**
 * kit の例（`news` / `works`）の管理 CRUD route（PHASE 9.7 / STAGE 20F）。
 *
 * **このファイルは新しく生成するサイトへ配らない**（SPEC §C.5 / OG-A）。
 * 呼び出し側（`routes/admin.php`）が在るときだけ読み込む。
 * 生成サイトの投稿タイプは `ManagedContentController` が受けるので、
 * ここに 1 行も要らない。
 */
foreach ([
    'news' => AdminNewsController::class,
    'works' => AdminWorksController::class,
] as $base => $controller) {
    Route::get($base, [$controller, 'index'])->name("{$base}.index");
    Route::get("{$base}/create", [$controller, 'create'])->name("{$base}.create");
    Route::post($base, [$controller, 'store'])->name("{$base}.store");
    Route::get("{$base}/{id}/edit", [$controller, 'edit'])->whereNumber('id')->name("{$base}.edit");
    // 保存済みの下書きを公開と同じ見た目で確かめる（Phase 9.15 / STAGE 28A）
    Route::get("{$base}/{id}/preview", [$controller, 'preview'])->whereNumber('id')->name("{$base}.preview");
    Route::put("{$base}/{id}", [$controller, 'update'])->whereNumber('id')->name("{$base}.update");
    // 編集中の内容を履歴へ積む（Phase 9.15 / STAGE 28B）。**記事本体は書き換えない**
    Route::post("{$base}/{id}/autosave", [$controller, 'autosave'])->whereNumber('id')->name("{$base}.autosave");
    Route::delete("{$base}/{id}", [$controller, 'destroy'])->whereNumber('id')->name("{$base}.destroy");
    // ゴミ箱への出し入れ（Phase 9.8 / STAGE 21C）。画面は 21E
    Route::post("{$base}/{id}/trash", [$controller, 'trash'])->whereNumber('id')->name("{$base}.trash");
    // 見本の投稿型も同じ複製処理へ渡す。
    Route::post("{$base}/{id}/duplicate", [$controller, 'duplicate'])->whereNumber('id')->name("{$base}.duplicate");
    Route::post("{$base}/{id}/restore", [$controller, 'restore'])->whereNumber('id')->name("{$base}.restore");
    // 履歴と過去の版への復元（Phase 9.8 / STAGE 21D）。画面は 21E
    Route::get("{$base}/{id}/revisions", [$controller, 'revisions'])->whereNumber('id')->name("{$base}.revisions");
    Route::post("{$base}/{id}/revisions/{revision}/restore", [$controller, 'revisionRestore'])
        ->whereNumber('id')->whereNumber('revision')->name("{$base}.revision-restore");
}
