<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Content\PublicSearch;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

/** 公開済みの各投稿タイプとページを、同じ検索窓から探す。 */
final class PublicSearchController
{
    public function __invoke(Request $request, SchemaRegistry $schemas, ContentTypeResolver $types,
        ContentTypeSettingsResolver $settings, ContentTypeLifecyclePolicy $lifecycle,
        ContentPermalink $permalinks, FrontPage $frontPage): Response
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $term = trim((string) ($input['q'] ?? ''));
        $number = (int) ($input['page'] ?? 1);
        $offset = ($number - 1) * 12;
        $total = 0;
        $items = [];
        // 件数と一頁分だけを取得する。全件をPHPへ読み込まない。
        if ($term !== '') {
            $declared = $schemas->declared();
            ksort($declared);
            foreach ($declared as $id => $schema) {
                if (! EditorialCollections::isEditorial($schema) || ! $types->knows($id)
                    || ! $schema->hasField('title') || $schema->searchable === [] || ! $lifecycle->isActive($id)) {
                    continue;
                }
                $setting = $settings->for($id);
                // module固有の公開経路と、CMSが作る公開型の両方に対応する。
                if (! Route::has($id.'.show') && (! $lifecycle->isPublic($id) || ! $setting->flag('publicly_queryable', true))) {
                    continue;
                }
                /** @var Builder<Model> $query */
                $query = $types->newModel($id)->newQuery()->scopes('published');
                PublicSearch::apply($query, $schema, $term);
                $count = $query->count();
                $total += $count;
                if ($offset >= $count) {
                    $offset -= $count;

                    continue;
                }
                if (count($items) < 12) {
                    foreach ($query->orderByDesc('published_at')->orderByDesc('id')->skip($offset)->take(12 - count($items))->get() as $entry) {
                        $url = $permalinks->entry($id, $entry);
                        if ($url !== null) {
                            $items[] = ['title' => (string) $entry->getAttribute('title'), 'url' => $url, 'label' => $setting->labelOr($schema->label)];
                        }
                    }
                }
                $offset = 0;
            }
            // 下書き版は結合しない。ページは公開タイトルと検索説明を対象にする。
            $pages = Page::query()->where('managed_by', Page::MANAGED_BY_DOCUMENT)->publiclyVisible()
                ->whereHas('publishedVersion')
                ->where(fn (Builder $q) => $q->whereNull('seo->noindex')->orWhere('seo->noindex', false))
                ->where(fn (Builder $q) => $q->whereLike('title', '%'.$term.'%')->orWhereLike('seo->description', '%'.$term.'%'));
            $count = $pages->count();
            $total += $count;
            if ($offset < $count && count($items) < 12) {
                $front = $frontPage->resolve();
                foreach ($pages->orderByDesc('id')->skip($offset)->take(12 - count($items))->get() as $page) {
                    $items[] = ['title' => $page->title, 'url' => $front?->id === $page->id ? route('home') : url('/'.$page->slug), 'label' => 'ページ'];
                }
            }
        }
        $results = new LengthAwarePaginator($items, $total, 12, $number, ['path' => route('site.search'), 'query' => ['q' => $term]]);

        return response()->view('public.search', ['title' => 'サイト内検索', 'noindex' => true, 'term' => $term, 'results' => $results])
            ->header('X-Robots-Tag', 'noindex, follow');
    }
}
