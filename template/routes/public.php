<?php

use AgentCms\Core\Http\PublicCspMiddleware;
use App\Http\Controllers\ContactEntryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicCommentController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicSearchController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SitemapController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\Contact\Http\ContactController;
use Modules\Media\Http\MediaDownloadController;

/*
 * `news` / `works` の Controller は **文字列で持つ**（Phase 9.7 / STAGE 20F）。
 * この 2 つは kit の例で、新しく生成するサイトには配られない。`use` で取り込むと、
 * 配られていない環境では静的解析がクラスを解決できずに落ちる。
 */
$newsController = 'Modules\\News\\Http\\NewsController';
$worksController = 'Modules\\Works\\Http\\WorksController';

// 公開サイト（Blade SSR）。5 ページ + News/Works 詳細 + sitemap。
Route::middleware(PublicCspMiddleware::class)->group(function () use ($newsController, $worksController): void {

    // 既存のページslugと衝突しない専用入口。公開の検索だけを受ける。
    Route::get('/_search', PublicSearchController::class)->middleware('throttle:60,1')->name('site.search');
    Route::get('/', [PublicSiteController::class, 'home'])->name('home');
    Route::get('/about', [PublicSiteController::class, 'about'])->name('about');
    Route::get('/service', [PublicSiteController::class, 'service'])->name('service');

    /*
     * `news` / `works` の公開面（Phase 9.7 / STAGE 20B）。
     *
     * **kit の例であって、新しく生成するサイトには配らない**（SPEC §C / OG-A）。
     * module ごと無い環境では route も登録しない — 登録だけして 500 を返さない。
     * 既に生成済みの project では module が在るので、これまでどおり動く。
     *
     * 汎用の投稿タイプの公開面（既定テンプレート 1 枚）は **Stage 20C**（OG-D = (a)）。
     */
    /*
     * 分類ごとの一覧 `/{入口}/{分類のキー}/{項目の綴り}`（R05・2026-09-09）。
     *
     * 汎用の型は fallback（`PublicContentController`）が 3 segment で受けるが、
     * `/news/…` `/works/…` は名前付き route の世界なので **fallback には届かない**
     * （公開スラッグの行が無い型を `ContentPermalink::typeForBase()` は知らない）。
     * 記事の分類名をリンクにする以上、押した先を受ける route が要る。
     * 綴りの形は `TaxonomyKey::PATTERN` / `TermSlug::PATTERN` と同じ。
     */
    $taxonomyPath = ['taxonomy' => '[a-z][a-z0-9_]*', 'term' => '[a-z0-9-]+'];

    if (class_exists($worksController)) {
        Route::get('/works', [$worksController, 'index'])->name('works.index');
        Route::get('/works/{slug}', [$worksController, 'show'])
            ->where('slug', '[a-z0-9-]+')->name('works.show');
        Route::get('/works/{taxonomy}/{term}', [$worksController, 'term'])
            ->where($taxonomyPath)->name('works.term');
    }

    if (class_exists($newsController)) {
        Route::get('/news', [$newsController, 'index'])->name('news.index');
        Route::get('/news/{slug}', [$newsController, 'show'])
            ->where('slug', '[a-z0-9-]+')->name('news.show');
        Route::get('/news/{taxonomy}/{term}', [$newsController, 'term'])
            ->where($taxonomyPath)->name('news.term');
    }

    /*
     * `/contact` の中身は `ContactEntryController` が振り分ける（R-12 Stage 1）。
     * **route 名は残す** — 同梱テーマ・sitemap・既定の導線・送信後の戻り先が
     * この名前を呼んでいる（実測 8 か所）。
     */
    Route::get('/contact', ContactEntryController::class)->name('contact.show');
    Route::post('/contact', [ContactController::class, 'store'])
        ->middleware('throttle:10,1')->name('contact.store');

    /*
     * フォーム（Phase 9.12 / P0-8・SPEC §C.1）。
     *
     * `{key}` は `forms.key` そのもの。**投稿タイプの公開面と違って fallback にしない** —
     * URL の形が `/forms/…` に固定されていて、他の route を奪う余地が無いからである。
     * どのフォームが公開かは要求を受けてから DB で判定する（boot は DB を読まない）。
     *
     * `throttle` は**外側の上限**。フォームごとの細かい上限は `RateLimitCheck` が持つ
     * （honeypot で先に弾かれる要求は内側の上限を消費しないため、外側が要る）。
     */
    Route::prefix('forms/{key}')
        ->where(['key' => '[a-z][a-z0-9_-]{1,63}'])
        ->group(function (): void {
            Route::get('/', [PublicFormController::class, 'show'])->name('forms.show');
            Route::get('/complete', [PublicFormController::class, 'complete'])->name('forms.complete');
            Route::post('/confirm', [PublicFormController::class, 'confirm'])
                ->middleware('throttle:30,1')->name('forms.confirm');
            Route::post('/', [PublicFormController::class, 'store'])
                ->middleware('throttle:30,1')->name('forms.store');
        });

    /*
     * コメントの受付（Phase 9.13 / P0-9・SPEC §C.1）。
     *
     * **投稿の口だけ。** 表示は記事ページの中で行う。一覧や詳細の route を作ると、
     * 承認前の本文へ直リンクできる口ができる。
     * 型ごとに route を増やさないので、型を 10 個作っても 1 本のままである。
     *
     * `throttle` は外側の上限。投稿タイプごとの上限は `RateLimitCheck` が持つ。
     */
    Route::post('comments/{contentType}/{contentId}', [PublicCommentController::class, 'store'])
        ->where(['contentType' => '[a-z][a-z0-9_-]*', 'contentId' => '[0-9]+'])
        ->middleware('throttle:20,1')
        ->name('comments.store');

    Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

    Route::get('/robots.txt', function () {
        // staging は全 UA を Disallow（検索エンジン index 禁止。X-Robots-Tag と二重防御）
        $lines = app()->environment('staging')
            ? ['User-agent: *', 'Disallow: /', '']
            : ['User-agent: *', 'Allow: /', 'Disallow: /admin', 'Disallow: /login', '', 'Sitemap: '.route('sitemap'), ''];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->name('robots');

});

/*
 * 一般ファイルの配信（Phase 9.5-D / SPEC §3.2）。
 *
 * **公開サイトの CSP group には入れない。** この応答は
 * `default-src 'none'; sandbox` を自分で付けるので、上書きされては困る。
 * `{name}` は利用者とブラウザが保存名を読めるようにするためだけの飾りで、
 * 実体の特定には使わない（`{id}` が正本）。
 */
Route::get('/media/file/{id}/{name}', [MediaDownloadController::class, 'show'])
    ->whereNumber('id')
    ->where('name', '.*')
    ->name('media.file');

/*
 * 公開した投稿タイプと、PageDocument で管理されたページ（Phase 9.1D / 9.7 STAGE 20C）。
 *
 * **fallback 1 本で両方を受ける。** 通常の route はすべて先に評価されるため、
 * 既存の Blade 管理ページ・管理画面・認証・healthz を一切奪わない。
 *
 * 投稿タイプごとに route を登録しないのは、**boot が DB を読まない**ためである
 * （どの型が公開かは DB にしかない）。fallback なら route 集合は固定のままで、
 * 公開可否は要求を受けてから判定できる。無効にしても route は消えず、
 * 復元すれば同じ URL がそのまま戻る。
 *
 * 投稿タイプに当たらなければ `PublicPageController` へ渡す。
 * どちらにも当たらなければ 404（通常の未定義 URL と同じ振る舞い）。
 */
Route::fallback(PublicContentController::class)->middleware(PublicCspMiddleware::class);

/*
 * GET 以外で未定義 URL を叩かれたときは **404 のまま**にする。
 * 上の fallback（GET）だけがあると、POST /register などが 405 に変わってしまい、
 * 「存在しない URL」の応答が Phase 9.1D 以前と変わる。CSRF より前に落としたいので
 * この route だけ CSRF を外す（常に 404 で、状態は一切変えない）。
 */
Route::match(['post', 'put', 'patch', 'delete', 'options'], '{missing}', fn () => abort(404))
    ->where('missing', '.*')
    ->fallback()
    ->withoutMiddleware([VerifyCsrfToken::class]);

// health endpoint（デプロイ後の外形検査用。秘密情報を返さない）
Route::get('/healthz', HealthController::class)->name('healthz');
