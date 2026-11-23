<?php

use AgentCms\Core\Admin\ManagedContentController;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\CmsManagedContentTypes;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Http\Controllers\Admin\ActivityAdminController;
use App\Http\Controllers\Admin\BackupAdminController;
use App\Http\Controllers\Admin\CommentAdminController;
use App\Http\Controllers\Admin\ContentExportController;
use App\Http\Controllers\Admin\ContentReplaceAdminController;
use App\Http\Controllers\Admin\ContentTypeAdminController;
use App\Http\Controllers\Admin\FieldGroupAdminController;
use App\Http\Controllers\Admin\FormAdminController;
use App\Http\Controllers\Admin\IndustryAdminController;
use App\Http\Controllers\Admin\MenuAdminController;
use App\Http\Controllers\Admin\PageAdminController;
use App\Http\Controllers\Admin\PwaController;
use App\Http\Controllers\Admin\ReadinessAdminController;
use App\Http\Controllers\Admin\RedirectAdminController;
use App\Http\Controllers\Admin\RelationOptionController;
use App\Http\Controllers\Admin\ReusableComponentAdminController;
use App\Http\Controllers\Admin\SamplePageAdminController;
use App\Http\Controllers\Admin\StarterPageAdminController;
use App\Http\Controllers\Admin\TaxonomyAdminController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Contact\Http\AdminContactController;
use Modules\Media\Http\AdminMediaController;
use Modules\Media\Http\MediaDownloadController;
use Modules\Notifications\Http\NotificationSettingsController;
use Modules\Notifications\Http\PushSubscriptionController;
use Modules\Site\Http\AdminSiteController;

/*
 * 管理画面ルート。認証は Laravel 標準（Fortify: login / password reset / 2FA / rate limiting）。
 * 公開登録は無効（管理者は Seeder / CLI で作成）。
 */

// PWA manifest / SW / offline は秘匿情報を含まず、ブラウザが資格情報なしで取得するため auth の外に置く。
//
// **セッションからも外す。** これらは Service Worker が任意のタイミングで取りに来る。
// セッションを開始すると、その裏取得が **flash を読んで消す**ことがあり、
// 直後に利用者が開いた画面から確認ダイアログや toast が消える
// （実際に「削除の確認が出ない」という形で表面化した）。
// 3 つとも状態を持たない応答なので、session / errors / Inertia の共有は不要。
// CSRF の中間層はセッションから XSRF cookie を作るので、これも一緒に外す
// （3 route とも GET のみで、書き込みが無いため保護対象ではない）
Route::middleware([])->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    HandleInertiaRequests::class,
    PreventRequestForgery::class,
])->group(function (): void {
    Route::get('admin/manifest.webmanifest', [PwaController::class, 'manifest'])->name('admin.manifest');
    Route::get('admin/sw.js', [PwaController::class, 'serviceWorker'])->name('admin.sw');
    Route::get('admin/offline', [PwaController::class, 'offline'])->name('admin.offline');
});
/*
 * 一般ファイルの配信（Phase 9.5-D / SPEC §3.2）。
 *
 * **認証 middleware の group に入れない。** あれは未認証を `/login` へ redirect するが、
 * redirect は「この URL は在る」と教えることになる。private を「無い」ことにしたいので、
 * 認可は controller の先頭で判定し、だめなら 404 を返す。
 * session は必要なので `web` group（routes/web.php 経由）には載ったままである。
 */
Route::get('admin/media/file/{id}', [MediaDownloadController::class, 'admin'])
    ->whereNumber('id')->name('admin.media.file');

Route::middleware(['auth', 'verified', 'active'])->prefix('admin')->name('admin.')->group(function (): void {
    /*
     * `/admin` の入口（Phase 9.7 / STAGE 20B）。
     *
     * **存在しない固定型へ redirect しない。** kit には `news` が在るが、
     * 新しく生成するサイトに在るのは `post` だけである（SPEC §C）。
     * 行き先は**要求を受けてから**決める — 判定は既存の
     * `ContentTypeResolver`（ファイルだけを読む）と route の登録状況に任せ、
     * ここに型名を焼き込まない。
     */
    Route::get('/', static function (ContentTypeResolver $resolver, Router $router): RedirectResponse {
        foreach ($resolver->availableTypes() as $id) {
            if ($resolver->schema($id)->kind === 'collection' && $router->has("admin.{$id}.index")) {
                return redirect()->route("admin.{$id}.index");
            }
        }

        // 投稿タイプが 1 つも無くても、行き先が無くならないようにする
        return redirect()->route('dashboard');
    })->name('home');

    /*
     * collection kind: Schema 駆動 CRUD（core SchemaResourceController）。
     *
     * **配られていない module の route を登録しない。** `news` / `works` は
     * kit の例なので、生成サイトには controller ごと存在しない（SPEC §C.5）。
     * その配線は**配らない別ファイル**に置き、在るときだけ読み込む — 参照ごと
     * 配らないので、生成サイトでは静的解析にも掛からない。
     */
    if (file_exists(__DIR__.'/admin-examples.php')) {
        require __DIR__.'/admin-examples.php';
    }

    /*
     * 投稿タイプ（Phase 9.4 / STAGE #17A — SPEC §1A）。**ちょうど 3 本**。
     *
     * 今回できるのは **新規追加だけ**。編集・アーカイブ・削除は次 Stage の
     * Owner Gate 対象なので route を置かない（置かないことが仕様である）。
     * 作成は `POST` 1 本で、確認トークンの有無で preview / apply が決まる。
     */
    Route::get('content-types', [ContentTypeAdminController::class, 'index'])->middleware('site.build')->name('content-types.index');
    Route::get('content-types/create', [ContentTypeAdminController::class, 'create'])->middleware('site.build')->name('content-types.create');
    Route::post('content-types', [ContentTypeAdminController::class, 'store'])->middleware('site.build')->name('content-types.store');

    /*
     * 編集・無効化・復元・並べ替え（Phase 9.7 / STAGE 20C）。
     *
     * **物理削除の route は置かない**（Field Group と同じ作法）。「削除」は無効化であり、
     * 定義もテーブルも投稿も消さないという契約を route の形で表す。
     * 破壊的操作は DELETE 動詞ではなく `POST .../archive` + 確認トークンに揃える。
     *
     * `reorder` は `{type}` より先に置く（`content-types/reorder` が型 ID として食われないように）。
     * `{type}` の文法は `ContentTypeIdentifier::PATTERN` と同じ — 外から別の語を差し込めない。
     */
    Route::post('content-types/reorder', [ContentTypeAdminController::class, 'reorder'])
        ->middleware('site.build')->name('content-types.reorder');
    Route::get('content-types/{type}/edit', [ContentTypeAdminController::class, 'edit'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->middleware('site.build')->name('content-types.edit');
    Route::patch('content-types/{type}', [ContentTypeAdminController::class, 'update'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->middleware('site.build')->name('content-types.update');
    /**
     * 「記事を書けるようにする」1 クリック（WP 一周 2026-09-03）。
     * `{type}` より前に置く必要は無い（`quick-start` は型 ID の綴りに合致しない）。
     */
    Route::post('content-types/{type}/quick-start', [ContentTypeAdminController::class, 'quickStart'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->name('content-types.quick-start');
    Route::post('content-types/{type}/archive', [ContentTypeAdminController::class, 'archive'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->middleware('site.build')->name('content-types.archive');
    Route::post('content-types/{type}/restore', [ContentTypeAdminController::class, 'restore'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->middleware('site.build')->name('content-types.restore');
    // 型側のコメント利用可否（Phase 9.7 / STAGE 20E）。**受付の route はここでは作らない**
    Route::post('content-types/{type}/comments', [ContentTypeAdminController::class, 'comments'])
        ->where('type', '[a-z][a-z0-9_]{1,39}')->middleware('site.build')->name('content-types.comments');

    /*
     * カスタムフィールド（Field Group — Phase 9.5-A / 9.5-B・ADR-0015 / ADR-0016）。
     *
     * **物理削除の route は置かない**（アーカイブと復元だけ）。定義も投稿の値も
     * 消さないという契約を route の形で表す。破壊的操作は DELETE 動詞ではなく
     * `POST .../archive` + 確認トークンに揃える（利用者の管理と同じ作法）。
     *
     * `reorder` は `{id}` より先に置く（`field-groups/reorder` が ULID として
     * 食われないようにする）。
     */
    Route::get('field-groups', [FieldGroupAdminController::class, 'index'])->middleware('site.build')->name('field-groups.index');
    Route::get('field-groups/create', [FieldGroupAdminController::class, 'create'])->middleware('site.build')->name('field-groups.create');
    Route::post('field-groups', [FieldGroupAdminController::class, 'store'])->middleware('site.build')->name('field-groups.store');
    Route::post('field-groups/reorder', [FieldGroupAdminController::class, 'reorder'])
        ->middleware('site.build')->name('field-groups.reorder');
    Route::get('field-groups/{id}', [FieldGroupAdminController::class, 'edit'])
        ->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('site.build')->name('field-groups.edit');
    Route::patch('field-groups/{id}', [FieldGroupAdminController::class, 'update'])
        ->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->name('field-groups.update');
    Route::post('field-groups/{id}/archive', [FieldGroupAdminController::class, 'archive'])
        ->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('site.build')->name('field-groups.archive');
    Route::post('field-groups/{id}/restore', [FieldGroupAdminController::class, 'restore'])
        ->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('site.build')->name('field-groups.restore');

    /*
     * relation / relations の候補（Phase 9.5-C / ADR-0017 §6）。
     *
     * **管理画面の内部 route だけ**。公開 API にも MCP にも足さない。
     * 実体は既存の content.list で、新しい operation も capability も作らない。
     * 候補は検索付きで少しずつ取りに来る（全件を初期 HTML へ入れない）。
     */
    Route::get('relations/{type}/options', RelationOptionController::class)
        ->where('type', '[a-z][a-z0-9_]*')->name('relations.options');

    /*
     * CMS が作った collection 型の Schema 駆動 CRUD。
     *
     * **type ごとに Controller も route ファイルも増やさない。**
     * 実行時の authoritative な一覧（`managedBy: "cms"` の schema）から
     * news / works と同じ 6 本を生成する。type は URL のパラメータではなく
     * **route 名**（`admin.<type>.<action>`）に埋まっているので、
     * 外から未登録 type を差し込めない。
     */
    foreach (array_keys(CmsManagedContentTypes::discover(app(SchemaRegistry::class))) as $base) {
        Route::get($base, [ManagedContentController::class, 'index'])->name("{$base}.index");
        Route::get("{$base}/create", [ManagedContentController::class, 'create'])->name("{$base}.create");
        Route::post($base, [ManagedContentController::class, 'store'])->name("{$base}.store");
        Route::get("{$base}/{id}/edit", [ManagedContentController::class, 'edit'])
            ->whereNumber('id')->name("{$base}.edit");
        Route::put("{$base}/{id}", [ManagedContentController::class, 'update'])
            ->whereNumber('id')->name("{$base}.update");
        Route::delete("{$base}/{id}", [ManagedContentController::class, 'destroy'])
            ->whereNumber('id')->name("{$base}.destroy");

        /*
         * ゴミ箱への出し入れ（Phase 9.8 / STAGE 21C）。
         *
         * **画面はまだ作らない。** ここで足すのは backend の契約だけで、
         * ゴミ箱一覧もボタンもダイアログも STAGE 21E である。
         * 完全削除（purge）の route は置かない — backup ができるまで作らない（OG8）。
         */
        Route::post("{$base}/{id}/trash", [ManagedContentController::class, 'trash'])
            ->whereNumber('id')->name("{$base}.trash");
        // 元の記事を残して、新しい下書きへ複製する。
        Route::post("{$base}/{id}/duplicate", [ManagedContentController::class, 'duplicate'])
            ->whereNumber('id')->name("{$base}.duplicate");
        Route::post("{$base}/{id}/restore", [ManagedContentController::class, 'restore'])
            ->whereNumber('id')->name("{$base}.restore");

        /*
         * 履歴と、過去の版への復元（Phase 9.8 / STAGE 21D）。
         *
         * **AI へは公開しない**（`OperationCatalog` にも MCP にも載せない）。
         * 内容を丸ごと置き換える操作なので、まずは人の操作に留める。
         * 画面（履歴一覧・差分表示）は STAGE 21E。
         */
        // 編集中の内容を履歴へ積む（Phase 9.15 / STAGE 28B）。**記事本体は書き換えない**
        Route::post("{$base}/{id}/autosave", [ManagedContentController::class, 'autosave'])
            ->whereNumber('id')->name("{$base}.autosave");
        // 保存済みの下書きを公開と同じ見た目で確かめる（Phase 9.15 / STAGE 28A）。
        // **公開の口を持たない型でも route は置く** — 「無い」ことを画面で言うため
        Route::get("{$base}/{id}/preview", [ManagedContentController::class, 'preview'])
            ->whereNumber('id')->name("{$base}.preview");
        Route::get("{$base}/{id}/revisions", [ManagedContentController::class, 'revisions'])
            ->whereNumber('id')->name("{$base}.revisions");
        Route::post("{$base}/{id}/revisions/{revision}/restore", [ManagedContentController::class, 'revisionRestore'])
            ->whereNumber('id')->whereNumber('revision')->name("{$base}.revision-restore");
    }

    /*
     * 利用者の管理（PHASE WP-BASELINE / STAGE #18A — SPEC §1A.10）。
     *
     * **物理削除の route は置かない**（無効化と再有効化だけ）。過去の監査ログの
     * actor を失わないため、利用者は消さないという契約を route の形で表す。
     * 破壊的操作は DELETE 動詞ではなく `POST .../deactivate` + 確認トークンに揃える。
     */
    Route::get('users', [UserAdminController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserAdminController::class, 'create'])->name('users.create');
    Route::post('users', [UserAdminController::class, 'store'])->name('users.store');
    Route::get('users/{id}', [UserAdminController::class, 'edit'])->whereNumber('id')->name('users.edit');
    Route::patch('users/{id}', [UserAdminController::class, 'update'])->whereNumber('id')->name('users.update');
    Route::post('users/{id}/role', [UserAdminController::class, 'changeRole'])->whereNumber('id')->name('users.role');
    Route::post('users/{id}/deactivate', [UserAdminController::class, 'deactivate'])
        ->whereNumber('id')->name('users.deactivate');
    Route::post('users/{id}/reactivate', [UserAdminController::class, 'reactivate'])
        ->whereNumber('id')->name('users.reactivate');
    Route::post('users/{id}/invitation', [UserAdminController::class, 'resendInvitation'])
        ->whereNumber('id')->name('users.invitation');
    // 手動受け渡し用のパスワード設定 URL を出し直す（STAGE #18B）。
    // 発行のたびに前の URL は無効になる
    Route::post('users/{id}/setup-link', [UserAdminController::class, 'issueSetupLink'])
        ->whereNumber('id')->name('users.setup-link');
    /*
     * 発行した URL の受け渡し画面（STAGE #18B）。
     *
     * **secret を含まない固定 URL**。実体は暗号化した封筒として
     * session に預けてあり、この画面が 1 度だけ取り出して消す。
     * `users/{id}` は `whereNumber` なので、この文字列 path とは衝突しない。
     */
    Route::get('users/setup-link', [UserAdminController::class, 'showSetupLink'])
        ->name('users.setup-link.show');
    Route::get('users/{id}/activity', [UserAdminController::class, 'activity'])
        ->whereNumber('id')->name('users.activity');

    // 操作履歴（全体）。CSV は現在の絞り込みをそのまま反映する
    Route::get('activity', [ActivityAdminController::class, 'index'])->name('activity.index');
    Route::get('activity/export', [ActivityAdminController::class, 'export'])->name('activity.export');

    // メディア（アップロード → alt 編集 → 選択のみのシンプル UX — ADR-0006）
    Route::get('media', [AdminMediaController::class, 'index'])->name('media.index');
    Route::get('media/select', [AdminMediaController::class, 'select'])->name('media.select');
    // 専用の編集ページ（modal 廃止 — F-UX7）と画像差し替え（F-UX6）
    Route::get('media/{id}', [AdminMediaController::class, 'edit'])->whereNumber('id')->name('media.edit');
    Route::post('media/{id}/replace', [AdminMediaController::class, 'replace'])->whereNumber('id')->name('media.replace');
    Route::post('media', [AdminMediaController::class, 'store'])->name('media.store');
    Route::patch('media/{id}', [AdminMediaController::class, 'update'])->whereNumber('id')->name('media.update');
    Route::delete('media/{id}', [AdminMediaController::class, 'destroy'])->whereNumber('id')->name('media.destroy');

    /*
     * ページ（Phase 9.3B / #19 — SPEC §V.2-(1)）。**ちょうど 10 本**
     * （Phase 9.6 / P0-C2 で Visual Page Editor の**画面**を 1 本追加。GET だけ）。
     *
     * mutation は既存 API を保存経路にせず、Admin Controller から
     * 同じ Application Service を `OperationContext::admin()` で呼ぶ（§V.2-(2)）。
     * 物理削除・複製・document の直接編集・共有プレビュー token は置かない。
     * **document の編集だけは admin route を持たず**、同一オリジンの操作 API
     * （`page.document.patch`）が唯一の経路になる（`docs/PHASE9_6_SPEC.md` §5.1）。
     */
    Route::get('pages', [PageAdminController::class, 'index'])->name('pages.index');
    Route::get('pages/create', [PageAdminController::class, 'create'])->name('pages.create');
    Route::post('pages', [PageAdminController::class, 'store'])->name('pages.store');
    Route::get('pages/{id}/edit', [PageAdminController::class, 'edit'])->whereNumber('id')->name('pages.edit');
    Route::patch('pages/{id}', [PageAdminController::class, 'update'])->whereNumber('id')->name('pages.update');
    Route::get('pages/{id}/editor', [PageAdminController::class, 'editor'])->whereNumber('id')->name('pages.editor');
    // ページの作成の画面の URL の候補（読みはサーバー。UX 一周 19 周目）
    Route::get('pages/slug-preview', [PageAdminController::class, 'slugPreview'])->name('pages.slug-preview');
    Route::get('pages/{id}/preview', [PageAdminController::class, 'preview'])->whereNumber('id')->name('pages.preview');
    Route::post('pages/{id}/publish', [PageAdminController::class, 'publish'])->whereNumber('id')->name('pages.publish');
    // 公開・予約を取り消し、本文を残して下書きへ戻す。
    // 複製は公開しない新しい下書きを作る。
    Route::post('pages/{id}/duplicate', [PageAdminController::class, 'duplicate'])->whereNumber('id')->name('pages.duplicate');
    Route::post('pages/{id}/unpublish', [PageAdminController::class, 'unpublish'])->whereNumber('id')->name('pages.unpublish');
    Route::post('pages/{id}/trash', [PageAdminController::class, 'trash'])->whereNumber('id')->name('pages.trash');
    Route::post('pages/{id}/restore', [PageAdminController::class, 'restore'])->whereNumber('id')->name('pages.restore');

    /*
     * 同梱の見本ページを非公開にする／戻す（UX 一周 2026-09-27）。
     * 公開前チェックが「消してください」と言う見本を、画面から消せるようにする。
     * **`pages.*` の本数は固定なので別の名前で置く。**
     */
    // 最初に業種を聞く入口（UX 一周 2026-09-28）。選んだ業種の形にサイトを合わせる
    Route::post('starter-page', StarterPageAdminController::class)->name('starter-page.create');
    Route::post('industry', IndustryAdminController::class)->name('industry.set');

    Route::post('sample-pages/{route}/hide', [SamplePageAdminController::class, 'hide'])
        ->where('route', '[a-z]+')->name('sample-pages.hide');
    Route::post('sample-pages/{route}/show', [SamplePageAdminController::class, 'show'])
        ->where('route', '[a-z]+')->name('sample-pages.show');
    // 見本のトップの代わりに、作ったページをトップにする（UX 一周 7 周目 R7-4）
    Route::post('sample-pages/home/front/{id}', [SamplePageAdminController::class, 'front'])
        ->whereNumber('id')->name('sample-pages.front');

    /*
     * 再利用 component（Phase 9.6 / P0-C7・SPEC §4.5）。
     *
     * **読む口だけ置く。** 作る・直す・公開する・しまうは api/v1 を呼ぶ
     * （編集画面は Canvas / Layers / Inspector を流用しつつ、書き込みだけ
     * `/api/v1/components/reusable/...` へ送る — §4.5 の「編集の口」）。
     *
     * **切り離しの口はここにも無い。** ページの document を変える操作なので
     * `page.document.patch` の command として入る。
     */
    Route::get('reusable-components', [ReusableComponentAdminController::class, 'index'])
        ->middleware('site.build')->name('reusable-components.index');
    Route::get('reusable-components/{id}', [ReusableComponentAdminController::class, 'editor'])
        ->middleware('site.build')->name('reusable-components.editor');
    Route::get('reusable-components/{id}/preview', [ReusableComponentAdminController::class, 'preview'])
        ->middleware('site.build')->name('reusable-components.preview');

    /*
     * 分類（Phase 9.3B / #16 — SPEC §R）。
     *
     * mutation は **api/v1 を呼ばず**、Admin Controller から同じ Application Service を
     * `OperationContext::admin()` で呼ぶ（AD-6）。
     * **taxonomy 自体の作成・更新・削除 route は置かない**（§P.2 — Phase 9.4）。
     * `{key}` は taxonomy key。**taxonomy の ULID を URL へ露出させない**。
     * 破壊的操作は DELETE 動詞ではなく `POST .../delete` + 確認トークンに揃える。
     */
    /*
     * メニュー / ナビゲーション（Phase 9.9 / P0-4・SPEC §F）。
     *
     * `{key}` はメニューの key（`header` など）。id を URL へ出さない。
     * 破壊的操作は DELETE 動詞ではなく `POST .../delete` + 確認トークンに揃える
     * （既存 admin route に DELETE 動詞が 1 本も無いため）。
     */
    /*
     * リダイレクトと 404 の記録（Phase 9.10 / P0-5・SPEC §G）。
     *
     * `simulate` は **何も変えない**ので GET。
     * 破壊的操作は DELETE 動詞ではなく `POST .../delete` + 確認トークンに揃える。
     */
    /*
     * backup（Phase 9.11 / P0-6・SPEC §H）。
     *
     * 取得は **別プロセス**で行う（2026-09-09・OG3 改訂）。`POST backups` は起動と記録だけで
     * 返り、進みは `GET backups/runs/{run}` が答える。HTTP の時間制限の中で取らない、
     * という OG3 の理由はそのまま守る。取得・進み・復元・持ち出しは Owner だけ（判定は operation 側）。
     */
    Route::get('backups', [BackupAdminController::class, 'index'])->name('backups.index');
    // 内容の持ち出しは全経路でOwnerを検査する。処理自体はHTTPから切り離す。
    Route::get('exports', [ContentExportController::class, 'index'])->name('exports.index');
    Route::post('exports', [ContentExportController::class, 'store'])->middleware('throttle:6,1')->name('exports.store');
    Route::get('exports/{id}', [ContentExportController::class, 'status'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->name('exports.status');
    Route::get('exports/{id}/download', [ContentExportController::class, 'download'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->name('exports.download');
    Route::post('backups', [BackupAdminController::class, 'store'])->name('backups.store');
    Route::get('backups/runs/{run}', [BackupAdminController::class, 'runStatus'])
        ->where('run', '[a-z0-9][a-z0-9-]*')->name('backups.runs.show');
    Route::get('backups/{id}/verify', [BackupAdminController::class, 'verify'])
        ->where('id', '[a-z0-9][a-z0-9-]*')->name('backups.verify');
    Route::get('backups/{id}/download', [BackupAdminController::class, 'download'])
        ->where('id', '[a-z0-9][a-z0-9-]*')->name('backups.download');
    Route::post('backups/{id}/restore', [BackupAdminController::class, 'restore'])
        ->where('id', '[a-z0-9][a-z0-9-]*')->name('backups.restore');
    Route::post('backups/{id}/delete', [BackupAdminController::class, 'destroy'])
        ->where('id', '[a-z0-9][a-z0-9-]*')->name('backups.delete');

    /*
     * 本文の一括置換（P1 ⑧ / GUI 化・2026-09-10）。**Owner だけ。MCP へは出さない。**
     *
     * 予告（`preview`）は 1 文字も変えないが、本文の抜粋を返すので同じ認可を通す。
     * 実行（`apply`）は予告が出した token を `X-Confirmation` で受ける。判定は operation 側
     * （`ReplaceContent`）— 画面で入口を隠すのは補助でしかない。
     * **`site.build` の関所は付けない。** 一括置換は `content.replace` を制作者だけに持たせて
     * 閉じている（2026-09-28）。関所で先に弾くと、拒否が監査に残らなくなる。
     */
    Route::get('content-replace', [ContentReplaceAdminController::class, 'index'])->name('content-replace.index');
    Route::post('content-replace/preview', [ContentReplaceAdminController::class, 'preview'])
        ->middleware('throttle:30,1')->name('content-replace.preview');
    Route::post('content-replace/apply', [ContentReplaceAdminController::class, 'apply'])
        ->middleware('throttle:10,1')->name('content-replace.apply');

    Route::get('redirects', [RedirectAdminController::class, 'index'])->middleware('site.build')->name('redirects.index');
    Route::get('redirects/simulate', [RedirectAdminController::class, 'simulate'])
        ->middleware('site.build')->name('redirects.simulate');
    Route::get('not-found', [RedirectAdminController::class, 'notFound'])->middleware('site.build')->name('redirects.not-found');
    Route::post('redirects', [RedirectAdminController::class, 'store'])->middleware('site.build')->name('redirects.store');
    Route::patch('redirects/{id}', [RedirectAdminController::class, 'update'])
        ->whereNumber('id')->middleware('site.build')->name('redirects.update');
    Route::post('redirects/{id}/delete', [RedirectAdminController::class, 'destroy'])
        ->whereNumber('id')->middleware('site.build')->name('redirects.delete');
    Route::post('not-found/{id}/resolve', [RedirectAdminController::class, 'resolve'])
        ->whereNumber('id')->middleware('site.build')->name('redirects.resolve');

    /*
     * フォーム（Phase 9.12 / P0-8・SPEC §D）。
     *
     * **`forms/new` を `forms/{key}` より先に置く。** 逆にすると
     * key に `new` を持つフォームの編集画面へ行けなくなる（`{key}` が先に当たる）。
     * 受信は `forms/{key}/submissions` に置き、フォームの定義とは別の権限で守る
     * （定義は SettingsManage、受信は ContactRead / ContactWrite — §E.5）。
     */
    Route::get('forms', [FormAdminController::class, 'index'])->name('forms.index');
    Route::get('forms/new', [FormAdminController::class, 'create'])->name('forms.create');
    Route::post('forms', [FormAdminController::class, 'store'])->name('forms.store');
    Route::prefix('forms/{key}')
        ->where(['key' => '[a-z][a-z0-9_-]*'])
        ->group(function (): void {
            Route::get('/', [FormAdminController::class, 'edit'])->name('forms.edit');
            Route::patch('/', [FormAdminController::class, 'update'])->name('forms.update');
            Route::post('delete', [FormAdminController::class, 'destroy'])->name('forms.delete');
            // 共有している入力項目を、このフォームだけのものにする（B-31）
            Route::post('fields/detach', [FormAdminController::class, 'detachFields'])
                ->name('forms.fields.detach');
            Route::get('submissions', [FormAdminController::class, 'submissions'])->name('forms.submissions');
            Route::get('submissions/export', [FormAdminController::class, 'export'])->name('forms.export');
            Route::get('submissions/{id}', [FormAdminController::class, 'submission'])
                ->whereNumber('id')->name('forms.submission');
            Route::patch('submissions/{id}', [FormAdminController::class, 'updateSubmission'])
                ->whereNumber('id')->name('forms.submission.update');
            /*
             * 受信を消す（R-12 / 2026-08-30）。**個人情報なので消せることが要る。**
             * `POST` なのは削除の確認トークンを載せるため（`forms.delete` と同じ作法）。
             */
            /*
             * 通知メールの送り直し（WP 一周 10 周目 2026-09-04）。
             * 届かなかったことに気づいても、送り直せなければ意味が無い。
             */
            Route::post('submissions/{id}/resend', [FormAdminController::class, 'resendNotification'])
                ->whereNumber('id')->name('forms.submissions.resend');
            Route::post('submissions/{id}/delete', [FormAdminController::class, 'destroySubmission'])
                ->whereNumber('id')->name('forms.submission.delete');
        });

    /*
     * コメント（Phase 9.13 / P0-9・SPEC §D）。
     *
     * **型ごとに route を増やさない。** 型を 10 個作っても本数は変わらない。
     * 承認は `CommentModerate`、記事ごとの受付は `ContentWrite`（記事の設定の一部）。
     */
    Route::get('comments', [CommentAdminController::class, 'index'])->name('comments.index');
    Route::post('comments/status', [CommentAdminController::class, 'status'])->name('comments.status');
    Route::get('comments/{id}', [CommentAdminController::class, 'show'])
        ->whereNumber('id')->name('comments.show');
    Route::post('comments/{id}/reply', [CommentAdminController::class, 'reply'])
        ->whereNumber('id')->name('comments.reply');
    Route::post('comments/entry/{contentType}/{contentId}', [CommentAdminController::class, 'entryOpen'])
        ->where(['contentType' => '[a-z][a-z0-9_-]*', 'contentId' => '[0-9]+'])
        ->name('comments.entry');

    Route::get('menus', [MenuAdminController::class, 'index'])->name('menus.index');
    Route::get('menus/{key}', [MenuAdminController::class, 'edit'])
        ->where('key', '[a-z][a-z0-9_-]*')->name('menus.edit');
    Route::patch('menus/{key}', [MenuAdminController::class, 'update'])
        ->where('key', '[a-z][a-z0-9_-]*')->name('menus.update');
    Route::post('menus/{key}/delete', [MenuAdminController::class, 'destroy'])
        ->where('key', '[a-z][a-z0-9_-]*')->name('menus.delete');
    // 投稿の候補（型ごとに引く。一覧を全部積まない）
    Route::get('menu-entries/{type}', [MenuAdminController::class, 'entries'])
        ->where('type', '[a-z][a-z0-9_]*')->name('menus.entries');

    Route::get('taxonomies', [TaxonomyAdminController::class, 'index'])->name('taxonomies.index');

    /*
     * 分類の**定義**の管理（Phase 9.7 / STAGE 20D）。
     *
     * **物理削除の route は 1 本だけ**で、通るのは「一度も使っていない定義」に限る
     * （項目・割り当てを持つ分類は無効にするしかない）。
     * `create` は `{key}` より先に置く（`taxonomies/create` が key として食われないように）。
     * `{key}` の文法は `TaxonomyKey` と同じ — 外から別の語を差し込めない。
     */
    Route::get('taxonomies/create', [TaxonomyAdminController::class, 'create'])->name('taxonomies.create');
    Route::post('taxonomies', [TaxonomyAdminController::class, 'store'])->name('taxonomies.store');
    Route::get('taxonomies/{key}/edit', [TaxonomyAdminController::class, 'edit'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.edit');
    Route::patch('taxonomies/{key}', [TaxonomyAdminController::class, 'update'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.update');
    Route::post('taxonomies/{key}/archive', [TaxonomyAdminController::class, 'archive'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.archive');
    Route::post('taxonomies/{key}/restore', [TaxonomyAdminController::class, 'restore'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.restore');
    Route::post('taxonomies/{key}/remove', [TaxonomyAdminController::class, 'remove'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.remove');
    Route::post('taxonomies/{key}/attach', [TaxonomyAdminController::class, 'attach'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.attach');
    Route::post('taxonomies/{key}/detach', [TaxonomyAdminController::class, 'detach'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.detach');

    Route::get('taxonomies/{key}', [TaxonomyAdminController::class, 'show'])
        ->where('key', '[a-z][a-z0-9_]*')->name('taxonomies.show');
    Route::post('taxonomies/{key}/terms', [TaxonomyAdminController::class, 'storeTerm'])->name('taxonomies.terms.store');
    Route::post('taxonomies/{key}/terms/reorder', [TaxonomyAdminController::class, 'reorderTerms'])
        ->name('taxonomies.terms.reorder');
    Route::patch('terms/{id}', [TaxonomyAdminController::class, 'updateTerm'])->name('terms.update');
    Route::post('terms/{id}/delete', [TaxonomyAdminController::class, 'deleteTerm'])->name('terms.delete');

    // submissions kind: 閲覧と状態変更のみ
    /*
     * 公開前チェック（2026-08-30）。**判定は前から在ったが画面が無かった** —
     * `php artisan site:readiness` しか入口が無く、編集担当者は触れなかった。
     * ダッシュボードの「はじめに」④ が指す先である。
     */
    Route::get('readiness', ReadinessAdminController::class)->name('readiness');

    Route::get('contact', [AdminContactController::class, 'index'])->name('contact.index');
    Route::get('contact/{id}', [AdminContactController::class, 'show'])->whereNumber('id')->name('contact.show');
    Route::patch('contact/{id}/status', [AdminContactController::class, 'updateStatus'])->whereNumber('id')->name('contact.status');

    // settings kind
    Route::get('site', [AdminSiteController::class, 'edit'])->name('site.edit');
    Route::put('site', [AdminSiteController::class, 'update'])->name('site.update');

    // 通知設定 + Push subscription（本人の subscription のみ操作可能）
    Route::get('notifications', [NotificationSettingsController::class, 'show'])->name('notifications.show');
    Route::put('notifications/preferences', [NotificationSettingsController::class, 'update'])->name('notifications.preferences');
    Route::post('notifications/subscriptions', [PushSubscriptionController::class, 'store'])->name('notifications.subscribe');
    Route::delete('notifications/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('notifications.unsubscribe');
});
