<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * コンテンツ一覧（Phase 9.1B / STEP 4）。
 *
 * Admin はページャの link 情報まで必要なので paginate() を、
 * API / MCP は素の配列が欲しいので execute() を使う。検索条件の解釈は 1 箇所。
 */
final class ListContent implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly ContentTaxonomyReader $taxonomies,
        private readonly OperationPolicy $policy,
        private readonly TrashReadinessResolver $trashReadiness,
    ) {}

    public function operationId(): string
    {
        return 'content.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        string $type,
        OperationContext $context,
        string $search = '',
        int $page = 1,
        int $perPage = 20,
    ): Result {
        return $this->present($type, $context, $search, $page, $perPage, null);
    }

    /**
     * 公開ページの描画専用の内部経路（Phase 9.3B / #22）。
     *
     * ## なぜ `execute()` に引数を足さないのか
     *
     * `execute()` の引数は **`content.list` として外へ出している contract** そのもので、
     * API / MCP がその形で呼ぶ。ここに絞り込みを足すと「分類で絞れる公開 API」を
     * 増やしたことになり、9.3B が公開すると決めた範囲（term の操作と taxonomy の read）を
     * 超える。だから**描画のためだけの別入口**にする。
     *
     * 操作としても公開しない（`OperationCatalog` には `content.list` しか無い）。
     */
    public function forRendering(
        string $type,
        OperationContext $context,
        int $perPage,
        ContentQueryRestriction $restriction,
        int $page = 1,
    ): Result {
        return $this->present($type, $context, '', $page, $perPage, $restriction);
    }

    private function present(
        string $type,
        OperationContext $context,
        string $search,
        int $page,
        int $perPage,
        ?ContentQueryRestriction $restriction,
    ): Result {
        $paginator = $this->paginateWith($type, $context, $search, $perPage, $page, $restriction);

        if ($paginator instanceof Result) {
            return $paginator;
        }

        $models = $paginator->getCollection();

        // **一覧でも N+1 を作らない**: content_type + content_id の集合で一括取得する（§K.4）
        $blocks = $this->taxonomies->forEntries(
            $this->resolver->schema($type),
            $type,
            array_values($models->map(static fn (Model $item): int => (int) $item->getKey())->all()),
        );

        return Result::success(
            ['items' => $models->map(static function (Model $item) use ($blocks): array {
                $row = GetContent::present($item);
                $row[ContentTaxonomyInput::RESPONSE_KEY] = $blocks[(int) $item->getKey()] ?? [];

                return $row;
            })->all()],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'search' => trim($search),
            ],
        );
    }

    /**
     * ページングより前にかける絞り込み（#22）。
     *
     * **公開ページの描画からしか渡されない。** 既定は null なので
     * API / MCP / Admin の結果は変わらない。
     *
     * `taxonomy_term_assignments` はクラス固定で、値はすべて束縛変数。
     * 入力からテーブル名やカラム名を組み立てることは無い。
     *
     * @param  Builder<Model>  $query
     */
    private function restrict(
        Builder $query,
        string $type,
        ContentSchema $schema,
        ContentQueryRestriction $restriction,
    ): void {
        // status を持たない型（設定的なもの）には効かせない
        if ($restriction->publishedOnly && $schema->hasField('status')) {
            $query->where('status', 'published');
        }

        if ($restriction->termGroups === []) {
            return;
        }

        $assignments = (new TaxonomyTermAssignment)->getTable();
        $table = $query->getModel()->getTable();

        // group ごとに exists を積む = **AND**。group の中は whereIn = **OR**
        foreach ($restriction->termGroups as $termIds) {
            $query->whereExists(static function ($sub) use ($assignments, $table, $type, $termIds): void {
                $sub->select(DB::raw(1))
                    ->from($assignments)
                    ->whereColumn($assignments.'.content_id', $table.'.id')
                    ->where($assignments.'.content_type', $type)
                    ->whereIn($assignments.'.term_id', $termIds);
            });
        }
    }

    /**
     * 失敗時は Result（forbidden / notFound）を返す。
     *
     * @return LengthAwarePaginator<int, Model>|Result
     */
    public function paginate(
        string $type,
        OperationContext $context,
        string $search = '',
        int $perPage = 20,
        ?int $page = null,
        bool $trashedOnly = false,
    ): LengthAwarePaginator|Result {
        return $this->paginateWith($type, $context, $search, $perPage, $page, null, $trashedOnly);
    }

    /**
     * @return LengthAwarePaginator<int, Model>|Result
     */
    private function paginateWith(
        string $type,
        OperationContext $context,
        string $search,
        int $perPage,
        ?int $page,
        ?ContentQueryRestriction $restriction,
        bool $trashedOnly = false,
    ): LengthAwarePaginator|Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! $this->resolver->knows($type)) {
            return Result::notFound("コンテンツタイプ [{$type}] は存在しません");
        }

        $schema = $this->resolver->schema($type);
        $model = $this->resolver->modelClass($type);

        /*
         * ゴミ箱の一覧（Phase 9.8 / STAGE 21E）。
         *
         * **管理画面の一覧からしか渡らない。** `execute()` の引数は
         * `content.list` として外へ出している契約そのもので、そこは変えていない
         * （API / MCP から見える形は 21C のまま）。
         *
         * 対応していない型で `onlyTrashed()` を呼ぶと typed に止まる。
         * 「ゴミ箱は空でした」と「この型にゴミ箱はまだ無い」を混ぜないためである。
         */
        if ($trashedOnly) {
            /*
             * **対応していない型では「空でした」と言わない。** 列がまだ無い型と、
             * ゴミ箱が空の型は別のことなので、typed に止める。
             */
            $this->trashReadiness->assertUsable($type);

            $query = $model::query()
                ->withoutGlobalScope(TrashScope::NAME)
                ->whereNotNull((new $model)->qualifyColumn(TrashColumns::DELETED_AT))
                ->latest('id');
        } else {
            $query = $model::query();

            /*
             * **手で決めた掲載順が第一キー**（P1 ②）。
             *
             * 並べ替えを 1 度もしていないサイトでは全行 0 なので、
             * 第二キー（id の新しい順）がそのまま効き、**いままでと同じ並び**になる。
             *
             * ゴミ箱の一覧には掛けない。捨てたものの掲載順に意味は無く、
             * **捨てた順（id の新しい順）で見たい**からである。
             */
            ContentOrder::apply($query, $schema->table);

            $query->latest('id');
        }

        $search = trim($search);

        if ($search !== '' && $schema->searchable !== []) {
            $query->where(function ($q) use ($schema, $search): void {
                foreach ($schema->searchable as $key) {
                    $q->orWhere($key, 'like', "%{$search}%");
                }
            });
        }

        if ($restriction !== null && ! $restriction->isEmpty()) {
            $this->restrict($query, $type, $schema, $restriction);
        }

        return $query->paginate(
            max(1, min($perPage, self::MAX_PER_PAGE)),
            ['*'],
            'page',
            $page === null ? null : max(1, $page),
        );
    }
}
