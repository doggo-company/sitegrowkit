<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * 項目の一覧（Phase 9.3B / STAGE 2B・SPEC §I.5 / §I.7）。
 *
 * **includeArchived の contract**
 *
 * | 値 | 返るもの | 用途 |
 * | --- | --- | --- |
 * | `false`（既定） | **active のみ**（AR-1 / HR-5） | assignment の選択候補・API / MCP の通常取得 |
 * | `true` | active + archived（AR-2） | **Admin の管理 tree**（HR-3）。hierarchy を壊さないため |
 *
 * **HR-7（重要な contract）**: 既定 response だけでは **hierarchy を再構成できないことがある**。
 * archived な親が一覧から外れても、その配下の active な子は
 * `parentId` を保持したまま返る（HR-6）。呼び出し側はこれを
 * **孤児 root として描画してはならない**。完全な tree が必要なら `includeArchived=true` を使う。
 *
 * 並び順は **同一 parent 内で `sort_order` 昇順**、同値のときは `id` 昇順（安定 tie-breaker）。
 */
final class ListTerms implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
    ) {}

    public function operationId(): string
    {
        return 'term.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        string $taxonomyKey,
        OperationContext $context,
        bool $includeArchived = false,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $taxonomy = $this->lookup->taxonomy($taxonomyKey);

        if ($taxonomy instanceof Result) {
            return $taxonomy;
        }

        $terms = $this->query($taxonomy, $includeArchived)->get();

        $items = $terms
            ->map(static fn (TaxonomyTerm $term): array => TaxonomyPresenter::term($term, $taxonomy))
            ->values()
            ->all();

        return Result::success(
            ['terms' => $items],
            [
                'taxonomyKey' => $taxonomy->key,
                'includeArchived' => $includeArchived,
                'count' => count($items),
                // HR-7: 既定では tree を再構成できないことを呼び出し側へ明示する
                'treeComplete' => $includeArchived,
            ],
        );
    }

    /** @return Builder<TaxonomyTerm> */
    private function query(Taxonomy $taxonomy, bool $includeArchived)
    {
        $query = TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id);

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        // 同一 parent 内で sort_order 昇順。同値は id 昇順で安定させる
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
