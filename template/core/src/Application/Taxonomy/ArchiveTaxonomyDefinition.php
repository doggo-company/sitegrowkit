<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * 分類を無効にする（Phase 9.7 / STAGE 20D・SPEC §H.2）。
 *
 * **何も消さない。** 項目（term）も、投稿への割り当ても、投稿タイプとの関連付けも残る。
 * 変わるのは「編集画面に出なくなり、新しく付けられなくなる」ことだけで、
 * 戻せば元どおり見える。
 */
final class ArchiveTaxonomyDefinition implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.archive';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $key, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = TaxonomyDefinitionGuard::for($this, $this->policy, $this->outcome, $key, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $taxonomy = $guard->taxonomy;

        if ($taxonomy->isArchived()) {
            return $this->outcome->record($this, $context, Result::conflict('この分類は既に無効です'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        $terms = TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->count();
        $associations = $this->writer->associationCount($taxonomy->id);

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['key' => $key, 'lock_version' => $expectedLockVersion],
            "分類「{$taxonomy->label}」を無効にします",
            [
                "{$terms} 件の項目が投稿の編集画面に出なくなります",
                "{$associations} 件の投稿タイプとの関連付けは残ります",
                '投稿に付いている分類は削除されません。戻せば元どおり見えます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'taxonomy', resourceId: $key);
        }

        if (! $this->writer->update($taxonomy->id, ['status' => 'archived'], $expectedLockVersion)) {
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
            Result::success(['taxonomy' => ['key' => $key, 'status' => 'archived', 'lockVersion' => $expectedLockVersion + 1]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            metadata: ['changed' => ['status'], 'termCount' => $terms],
        );
    }
}
