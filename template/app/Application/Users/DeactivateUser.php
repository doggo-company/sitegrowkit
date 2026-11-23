<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Support\SessionInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * 利用者を無効化する（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **物理削除しない。** 過去の監査ログの actor を失わないため、
 * 行は残したまま入口だけを閉じる。無効化した瞬間に session を失効させ、
 * 以降は `EnsureUserIsActive` が毎リクエストで締め出す。
 */
final class DeactivateUser implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly UserGuard $guard,
        private readonly SessionInvalidator $sessions,
        private readonly UserPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'user.deactivate';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
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

        if ($refusal = $this->guard->refuseDeactivation($user, $context)) {
            return $this->outcome->record($this, $context, $refusal, 'user', (string) $user->getKey());
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            summary: "{$user->name} さんを無効化します",
            impact: [
                'この利用者はログインできなくなります',
                'ログイン中の場合、その場でログアウトされます',
                '過去の操作履歴は残ります（利用者は削除しません）',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
        }

        /*
         * 最後のオーナーの判定は **適用と同じ transaction で**行う（Correction 2）。
         * ロック順序は `UserGuard::lockOwnerCriticalChange()` が
         * 全経路で ID 昇順に統一する（Correction 2-1）。
         */
        $refusal = DB::transaction(function () use ($user): ?Result {
            ['user' => $locked, 'refusal' => $blocked] = $this->guard->lockOwnerCriticalChange(
                (int) $user->getKey(),
                'deactivate',
            );

            if ($blocked !== null) {
                return $blocked;
            }

            $locked?->forceFill(['is_active' => false])->save();

            return null;
        });

        if ($refusal !== null) {
            return $this->outcome->record($this, $context, $refusal, 'user', (string) $user->getKey());
        }

        $this->sessions->forgetUser((int) $user->getKey());

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
