<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\FormPresenter;
use AgentCms\Core\Forms\SubmissionStatus;

/**
 * 受信の状態を変える（Phase 9.12 / STAGE 25D・SPEC §D.2・§E.5）。
 *
 * ## 新しい権限の軸を作らない
 *
 * 「問い合わせを見られる人」「対応できる人」は既存の `ContactRead` /
 * `ContactWrite` で定義済みである。フォーム専用の軸を足すと、
 * 同じ役割の人に 2 か所で権限を与えることになる。
 *
 * ## 入力値は変えられない
 *
 * ここで動くのは `status` だけである。届いた本文は**書き換えない** —
 * 顧客が何と書いて送ったかは、あとから直してよい記録ではない。
 */
final class UpdateSubmissionStatus implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'form.submission.status';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $id, string $status, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $next = SubmissionStatus::tryFrom($status);

        if ($next === null) {
            return Result::validationError(['status' => ['状態の指定が正しくありません']]);
        }

        $submission = GetSubmission::find($id);

        if ($submission === null) {
            return Result::notFound("受信 [{$id}] は存在しません");
        }

        $submission->forceFill(['status' => $next->value])->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['submission' => FormPresenter::submission($submission)]),
            resourceType: 'form_submission',
            resourceId: (string) $id,
        );
    }
}
