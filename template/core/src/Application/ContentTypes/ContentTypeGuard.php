<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 型の lifecycle 操作に共通する入口の検査（Phase 9.7 / STAGE 20C）。
 *
 * archive / restore が同じ検査をそれぞれ書くと、片方だけ緩む。
 * **通ったときは対象の schema を、通らなかったときは記録済みの `Result` を返す。**
 */
final readonly class ContentTypeGuard
{
    private function __construct(public ContentSchema $schema, public string $label) {}

    public static function for(
        Operation $operation,
        OperationPolicy $policy,
        OperationAudit $outcome,
        SchemaRegistry $schemas,
        ContentTypeSettingsResolver $resolver,
        string $id,
        OperationContext $context,
    ): self|Result {
        if (! $policy->allows($operation->operationId(), $operation->classification(), $context)) {
            return $outcome->record($operation, $context, Result::forbidden(
                $policy->denialReason($operation->operationId(), $context),
            ));
        }

        $schema = $schemas->declared()[$id] ?? null;

        if ($schema === null) {
            return $outcome->record($operation, $context, Result::notFound('投稿タイプが見つかりません'),
                resourceType: 'content_type', resourceId: $id);
        }

        if (! EditorialCollections::isManageable($schema)) {
            return $outcome->record(
                $operation,
                $context,
                Result::forbidden('この投稿タイプはコードで管理されているため、管理画面からは変更できません'),
                resourceType: 'content_type',
                resourceId: $id,
            );
        }

        // 設定を読めないまま状態を書き換えない（SPEC §J.4 の fail-closed）
        if (! $resolver->readiness()->allowsWrites()) {
            return $outcome->record(
                $operation,
                $context,
                Result::conflict($resolver->readiness()->message() ?? '設定を読めませんでした'),
                resourceType: 'content_type',
                resourceId: $id,
            );
        }

        return new self($schema, $resolver->for($id)->labelOr($schema->label));
    }
}
