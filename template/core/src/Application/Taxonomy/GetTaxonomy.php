<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;

/**
 * 分類の取得（Phase 9.3B / STAGE 2B）。
 *
 * 外部 contract の識別子は **key**、DB 内部 identity は **ULID**（§J.3）。
 *
 * **read-only。**
 * - 未登録なら notFound、未同期・`managed_by` 不一致なら conflict。**自動作成も自動 sync もしない**
 * - taxonomy 自体の mutation は提供しない（§P.2）
 * - **Reference Inspector の全 PageVersion 走査をここで毎回行わない。**
 *   参照調査は削除時（`term.delete`）だけに限定し、通常の取得を重くしない
 */
final class GetTaxonomy implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $key, OperationContext $context): Result
    {
        $taxonomy = $this->find($key, $context);

        if ($taxonomy instanceof Result) {
            return $taxonomy;
        }

        return Result::success([
            'taxonomy' => TaxonomyPresenter::taxonomy(
                $taxonomy,
                TaxonomyPresenter::countsFor($taxonomy->id),
            ),
        ]);
    }

    /**
     * 他の操作が同じ解決手順を書かないための入口。
     *
     * @return Taxonomy|Result 認可・解決に失敗したら Result
     */
    public function find(string $key, OperationContext $context): Taxonomy|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        return $this->lookup->taxonomy($key);
    }
}
