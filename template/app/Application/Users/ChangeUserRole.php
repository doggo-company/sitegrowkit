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
use App\Support\Role;
use App\Support\SessionInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * ロールを変更する（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 権限が変わる操作なので **確認を必ず通す**。適用したら対象の session を
 * 失効させ、開いたままのタブが古い権限で動き続けないようにする。
 */
final class ChangeUserRole implements Operation
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
        return 'user.role.change';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::ExternalEffect;
    }

    public function execute(int $id, string $role, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $user = User::query()->find($id);

        if ($user === null) {
            return $this->outcome->record($this, $context, Result::notFound('利用者が見つかりません'));
        }

        $next = Role::tryFrom($role);

        if ($next === null) {
            return $this->outcome->record($this, $context, Result::validationError(['role' => ['権限の指定が正しくありません']], '入力内容を確認してください'));
        }

        if ($refusal = $this->guard->refuseRoleChange($user, $next, $context)) {
            return $this->outcome->record($this, $context, $refusal, 'user', (string) $user->getKey());
        }

        $previous = $user->role();

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id, 'role' => $next->value],
            summary: "{$user->name} さんの権限を「{$previous->label()}」から「{$next->label()}」へ変更します",
            impact: [
                '変更後は新しい権限でしか操作できなくなります',
                'この利用者がログイン中の場合、いったんログアウトされます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
        }

        /*
         * **最後のオーナーの判定を適用と同じ transaction で行う**（Correction 2）。
         * 判定と保存が離れていると、2 人のオーナーを同時に降格させたときに
         * どちらも「相手が居る」と読んで owner が 0 人になりうる。
         *
         * ロックの取り方は `UserGuard::lockOwnerCriticalChange()` にまとめてある。
         * **全員を同じ順序で掴む**ので、同時実行は deadlock ではなく
         * 「片方が成功・片方が競合」に落ちる（Correction 2-1）。
         */
        $refusal = DB::transaction(function () use ($user, $next): ?Result {
            ['user' => $locked, 'refusal' => $blocked] = $this->guard->lockOwnerCriticalChange(
                (int) $user->getKey(),
                'role',
            );

            if ($blocked !== null) {
                return $blocked;
            }

            $locked?->forceFill(['role' => $next->value])->save();

            return null;
        });

        if ($refusal !== null) {
            return $this->outcome->record($this, $context, $refusal, 'user', (string) $user->getKey());
        }

        // 権限が変わったら、開いたままの session は捨てる（driver 非依存）
        $this->sessions->forgetUser((int) $user->getKey());

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['user' => $this->presenter->present($user->refresh(), $context)]),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            metadata: ['from' => $previous->value, 'to' => $next->value],
        );
    }
}
