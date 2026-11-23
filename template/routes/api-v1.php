<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ContentApiController;
use App\Http\Controllers\Api\V1\ContentTypeApiController;
use App\Http\Controllers\Api\V1\MediaApiController;
use App\Http\Controllers\Api\V1\MenuApiController;
use App\Http\Controllers\Api\V1\PageApiController;
use App\Http\Controllers\Api\V1\RedirectApiController;
use App\Http\Controllers\Api\V1\ReusableComponentApiController;
use App\Http\Controllers\Api\V1\SettingsApiController;
use App\Http\Controllers\Api\V1\TaxonomyApiController;
use Illuminate\Support\Facades\Route;

/*
 * 同一オリジンの操作面（Phase 9.1B / STEP 10、owner 決定 G1 = Option A）。
 *
 * - **公開ヘッドレス API ではない**。web ミドルウェア（セッション + CSRF）の中に置き、
 *   Admin と同じログインセッションでのみ使える
 * - トークン認証（Sanctum / OAuth）は Phase 9.1B では**採用しない**
 * - 破壊的操作は ConfirmationGate を通る（`X-Confirmation` ヘッダで token を渡す）
 * - Phase 9.1D で PageDocument 操作の write を追加（owner 決定 5）。
 *   それ以外の write を無制限に増やさない方針は維持する
 */
Route::middleware(['auth', 'verified', 'active'])->prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('content-types', [ContentTypeApiController::class, 'index'])->name('content-types.index');
    Route::get('content-types/{type}', [ContentTypeApiController::class, 'show'])->name('content-types.show');

    Route::get('content/{type}', [ContentApiController::class, 'index'])->name('content.index');
    Route::get('content/{type}/{id}', [ContentApiController::class, 'show'])->whereNumber('id')->name('content.show');
    Route::patch('content/{type}/{id}', [ContentApiController::class, 'update'])->whereNumber('id')->name('content.update');

    /*
     * ゴミ箱への出し入れ（Phase 9.8 / STAGE 21C）。
     *
     * **`DELETE` 動詞を使わない。** 既存の api/v1 と同じで、状態を変える操作は
     * `POST .../<動作>` に揃える。完全削除（purge）の route は置かない — OG8 が
     * 「backup ができるまで作らない」なので、置く場所自体が無い。
     */
    Route::post('content/{type}/{id}/trash', [ContentApiController::class, 'trash'])
        ->whereNumber('id')->name('content.trash');
    // 管理画面と同じ下書き複製を使う。
    Route::post('content/{type}/{id}/duplicate', [ContentApiController::class, 'duplicate'])
        ->whereNumber('id')->name('content.duplicate');
    Route::post('content/{type}/{id}/restore', [ContentApiController::class, 'restore'])
        ->whereNumber('id')->name('content.restore');

    /*
     * 掲載順（P1 ②）。**送るのは「どれの直後へ」だけ。**
     *
     * 一覧が持っているのはその頁の行だけなので、全件を送らせる形にすると
     * **頁の外の行を上書き**する。`afterId` が無ければ先頭へ。
     */
    Route::post('content/{type}/{id}/reorder', [ContentApiController::class, 'reorder'])
        ->whereNumber('id')->name('content.reorder');

    Route::get('media', [MediaApiController::class, 'index'])->name('media.index');
    Route::get('media/{id}', [MediaApiController::class, 'show'])->whereNumber('id')->name('media.show');

    /*
     * **受信（お問い合わせ）を読む route は置かない**（B-43）。
     *
     * `GET contacts` / `GET contacts/{id}` は `ContactSubmission` の 14 列を
     * そのまま返しており、**送信者の生 IP と本文と連絡先**が乗っていた。
     * Phase 9.12 は同じ種類のデータ（`form.submission.*`）を出さないと決めているので、
     * 旧側をそれに揃える。**受信は管理画面（`/admin/contact`）で読む。**
     */

    Route::get('settings', [SettingsApiController::class, 'show'])->name('settings.show');

    // PageDocument（Phase 9.1D）。物理削除（purge）は API にも置かない
    Route::get('components', [PageApiController::class, 'components'])->name('components.index');

    /*
     * 再利用 component（Phase 9.6 / P0-C7・SPEC §4.5）。
     *
     * **`detach` の口は無い。** 切り離しはページの document を変える操作なので
     * `page.document.patch` の command（`component.detach`）として入る —
     * document を変える口を 2 本にすると、validator・確認ゲート・監査・楽観ロックを
     * 二重化するか、どれかを穴にするかの二択になる。
     *
     * 削除に `DELETE` 動詞を使わないのは既存 api/v1 と同じ。
     */
    Route::get('components/reusable', [ReusableComponentApiController::class, 'index'])
        ->name('components.reusable.index');
    Route::post('components/reusable', [ReusableComponentApiController::class, 'store'])
        ->name('components.reusable.store');
    Route::get('components/reusable/{id}', [ReusableComponentApiController::class, 'show'])
        ->name('components.reusable.show');
    Route::patch('components/reusable/{id}', [ReusableComponentApiController::class, 'update'])
        ->name('components.reusable.update');
    Route::post('components/reusable/{id}/publish', [ReusableComponentApiController::class, 'publish'])
        ->name('components.reusable.publish');
    Route::post('components/reusable/{id}/archive', [ReusableComponentApiController::class, 'archive'])
        ->name('components.reusable.archive');
    Route::get('pages', [PageApiController::class, 'index'])->name('pages.index');
    Route::post('pages', [PageApiController::class, 'store'])->name('pages.store');
    Route::get('pages/{id}', [PageApiController::class, 'show'])->whereNumber('id')->name('pages.show');
    Route::patch('pages/{id}', [PageApiController::class, 'update'])->whereNumber('id')->name('pages.update');
    Route::get('pages/{id}/document', [PageApiController::class, 'document'])->whereNumber('id')->name('pages.document');
    Route::patch('pages/{id}/document', [PageApiController::class, 'patch'])->whereNumber('id')->name('pages.document.patch');
    Route::get('pages/{id}/versions', [PageApiController::class, 'versions'])->whereNumber('id')->name('pages.versions');
    Route::post('pages/{id}/versions/restore', [PageApiController::class, 'restoreVersion'])->whereNumber('id')->name('pages.versions.restore');
    Route::post('pages/{id}/publish', [PageApiController::class, 'publish'])->whereNumber('id')->name('pages.publish');
    // 公開・予約を取り消し、本文を残して下書きへ戻す。
    // 複製は公開しない新しい下書きを作る。
    Route::post('pages/{id}/duplicate', [PageApiController::class, 'duplicate'])->whereNumber('id')->name('pages.duplicate');
    Route::post('pages/{id}/unpublish', [PageApiController::class, 'unpublish'])->whereNumber('id')->name('pages.unpublish');
    Route::post('pages/{id}/trash', [PageApiController::class, 'trash'])->whereNumber('id')->name('pages.trash');
    Route::post('pages/{id}/restore', [PageApiController::class, 'restore'])->whereNumber('id')->name('pages.restore');

    /*
     * 分類（Phase 9.3B / SPEC §Q.1）。**MCP tool と 1:1 の 7 本**。
     *
     * - taxonomy 自体の作成・更新・削除は置かない（§P.2 — Phase 9.4）
     * - `{key}` は taxonomy key。**ULID を URL へ露出させない**
     * - 削除に `DELETE` 動詞を使わない。既存 api/v1 と同じ
     *   `POST .../delete` + `X-Confirmation` ヘッダに揃える
     */
    /*
     * メニュー（Phase 9.9 / P0-4）。**読みだけ**。
     * 公開ページの導線は Blade component が出すので、ここは管理・自動化向けである。
     */
    Route::get('menus', [MenuApiController::class, 'index'])->name('menus.index');
    Route::get('menus/{key}', [MenuApiController::class, 'show'])->name('menus.show');

    /*
     * リダイレクト（Phase 9.10 / P0-5）。**読みだけ**。
     *
     * `simulate` も GET にする。何も変えないものを POST に置くと、
     * 「api/v1 の POST は書き込み」という既存の約束が崩れる（`ApiV1Test`）。
     * `{id}` より先に置く（`simulate` が id として食われないように）。
     */
    Route::get('redirects', [RedirectApiController::class, 'index'])->name('redirects.index');
    Route::get('redirects/simulate', [RedirectApiController::class, 'simulate'])
        ->name('redirects.simulate');
    Route::get('redirects/{id}', [RedirectApiController::class, 'show'])
        ->whereNumber('id')->name('redirects.show');

    Route::get('taxonomies', [TaxonomyApiController::class, 'index'])->name('taxonomies.index');
    Route::get('taxonomies/{key}', [TaxonomyApiController::class, 'show'])->name('taxonomies.show');
    Route::get('taxonomies/{key}/terms', [TaxonomyApiController::class, 'terms'])->name('taxonomies.terms.index');
    Route::post('taxonomies/{key}/terms', [TaxonomyApiController::class, 'storeTerm'])->name('taxonomies.terms.store');
    Route::post('taxonomies/{key}/terms/reorder', [TaxonomyApiController::class, 'reorderTerms'])
        ->name('taxonomies.terms.reorder');
    Route::patch('terms/{id}', [TaxonomyApiController::class, 'updateTerm'])->name('terms.update');
    Route::post('terms/{id}/delete', [TaxonomyApiController::class, 'deleteTerm'])->name('terms.delete');
});
