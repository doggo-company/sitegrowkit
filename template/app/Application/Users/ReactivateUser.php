<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;

/**
 * 無効化した利用者を再び有効にする（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 権限は無効化前のまま戻る（勝手に昇格も降格もしない）。
 */
final class ReactivateUser implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly UserPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'user.reactivate';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $user = User::query()->find($id);

        if ($user === null) {
            return $this->outcome->record($this, $context, Result::notFound('利用者が見つかりません'));
        }

        if ($user->is_active) {
            return $this->outcome->record($this, $context, Result::conflict('この利用者はすでに有効です'));
        }

        $user->forceFill(['is_active' => true])->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['user' => $this->presenter->present($user->refresh(), $context)]),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            metadata: ['role' => $user->role()->value],
        );
    }
}
