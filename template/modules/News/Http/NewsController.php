<?php

declare(strict_types=1);

namespace Modules\News\Http;

use AgentCms\Core\Content\PublicSearch;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Taxonomy\TermArchive;
use App\Support\FeaturedImages;
use App\Support\TermArchiveLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\News\Models\News;

final class NewsController
{
    public function index(Request $request): View
    {
        $term = $request->query('q', '');
        // 公開検索も入力形式を検証し、配列による500を防ぐ。
        abort_unless(is_string($term) && mb_strlen($term) <= 200, 422, '検索語は200文字以内で入力してください');

        $query = News::published();

        // **手で決めた掲載順が第一キー**（並べていなければ全行 0 で、いままでと同じ並び）
        ContentOrder::apply($query, (new News)->getTable());

        $query->orderByDesc('published_at')->orderByDesc('id');

        // **どう探すかは `PublicSearch` が持つ。** ここは「探す」としか言わない
        PublicSearch::apply($query, News::schema(), $term);

        $items = $query->paginate(10)->withQueryString();

        return view('news::index', [
            'items' => $items,
            'featuredImages' => app(FeaturedImages::class)->forEntries($items),
            'search' => trim($term),
            /*
             * **探せない型で窓を出さない**（B-14）。宣言を失った型では絞り込みが起きず、
             * **押せるのに結果が変わらない**。窓が在ること自体が嘘になる。
             */
            'searchable' => News::schema()->searchable !== [],

        ]);
    }

    public function show(string $slug, Purifier $purifier): View
    {
        $item = News::published()->where('slug', $slug)->firstOrFail();

        return view('news::show', [
            'item' => $item,
            'featuredImages' => app(FeaturedImages::class)->forEntries([$item]),
            'body' => $purifier->clean($item->body),
            /*
             * 記事に付いている分類（R05・2026-09-09）。
             *
             * 汎用の詳細は 6 周目で出していたが、**`news` は module 専用 route で
             * 描くので 1 文字も出ていなかった**。同じ読み手（`AssignedTerms`）と
             * 同じ押せる先の判定（`TermArchiveLinks`）を通す — 条件を 2 か所に置かない。
             */
            'taxonomies' => app(TermArchiveLinks::class)->forEntry(News::schemaId(), (int) $item->getKey()),
        ]);
    }

    /**
     * 分類ごとの一覧 `/news/{分類のキー}/{項目の綴り}`（R05・2026-09-09）。
     *
     * 汎用の型は fallback（`PublicContentController::term()`）が受けるが、
     * `/news/…` は名前付き route の世界なので、**fallback には届かない**
     * （`ContentPermalink::typeForBase('news')` は公開スラッグの行が無ければ null）。
     * 記事の分類名をリンクにする以上、押した先はここが受ける。
     *
     * 条件は fallback と同じ: `has_archive` を持たない型は分類の一覧も持たず、
     * 無効な分類・アーカイブ済みの項目は `TermArchive` が無いものとして扱う（404）。
     * 公開の条件は `published` scope 1 か所。
     */
    public function term(string $taxonomy, string $term, ContentTypeSettingsResolver $settings): View
    {
        abort_unless($settings->for(News::schemaId())->flag('has_archive', true), 404);

        $archive = app(TermArchive::class)->find(News::schemaId(), $taxonomy, $term);

        abort_if($archive === null, 404);

        $query = News::published()->whereIn('id', $archive['contentIds']);
        ContentOrder::apply($query, (new News)->getTable());
        $items = $query->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(12)->withQueryString();

        return view('news::term', [
            'items' => $items,
            'featuredImages' => app(FeaturedImages::class)->forEntries($items),
            'archive' => $archive,
        ]);
    }
}
