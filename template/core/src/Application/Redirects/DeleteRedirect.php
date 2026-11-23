<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectRule;

/**
 * 転送ルールを消す（Phase 9.10 / STAGE 23B・SPEC §E.1）。
 *
 * **DESTRUCTIVE。消した瞬間に、外から来ている URL が 404 に戻る。**
 * だから確認では **どれだけ踏まれているか**を必ず出す。
 * 「1 度も踏まれていない」のと「毎日 200 回踏まれている」のとでは、消す判断が変わる。
 *
 * 一時的に止めたいだけなら `is_enabled` を落とせばよい。その導線も文面に書く。
 */
final class DeleteRedirect implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'redirect.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
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

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            "転送ルール「{$rule->source_path}」を削除します",
            [
                "これまで {$rule->hit_count} 回転送しています",
                "削除すると {$rule->source_path} は 404 に戻ります",
                '一時的に止めたいだけなら、削除せず「無効」にできます',
            ],
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        $rule->delete();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(null, ['source_path' => $rule->source_path, 'hit_count' => $rule->hit_count]),
            resourceType: 'redirect',
            resourceId: (string) $id,
        );
    }
}
