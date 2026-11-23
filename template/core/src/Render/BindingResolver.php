<?php

declare(strict_types=1);

namespace AgentCms\Core\Render;

use AgentCms\Core\Application\Content\ContentQueryRestriction;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TaxonomyFilterIndex;

/**
 * Content Binding の解決（Phase 9.1D）。
 *
 * **一覧の取得は既存の `ListContent` へ委譲する。**
 * PageDocument 側に listing の業務ロジックを持ち込まない（Phase 9.1C §13）。
 * News / Works のような具体名はここにも書かない。
 */
final class BindingResolver
{
    /** 母集団を読み切るときの 1 ページ分。`ListContent` の上限と同じ */
    private const SCAN_PAGE_SIZE = 100;

    public function __construct(
        private readonly ListContent $list,
        private readonly SchemaRegistry $schemas,
        private readonly TaxonomyFilterIndex $taxonomies,
    ) {}

    /**
     * 単一値の binding（heading の text、image の mediaId など）。
     *
     * @param  array<string, mixed>  $binding
     */
    public function value(array $binding, RenderContext $context): mixed
    {
        $field = (string) ($binding['field'] ?? '');

        return match ((string) ($binding['source'] ?? '')) {
            'page' => match ($field) {
                'title' => $context->page->title,
                'slug' => $context->page->slug,
                default => null,
            },
            'settings' => $context->settings[$field] ?? null,
            'entry' => $this->fromEntry($binding, $field, $context),
            default => null,
        };
    }

    /**
     * entry binding。`field` は **immutable field ID / key / ドット記法**のいずれでもよい
     * （Phase 9.2B）。ID を第一候補にすることで、フィールド名を変えても壊れない。
     *
     * @param  array<string, mixed>  $binding
     */
    private function fromEntry(array $binding, string $field, RenderContext $context): mixed
    {
        $entry = $context->entry;

        if ($entry === null || $field === '') {
            return null;
        }

        $type = (string) ($binding['type'] ?? '');
        $path = $field;

        // field ID で指されていたら、その時点の key へ解決する
        if ($type !== '' && $this->schemas->has($type)) {
            $resolved = $this->schemas->get($type)->fieldById($field);

            if ($resolved !== null) {
                $path = $resolved->key;
            }
        }

        $value = $entry;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * collection binding。認可も検索も `ListContent` の中で行われる。
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    public function collection(array $binding, array $props): array
    {
        $type = (string) ($binding['type'] ?? '');

        if ($type === '') {
            return [];
        }

        $limit = max(1, min((int) ($props['limit'] ?? 6), 50));

        // 分類の絞り込みは **ページングより前**にかける。
        // 「取ってから絞る」にすると、新しい記事が別分類で埋まっているときに
        // 該当があるのに 0 件になる（母集団の大きさで結果が変わってしまう）
        $groups = $this->taxonomies->groups($binding['filters'] ?? null);

        if ($groups === null) {
            // 絞り込みを読み取れないまま全件出すと、意図しないものが公開ページに載る
            return [];
        }

        // 公開ページの描画は system 経路。認可は「公開済みのみ」を絞り込みで担保する
        $context = OperationContext::cli('renderer');
        $restriction = new ContentQueryRestriction($groups, publishedOnly: true);

        $sort = $this->sortSpec($binding);

        if ($sort === false) {
            // 並び順を読み取れない。**並べずに出す**のではなく出さない（検証も error にする形）
            return [];
        }

        // PHP 側でやることが残っているなら、母集団を読み切ってからでないと正しくない。
        // 並び替えも同じで、「先頭 N 件を並べ替える」のは「並べ替えて先頭 N 件」ではない
        $items = $this->hasFieldFilter($binding) || $sort !== null
            ? $this->scanAll($type, $context, $restriction)
            : $this->firstPage($type, $context, $restriction, $limit);

        // 絞り込みと並び替えのあとに切る。**先に切ってから絞ると件数が足りなくなる**
        return array_slice($this->applyFilters($items, $binding), 0, $limit);
    }

    /**
     * SQL 側で絞り切れているとき用。表示件数ぶんだけ読む。
     *
     * @return list<array<string, mixed>>
     */
    private function firstPage(
        string $type,
        OperationContext $context,
        ContentQueryRestriction $restriction,
        int $limit,
    ): array {
        $result = $this->list->forRendering($type, $context, $limit, $restriction);

        if (! $result->isSuccess()) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $result->data['items'] ?? [];

        return $items;
    }

    /**
     * `{field, op, value}` の絞り込みが残っているとき用。**母集団を最後まで読む**。
     *
     * ## なぜ上限を置かないか
     *
     * 上限を置くと、それが「隠れた意味上限」になる。上限より後ろにしか一致が無い場合に
     * 「該当があるのに 0 件」となり、しかも母集団が育つまで気づけない。
     * 件数で結果が変わる絞り込みは、絞り込みとして壊れている。
     *
     * ## 代わりに何を払うか
     *
     * クエリ数が **分類と公開状態で絞ったあとの件数に比例**する（1 ページ 100 件）。
     * 分類の条件は既に SQL 側でかかっているので、走るのは「その分類の公開記事」だけ。
     * field の条件まで SQL へ持ち込むには、custom field の格納方法（列 / JSON）や
     * ドット記法・field ID の解決が絡み、`ListContent` の外まで設計が広がるため
     * この Stage では踏み込まない。
     *
     * @return list<array<string, mixed>>
     */
    private function scanAll(
        string $type,
        OperationContext $context,
        ContentQueryRestriction $restriction,
    ): array {
        $items = [];
        $page = 1;

        do {
            $result = $this->list->forRendering($type, $context, self::SCAN_PAGE_SIZE, $restriction, $page);

            if (! $result->isSuccess()) {
                return $items;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $result->data['items'] ?? [];

            foreach ($rows as $row) {
                $items[] = $row;
            }

            $lastPage = (int) ($result->meta['lastPage'] ?? 1);
            $page++;
        } while ($rows !== [] && $page <= $lastPage);

        return $items;
    }

    /**
     * 並び順の指定を読み取る。
     *
     * - `null` … 指定なし（既定の並びのまま）
     * - `false` … **読み取れない**。検証側も error にする形なので、描画は 0 件にする
     * - 配列 … `{field, direction}`
     *
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>|null|false
     */
    private function sortSpec(array $binding): array|null|false
    {
        $sort = $binding['sort'] ?? null;

        if ($sort === null || $sort === []) {
            return null;
        }

        if (! is_array($sort) || ! array_is_list($sort)) {
            return false;
        }

        $first = $sort[0];

        if (! is_array($first) || ! is_string($first['field'] ?? null) || $first['field'] === '') {
            return false;
        }

        return $first;
    }

    /**
     * PHP 側に残る絞り込み（`{field, op, value}`）があるか。
     *
     * @param  array<string, mixed>  $binding
     */
    private function hasFieldFilter(array $binding): bool
    {
        $filters = $binding['filters'] ?? [];

        if (! is_array($filters)) {
            return false;
        }

        foreach ($filters as $filter) {
            if (is_array($filter) && ! array_key_exists('taxonomy', $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 側に残る処理。
     *
     * - **公開済みだけに絞る**（SQL 側でも絞っているが、認可の境界なので二重に守る）
     * - `{field, op, value}` の絞り込み
     * - 並び替え
     *
     * 分類の絞り込みは **SQL 側で終わっている**ので、ここでは触らない（§O）。
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $binding
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $items, array $binding): array
    {
        // 公開ページでは下書きを出さない（status を持つ型のみ）
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => ! array_key_exists('status', $item)
                || $item['status'] === 'published',
        ));

        $filters = $binding['filters'] ?? [];

        if (! is_array($filters) || ! array_is_list($filters)) {
            // 絞り込みを読み取れないまま全件出すと、意図しないものが公開ページに載る
            return [];
        }

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                return [];
            }

            // 分類での絞り込みは **既に SQL 側でかかっている**（§O）。ここでは触らない。
            // 判断の置き場所を 1 つにしておく
            if (array_key_exists('taxonomy', $filter)) {
                continue;
            }

            $field = (string) ($filter['field'] ?? '');
            $values = (array) ($filter['value'] ?? []);

            if ($field === '' || $values === []) {
                continue;
            }

            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => in_array($item[$field] ?? null, $values, true),
            ));
        }

        $sort = $this->sortSpec($binding);

        if (is_array($sort)) {
            $field = (string) $sort['field'];
            $descending = ($sort['direction'] ?? 'asc') === 'desc';

            usort($items, static function (array $a, array $b) use ($field, $descending): int {
                $result = ($a[$field] ?? null) <=> ($b[$field] ?? null);

                return $descending ? -$result : $result;
            });
        }

        return $items;
    }

    /** Result を返さないヘルパ（呼び出し側が失敗を握りつぶさないための明示） */
    public function failed(Result $result): bool
    {
        return ! $result->isSuccess();
    }
}
