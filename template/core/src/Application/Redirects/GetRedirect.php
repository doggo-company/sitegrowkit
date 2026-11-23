<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectPresenter;
use AgentCms\Core\Redirects\RedirectRule;

/** 転送ルール 1 件（Phase 9.10 / STAGE 23B・SPEC §E.1） */
final class GetRedirect implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'redirect.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $rule = RedirectRule::query()->find($id);

        if ($rule === null) {
            return Result::notFound("転送ルール（ID: {$id}）は見つかりません");
        }

        return Result::success(['rule' => RedirectPresenter::rule($rule)]);
    }
}
