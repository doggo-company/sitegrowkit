<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;

/**
 * Content Type の schema 定義（Phase 9.1B / STEP 4）。
 *
 * AI はこれを読んでからフィールドを組み立てる。型を hardcode させないための入口。
 */
final class GetContentSchema implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'content.schema.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $type, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! $this->resolver->knows($type)) {
            return Result::notFound("コンテンツタイプ [{$type}] は存在しません");
        }

        return Result::success(['schema' => $this->resolver->schema($type)->toAdminSpec()]);
    }
}
