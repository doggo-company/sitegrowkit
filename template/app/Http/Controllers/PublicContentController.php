<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Content\PublicSearch;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettings;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Permalink\PermalinkEntryRef;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TermArchive;
use App\Support\FeaturedImages;
use App\Support\TermArchiveLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 公開した投稿タイプの汎用公開面（Phase 9.7 / STAGE 20C・SPEC §D.4 / OG-D = (a)）。
 *
 * ## route を増やさない
 *
 * **fallback 1 本で受ける。** 型ごとに route を登録すると、route を組み立てる時点で
 * 「どの型が公開か」を知る必要があり、**boot が DB を読む**ことになる（§0.3-(1)）。
 * fallback なら route 集合は固定のままで、公開かどうかは**要求を受けてから**判定できる。
 * 無効にしても route は消えず、復元すれば**同じ URL がそのまま戻る**。
 *
 * ## ページを奪わない
 *
 * 投稿タイプに当たらない URL は、これまでどおり `PublicPageController` へ渡す。
 * fallback は 1 つしか登録できないので、**ここが両方の入口になる**。
 *
 * ## 描画
 *
 * Blade の SSR だけ。公開側に Vue runtime を要求しない。
 * 型ごとの view があればそれを使い、無ければ最小の既定テンプレートで
 * title / body / published_at を出す。
 */
final class PublicContentController
{
    public function __invoke(
        Request $request,
        SchemaRegistry $schemas,
        ContentTypeSettingsResolver $settings,
        ContentTypeLifecyclePolicy $lifecycle,
        ContentTypeResolver $types,
    ): View {
        $segments = array_values(array_filter(explode('/', trim($request->path(), '/')), static fn (string $s): bool => $s !== ''));
        $permalinks = app(ContentPermalink::class);

        /*
         * **一覧が先。** 入口（`/blog`）は構造に関わらず一覧である。
         * 記事より後に見ると、`slug` 構造の型が入口そのものを記事として奪い得る。
         */
        if (count($segments) === 1) {
            $target = $this->publicType($segments[0], $schemas, $settings, $lifecycle, $types);

            if ($target !== null) {
                return $this->index($target[0], $target[1], $types);
            }
        }

        // **記事。形を読むのは `ContentPermalink`**（P1 ③ 段 2）
        $ref = $permalinks->matchEntry($segments);

        if ($ref !== null) {
            $target = $this->publicType(null, $schemas, $settings, $lifecycle, $types, $ref->type);

            if ($target !== null) {
                return $this->show($target[0], $target[1], $ref, $types);
            }
        }

        /*
         * 分類ごとの一覧（`/{入口}/{分類のキー}/{項目の綴り}`）。
         *
         * 🔴 **記事の判定より後に見る。** こうすると、いまある URL の意味が
         * 1 つも変わらない（足すだけになる）。記事として読めた URL は
         * これまでどおり記事である。
         */
        if (count($segments) === 3) {
            $target = $this->publicType($segments[0], $schemas, $settings, $lifecycle, $types);

            if ($target !== null) {
                $archive = app(TermArchive::class)->find($target[0]->id, $segments[1], $segments[2]);

                if ($archive !== null) {
                    return $this->term($target[0], $target[1], $archive, $types);
                }
            }
        }

        // 投稿タイプではない URL は、これまでどおりページが受ける
        return app()->call(PublicPageController::class);
    }

    /**
     * その slug で公開されている投稿タイプ（無ければ null）。
     *
     * 公開可否の判定は `ContentTypeLifecyclePolicy` に任せる。
     * ここで `status` を読みに行かない（判定を 2 か所に置かない）。
     *
     * @return array{0: ContentSchema, 1: ContentTypeSettings}|null
     */
    private function publicType(
        ?string $slug,
        SchemaRegistry $schemas,
        ContentTypeSettingsResolver $settings,
        ContentTypeLifecyclePolicy $lifecycle,
        ContentTypeResolver $types,
        ?string $knownType = null,
    ): ?array {
        // **入口の segment から型を引くのは `ContentPermalink`**（P1 ③ 段 1）
        $id = $knownType ?? ($slug === null ? null : app(ContentPermalink::class)->typeForBase($slug));

        if ($id !== null) {
            $setting = $settings->for($id);
            $schema = $schemas->declared()[$id] ?? null;

            if ($schema === null || ! EditorialCollections::isEditorial($schema)) {
                return null;
            }

            // モデルを読み込めない型は「公開できる状態」ではない
            if (! $types->knows($id) || ! $lifecycle->isPublic($id)) {
                return null;
            }

            return [$schema, $setting];
        }

        return null;
    }

    private function index(ContentSchema $schema, ContentTypeSettings $setting, ContentTypeResolver $types): View
    {
        // 一覧の URL を持つかは登録属性が決める（既定は `public` に従う）
        abort_unless($setting->flag('has_archive', true), 404);

        // 公開済みの検索対象列だけを探す。配列や長すぎる語は500にせず断る。
        $search = request()->query('q', '');
        abort_unless(is_string($search) && mb_strlen($search) <= 200, 422, '検索語は200文字以内で入力してください');
        $query = $this->publishedQuery($types, $schema->id);
        PublicSearch::apply($query, $schema, $search);
        $items = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)->withQueryString();

        return $this->render($this->viewFor($schema->id, 'index'), [
            'contentType' => $schema,
            'label' => $setting->labelOr($schema->label),
            'slug' => (string) $setting->publicSlug,
            'items' => $items,
            'search' => trim($search),
            'searchable' => $schema->searchable !== [],
            'featuredImages' => app(FeaturedImages::class)->forEntries($items),
            'hasDetail' => $setting->flag('publicly_queryable', true),
        ]);
    }

    /**
     * 分類ごとの一覧（6 周目 2026-09-03）。
     *
     * 一覧の URL を持たない型は、分類の一覧も持たない — 戻る先が無いためである。
     *
     * @param  array{taxonomyKey: string, taxonomyLabel: string, termName: string, termSlug: string, contentIds: list<int>}  $archive
     */
    private function term(
        ContentSchema $schema,
        ContentTypeSettings $setting,
        array $archive,
        ContentTypeResolver $types,
    ): View {
        abort_unless($setting->flag('has_archive', true), 404);

        /*
         * **公開の条件は 1 か所**（`published` scope）。
         * `TermArchive` は割り当てられた id しか返さないので、
         * 下書きが混ざるのはここで止まる。
         */
        $items = $archive['contentIds'] === []
            ? $this->publishedQuery($types, $schema->id)->whereRaw('1 = 0')->paginate(12)->withQueryString()
            : $this->publishedQuery($types, $schema->id)
                ->whereIn('id', $archive['contentIds'])
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate(12)->withQueryString();

        return $this->render($this->viewFor($schema->id, 'term'), [
            'contentType' => $schema,
            'label' => $setting->labelOr($schema->label),
            'slug' => (string) $setting->publicSlug,
            'items' => $items,
            'featuredImages' => app(FeaturedImages::class)->forEntries($items),
            'archive' => $archive,
            'hasDetail' => $setting->flag('publicly_queryable', true),
        ]);
    }

    private function show(ContentSchema $schema, ContentTypeSettings $setting, PermalinkEntryRef $ref, ContentTypeResolver $types): View
    {
        abort_unless($setting->flag('publicly_queryable', true), 404);

        // slug で引く構造なら、slug を持たない型は個別ページを作れない
        abort_unless($ref->column !== 'slug' || $this->hasField($schema, 'slug'), 404);

        $item = $this->publishedQuery($types, $schema->id)
            ->where($ref->column, $ref->value)
            ->first();

        abort_if($item === null, 404);

        /*
         * **URL の日付と、記事の公開月が一致すること。**
         *
         * 照らさないと `/blog/1999/01/hello` でも同じ記事が出て、
         * **1 つの記事に無数の URL** ができる（検索エンジンから見れば別々のページである）。
         */
        if ($ref->yearMonth !== null) {
            $publishedAt = $item->getAttribute('published_at');

            abort_unless(
                $publishedAt instanceof \DateTimeInterface
                    && $publishedAt->format('Y-m') === $ref->yearMonth,
                404,
            );
        }

        return $this->render($this->viewFor($schema->id, 'show'), [
            'contentType' => $schema,
            'label' => $setting->labelOr($schema->label),
            'slug' => (string) $setting->publicSlug,
            'item' => $item,
            'featuredImages' => app(FeaturedImages::class)->forEntries([$item]),
            'body' => $this->bodyOf($schema, $item),
            'hasArchive' => $setting->flag('has_archive', true),
            /*
             * 記事に付いている分類（WP 一周 6 周目 2026-09-03 / 効くもの ②）。
             *
             * 分類管理で作って、記事に付けて、公開できる — **のに訪問者からは
             * 1 文字も見えなかった**。案件でいちばん普通の要望
             * （「お知らせをカテゴリで分けたい」）が、画面上はできたように見えて
             * できない状態だった。
             *
             * 見せるのは**いま使っているものだけ**（`AssignedTerms` が
             * 無効な分類とアーカイブ済みの項目を落とす）。
             * 押せる先は `TermArchiveLinks` が付ける（一覧を持たない型では null）。
             */
            'taxonomies' => app(TermArchiveLinks::class)->forEntry($schema->id, (int) $item->getKey()),
        ]);
    }

    /**
     * 公開済みだけを引く query。
     *
     * `published()` は `HasContentSchema` の scope で、schema 駆動のモデルは必ず持つ。
     * 汎用モデルの静的型からは見えないので、ここで 1 か所だけ橋渡しする。
     *
     * @return Builder<Model>
     */
    private function publishedQuery(ContentTypeResolver $types, string $contentTypeId): Builder
    {
        /** @var Builder<Model> $query */
        $query = $types->newModel($contentTypeId)->newQuery()->scopes('published');
        ContentOrder::apply($query, $query->getModel()->getTable());

        return $query;
    }

    /**
     * 型ごとの view があればそれを使う。**無ければ最小の既定テンプレート**。
     *
     * 「型を公開にしたのに何も出ない」を作らないための既定であって、
     * 見た目を作り込む場所ではない（それは案件のテーマと Visual Page Editor の仕事）。
     *
     * @param  'index'|'show'|'term'  $action
     */
    private function viewFor(string $contentTypeId, string $action): string
    {
        $specific = "public.{$contentTypeId}.{$action}";

        return view()->exists($specific) ? $specific : "public.content-type.{$action}";
    }

    /**
     * view 名を実行時に決めるので、静的解析のための入口を 1 つに絞る。
     *
     * @param  array<string, mixed>  $data
     */
    private function render(string $name, array $data): View
    {
        /** @var view-string $name */
        return view($name, $data);
    }

    /**
     * 本文。**richtext のときだけ** raw 出力の対象にする。
     *
     * richtext は書き込み境界で Purifier を通っている（`ContentValues`）。
     * それ以外の型は Blade 側で escape されるので、ここでは触らない。
     */
    private function bodyOf(ContentSchema $schema, Model $item): ?string
    {
        foreach ($schema->fields as $field) {
            if ($field->key === 'body' && $field->type === 'richtext') {
                $value = $item->getAttribute('body');

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    private function hasField(ContentSchema $schema, string $key): bool
    {
        foreach ($schema->fields as $field) {
            if ($field->key === $key) {
                return true;
            }
        }

        return false;
    }
}
