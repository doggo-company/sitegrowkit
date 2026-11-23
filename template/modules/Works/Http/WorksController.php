<?php

declare(strict_types=1);

namespace Modules\Works\Http;

use AgentCms\Core\Content\PublicSearch;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Taxonomy\TermArchive;
use App\Support\TermArchiveLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Works\Models\Work;

final class WorksController
{
    public function index(Request $request): View
    {
        $term = $request->query('q', '');
        // 公開検索も入力形式を検証し、配列による500を防ぐ。
        abort_unless(is_string($term) && mb_strlen($term) <= 200, 422, '検索語は200文字以内で入力してください');

        $query = Work::published()->with('mainImage.variants');

        // **手で決めた掲載順が第一キー**（並べていなければ全行 0 で、いままでと同じ並び）
        ContentOrder::apply($query, (new Work)->getTable());

        $query->orderByDesc('published_at')->orderByDesc('id');

        // **どう探すかは `PublicSearch` が持つ。** ここは「探す」としか言わない
        PublicSearch::apply($query, Work::schema(), $term);

        return view('works::index', [
            'items' => $query->paginate(12)->withQueryString(),
            'search' => trim($term),
            /*
             * **探せない型で窓を出さない**（B-14）。宣言を失った型では絞り込みが起きず、
             * **押せるのに結果が変わらない**。窓が在ること自体が嘘になる。
             */
            'searchable' => Work::schema()->searchable !== [],

        ]);
    }

    public function show(string $slug, Purifier $purifier): View
    {
        $item = Work::published()->with('mainImage.variants')->where('slug', $slug)->firstOrFail();

        return view('works::show', [
            'item' => $item,
            'body' => $purifier->clean($item->body),
            /*
             * 記事に付いている分類（R05・2026-09-09）。
             *
             * `works.category` は select のまま（Phase 9.3C）だが、分類管理で作った
             * 分類を実績に付けることはできる。付けたものを訪問者に見せる読み手は
             * `news` / 汎用の詳細と同じ（条件を 2 か所に置かない）。
             */
            'taxonomies' => app(TermArchiveLinks::class)->forEntry(Work::schemaId(), (int) $item->getKey()),
        ]);
    }

    /**
     * 分類ごとの一覧 `/works/{分類のキー}/{項目の綴り}`（R05・2026-09-09）。
     *
     * `/works/…` は名前付き route の世界なので fallback には届かない。
     * 条件は `PublicContentController::term()` と同じ（`has_archive` / `TermArchive` / `published`）。
     */
    public function term(string $taxonomy, string $term, ContentTypeSettingsResolver $settings): View
    {
        abort_unless($settings->for(Work::schemaId())->flag('has_archive', true), 404);

        $archive = app(TermArchive::class)->find(Work::schemaId(), $taxonomy, $term);

        abort_if($archive === null, 404);

        $query = Work::published()->with('mainImage.variants')->whereIn('id', $archive['contentIds']);
        ContentOrder::apply($query, (new Work)->getTable());
        $items = $query->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(12)->withQueryString();

        return view('works::term', [
            'items' => $items,
            'archive' => $archive,
        ]);
    }
}
