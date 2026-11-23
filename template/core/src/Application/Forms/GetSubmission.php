<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\FormPresenter;
use AgentCms\Core\Forms\FormSubmission;

/**
 * 受信 1 件（Phase 9.12 / STAGE 25B）。
 *
 * **そのときの表示名で出す。** 項目を消したり改名したりしたあとでも、
 * 届いた内容が読めるようにするためである（OG4）。
 */
final class GetSubmission implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'form.submission.get';
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

        $submission = self::find($id);

        if ($submission === null) {
            return Result::notFound("受信（ID: {$id}）は見つかりません");
        }

        return Result::success(['submission' => FormPresenter::submission($submission)]);
    }

    /** 権限を見ない素の取得（同じ層の Operation からだけ使う） */
    public static function find(int $id): ?FormSubmission
    {
        return FormSubmission::query()->find($id);
    }
}
