<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;

/**
 * 分類の定義を変える操作に共通する入口の検査（Phase 9.7 / STAGE 20D）。
 *
 * **code 管理の定義は管理画面から変えられない。** ファイルが正なので、
 * 管理画面から変えると git の内容と実行時の内容が食い違う
 * （投稿タイプで code-managed を触らせないのと同じ理由）。
 */
final readonly class TaxonomyDefinitionGuard
{
    private function __construct(public Taxonomy $taxonomy) {}

    public static function for(
        Operation $operation,
        OperationPolicy $policy,
        OperationAudit $outcome,
        string $key,
        OperationContext $context,
    ): self|Result {
        if (! $policy->allows($operation->operationId(), $operation->classification(), $context)) {
            return $outcome->record($operation, $context, Result::forbidden(
                $policy->denialReason($operation->operationId(), $context),
            ));
        }

        $taxonomy = Taxonomy::query()->where('key', $key)->first();

        if ($taxonomy === null) {
            return $outcome->record($operation, $context, Result::notFound('分類が見つかりません'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        if ($taxonomy->managed_by === TaxonomyManagedBy::Code) {
            return $outcome->record(
                $operation,
                $context,
                Result::forbidden('この分類はコードで管理されているため、管理画面からは変更できません'),
                resourceType: 'taxonomy',
                resourceId: $key,
            );
        }

        return new self($taxonomy);
    }
}
