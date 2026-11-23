<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Permalink\ContentPermalink;
use App\Support\BundledPages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;

/**
 * サイトマップ（Project 層）。
 *
 * `news` / `works` は **kit の例**であって、新しく生成するサイトには配られない
 * （Phase 9.7 / STAGE 20B・SPEC §C）。**無い URL を載せない**し、500 にもしない。
 */
final class SitemapController
{
    public function __invoke(
        ContentTypeSettingsResolver $settings,
        ContentTypeLifecyclePolicy $lifecycle,
        ContentTypeResolver $types,
        ContentPermalink $permalinks,
        FrontPage $frontPage,
    ): Response {
        $front = $frontPage->resolve();
        $urls = [
            ['loc' => route('home'), 'lastmod' => null],
        ];
        /*
         * Project isolation: import されていない標準ページ（404 になるもの）は載せない。
         * **出してよいかは `BundledPages` が決める**（D-1）。
         */
        foreach (['about', 'service'] as $route) {
            if (app(BundledPages::class)->isAvailable($route)) {
                $urls[] = ['loc' => route($route), 'lastmod' => null];
            }
        }
        if (RouteFacade::has('works.index')) {
            $urls[] = ['loc' => route('works.index'), 'lastmod' => null];
        }
        if (RouteFacade::has('news.index')) {
            $urls[] = ['loc' => route('news.index'), 'lastmod' => null];
        }
        $urls[] = ['loc' => route('contact.show'), 'lastmod' => null];

        /*
         * 取り込みで起こしたページ（`routes/imported.php` の `Route::view`）。
         *
         * 🔴 **これが抜けていた**（2026-08-28・実案件で実測。`/company` と
         * `/recruit` の 2 枚が sitemap に無かった）。**リンクからは辿れるので
         * 画面を見ても気づけない**が、**検索から入る人には無いのと同じ**。
         * 会社概要と求人は、外から探して来る人が一番多いページ。
         *
         * ▷ **ページ名を書かない。**案件ごとに違うので、名前で書くと他所で効かない。
         */
        foreach ($this->standaloneViewUrls() as $loc) {
            $urls[] = ['loc' => $loc, 'lastmod' => null];
        }

        // 管理画面で公開したページも検索へ届ける。公開版が無いページ・公開予約中のページは載せない
        // （見える条件は `Page::scopePubliclyVisible()` 1 か所 — route と同じ規則）。
        foreach (Page::query()->where('managed_by', Page::MANAGED_BY_DOCUMENT)
            ->publiclyVisible()
            ->whereHas('publishedVersion')->cursor() as $page) {
            if (($page->seo['noindex'] ?? false) || $page->getKey() === $front?->getKey()) {
                continue;
            }

            $urls[] = ['loc' => url('/'.$page->slug), 'lastmod' => $page->updated_at?->toAtomString()];
        }

        // トップに指定したページの検索除外も守り、別名 URL は重複登録しない。
        if ($front !== null && ($front->seo['noindex'] ?? false)) {
            $urls = array_values(array_filter($urls, static fn (array $item): bool => $item['loc'] !== route('home')));
        }

        /*
         * module が自前の公開面を持つ型（`news.show` のような route を登録するもの）。
         *
         * **型名を書かない**（Phase 9.7 / STAGE 20F）。`news` / `works` は kit の例で、
         * 新しく生成するサイトには配られない。名前で書くと、配られていない環境では
         * 静的解析すら通らなくなる。「`<型>.show` という route が在るか」で判断すれば、
         * 在る環境では今までどおり載り、無い環境では自然に消える。
         */
        foreach ($types->availableTypes() as $id) {
            if (! RouteFacade::has($id.'.show')) {
                continue;
            }

            foreach ($this->publishedEntries($types, $id) as $entry) {
                $entrySlug = $entry->getAttribute('slug');

                if (! is_string($entrySlug) || $entrySlug === '') {
                    continue;
                }

                $updatedAt = $entry->getAttribute('updated_at');

                // **形は `ContentPermalink` が決める**（P1 ③ 段 1）。載せる条件は上の guard のまま
                $loc = $permalinks->entry($id, $entry);

                if ($loc === null) {
                    continue;
                }

                $urls[] = [
                    'loc' => $loc,
                    'lastmod' => $updatedAt instanceof Carbon ? $updatedAt->toAtomString() : null,
                ];
            }
        }

        /*
         * 公開した投稿タイプ（Phase 9.7 / STAGE 20C）。
         *
         * **公開可否は `ContentTypeLifecyclePolicy` だけが決める。** 無効にした型も、
         * 公開していない型も、未公開の entry も載せない — sitemap は
         * 「開ける URL の一覧」であって、「在るかもしれない URL の一覧」ではない。
         */
        foreach ($settings->stored() as $id => $setting) {
            if ($setting->publicSlug === null || ! $types->knows($id) || ! $lifecycle->isPublic($id)) {
                continue;
            }

            if ($setting->flag('has_archive', true)) {
                $index = $permalinks->index($id);

                if ($index !== null) {
                    $urls[] = ['loc' => $index, 'lastmod' => null];
                }
            }

            if (! $setting->flag('publicly_queryable', true)) {
                continue;
            }

            foreach ($this->publishedEntries($types, $id) as $entry) {
                $entrySlug = $entry->getAttribute('slug');

                if (! is_string($entrySlug) || $entrySlug === '') {
                    continue;
                }

                $updatedAt = $entry->getAttribute('updated_at');

                $loc = $permalinks->entry($id, $entry);

                if ($loc === null) {
                    continue;
                }

                $urls[] = [
                    'loc' => $loc,
                    'lastmod' => $updatedAt instanceof Carbon ? $updatedAt->toAtomString() : null,
                ];
            }
        }

        // 同じ URL を 2 度載せない（module の公開面と公開 slug が重なった場合）
        $urls = array_values(array_column($urls, null, 'loc'));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>';
            if ($url['lastmod'] !== null) {
                $xml .= '<lastmod>'.e($url['lastmod']).'</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * 公開済みの entry。
     *
     * `published()` は `HasContentSchema` の scope で、schema 駆動のモデルは必ず持つ。
     * 汎用モデルの静的型からは見えないので、ここで 1 か所だけ橋渡しする。
     *
     * @return Collection<int, Model>
     */
    private function publishedEntries(ContentTypeResolver $types, string $contentTypeId): Collection
    {
        /** @var Builder<Model> $query */
        $query = $types->newModel($contentTypeId)->newQuery()->scopes('published');

        /** @var Collection<int, Model> $entries */
        $entries = $query->orderByDesc('published_at')->get();

        return $entries;
    }

    /** import されたページ view の有無（実行時判定 — PublicSiteController と同趣旨） */

    /**
     * `Route::view` で登録された、引数の無い公開ページの URL。
     *
     * `template apply` が起こす標準規約外ページはこの形で登録される。
     * **`view` の既定値を持つ route** かどうかで見分けるので、ページ名に依存しない。
     *
     * ▷ **開けないものは載せない。**view が無ければ 500 になるので、実在も確かめる。
     *
     * @return list<string>
     */
    private function standaloneViewUrls(): array
    {
        $urls = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $view = $route->defaults['view'] ?? null;

            if (! is_string($view) || $view === '' || $route->parameterNames() !== []) {
                continue;
            }

            if (! view()->exists($view)) {
                continue;
            }

            $urls[] = URL::to($route->uri());
        }

        return $urls;
    }
}
