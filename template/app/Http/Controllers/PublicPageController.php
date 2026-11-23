<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use App\Support\DocumentPageView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * PageDocument で管理されたページの公開表示（Phase 9.1D）。
 *
 * - **Blade によるサーバー描画**。公開側に Vue runtime を要求しない
 * - **fallback route として登録される**ため、既存の Blade 管理ページ・管理画面・
 *   認証・healthz など、他のすべての route が先に評価される。既存ページを奪わない
 */
final class PublicPageController
{
    public function __invoke(
        Request $request,
        DocumentPageView $documentPage,
        FrontPage $frontPage,
    ): View {
        $slug = trim($request->path(), '/');

        $page = Page::query()
            ->where('slug', $slug)
            ->where('managed_by', Page::MANAGED_BY_DOCUMENT)
            ->first();

        abort_if($page === null || ! $page->isPublicallyVisible(), 404);

        $version = PageVersion::query()->find($page->published_version_id);

        abort_if($version === null, 404);

        /*
         * **トップに指定されたページは、ここでも `/` を正規 URL と名乗る**（R-5）。
         *
         * `/` と `/page-slug` の両方で同じ内容が開ける。どちらも自分を canonical と
         * 名乗ると、検索側から見て**同じ内容の 2 ページ**になる。
         * 301 で片方を潰す案もあるが、**既に配った URL の意味を変える**方向なので採らない
         * （`docs/BACKLOG.md` R-5 / 判断 (A)）。
         */
        $isFrontPage = $frontPage->resolve()?->getKey() === $page->getKey();

        return $documentPage->render($page, $version, $isFrontPage ? url('/') : null);
    }
}
