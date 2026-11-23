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
use App\Notifications\UserInvitation;
use App\Support\SafeFailureLog;
use Throwable;

/**
 * 招待メールを送り直す（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 送信に失敗しても利用者の状態は変えない。**送れたかどうかだけ**を返し、
 * 画面が「もう一度試す」を出せるようにする。
 */
final class ResendInvitation implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'user.invitation.resend';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::ExternalEffect;
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

        if (! $user->is_active) {
            return $this->outcome->record(
                $this, $context,
                Result::conflict('無効化された利用者には招待を送れません'),
                'user', (string) $user->getKey(),
            );
        }

        /*
         * **まだ使い始めていない利用者にだけ**送る（Correction 6）。
         * 一度ログインした人へ再設定リンクを送りつける経路は、
         * URL を直接叩けば誰でも起動できる「乗っ取りの入口」になる。
         * 既存利用者のパスワード再設定は本人が /forgot-password から行う。
         */
        if (! $user->isPendingInvitation()) {
            return $this->outcome->record(
                $this, $context,
                Result::conflict('この利用者はすでに利用を開始しています。パスワードの再設定は本人が行ってください。'),
                'user', (string) $user->getKey(),
            );
        }

        $delivered = true;

        try {
            $user->notify(new UserInvitation(
                UserInvitation::issueTokenFor($user),
                $context->actorLabel !== '' ? $context->actorLabel : '管理者',
                $user->role()->label(),
            ));

            // **送れたときだけ**招待日時を更新する（失敗を「送った」と記録しない）
            $user->forceFill(['invited_at' => now()])->save();
        } catch (Throwable $e) {
            // token 発行とメール送信の両方がこの try の中にある
            SafeFailureLog::record('招待メールを再送できませんでした', $e);
            $delivered = false;
        }

        return $this->outcome->record(
            $this,
            $context,
            $delivered
                        ? Result::success(['invitationDelivered' => true])
                        : Result::conflict('招待メールを送信できませんでした。時間をおいてもう一度お試しください。'),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            metadata: ['invitationDelivered' => $delivered],
        );
    }
}
