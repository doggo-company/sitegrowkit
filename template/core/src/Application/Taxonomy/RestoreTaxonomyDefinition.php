<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;

/**
 * 無効にした分類を元に戻す（Phase 9.7 / STAGE 20D）。
 *
 * 確認を要らないのは、**値を失う操作ではない**からである。
 */
final class RestoreTaxonomyDefinition implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $key, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = TaxonomyDefinitionGuard::for($this, $this->policy, $this->outcome, $key, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $taxonomy = $guard->taxonomy;

        if (! $taxonomy->isArchived()) {
            return $this->outcome->record($this, $context, Result::conflict('この分類は無効になっていません'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        if (! $this->writer->update($taxonomy->id, ['status' => 'active'], $expectedLockVersion)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの分類が更新されました。読み込み直してからやり直してください'),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['expectedVersion' => $expectedLockVersion, 'actualVersion' => $this->writer->lockVersionOf($taxonomy->id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['taxonomy' => ['key' => $key, 'status' => 'active', 'lockVersion' => $expectedLockVersion + 1]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            metadata: ['changed' => ['status']],
        );
    }
}
