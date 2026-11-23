<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;

/**
 * 使える Content Type の一覧（Phase 9.1B / STEP 4）。
 *
 * ツール定義や画面に型名を焼き込まないための実行時取得口（PHASE9_1A_SPEC K）。
 */
final class ListContentTypes implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'content.types.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $types = array_map(
            fn (string $id): array => [
                'id' => $id,
                'label' => $this->resolver->schema($id)->label,
            ],
            $this->resolver->availableTypes(),
        );

        return Result::success(['types' => $types]);
    }
}
