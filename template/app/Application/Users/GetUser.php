<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;

/**
 * 利用者 1 件（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ID 直打ちでも権限が効くよう、**取得も Application Service を通す**。
 */
final class GetUser implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly UserPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'user.get';
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

        $user = User::query()->find($id);

        if ($user === null) {
            return Result::notFound('利用者が見つかりません');
        }

        return Result::success(['user' => $this->presenter->present($user, $context)]);
    }
}
