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
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;

/**
 * 分類と投稿タイプの関連付けを外す（Phase 9.7 / STAGE 20D・SPEC §H.3）。
 *
 * **投稿に付いている分類は 1 件も消さない。** 外すのは
 * 「この型の編集画面にこの分類を出す」という設定だけである。
 * 付け直せば、既に付いている分類がそのまま見える。
 *
 * 「関連付けを外したら割り当ても消える」を既定にしない — それは
 * 取り消せる操作と取り消せない操作を混ぜることになる。
 */
final class DetachTaxonomyFromContentType implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.detach';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $key, string $contentType, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $taxonomy = Taxonomy::query()->where('key', $key)->first();

        if ($taxonomy === null) {
            return $this->outcome->record($this, $context, Result::notFound('分類が見つかりません'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        $assigned = TaxonomyTermAssignment::query()
            ->where('content_type', $contentType)
            ->whereIn('term_id', TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->select('id'))
            ->count();

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['key' => $key, 'content_type' => $contentType],
            "分類「{$taxonomy->label}」を投稿タイプ [{$contentType}] から外します",
            [
                'この投稿タイプの編集画面に、この分類が出なくなります',
                "既に付いている {$assigned} 件の分類は**削除されません**",
                '付け直せば、そのまま元どおり見えます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'taxonomy', resourceId: $key, metadata: ['contentType' => $contentType]);
        }

        if (! $this->writer->detach($contentType, $taxonomy->id)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('この投稿タイプには関連付けられていません'),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['contentType' => $contentType],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['association' => ['taxonomy' => $key, 'contentType' => $contentType]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            // **残した件数**を記録する（消していないことを後から言えるように）
            metadata: ['contentType' => $contentType, 'keptAssignments' => $assigned],
        );
    }
}
