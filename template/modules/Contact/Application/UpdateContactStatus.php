<?php

declare(strict_types=1);

namespace Modules\Contact\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;
use Modules\Contact\Models\ContactStatus;

/**
 * 問い合わせの対応状態の変更（Phase 9.1B / STEP 9）。
 *
 * submissions kind なので作成・本文編集・削除は用意しない（閲覧と状態変更のみ）。
 */
final class UpdateContactStatus implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetContact $get,
        private readonly ValidationFactory $validator,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'contact.updateStatus';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $id, string $status, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $item = $this->get->find($id, $context);

        if ($item instanceof Result) {
            return $this->outcome->record($this, $context, $item, resourceType: 'contact', resourceId: (string) $id);
        }

        $validator = $this->validator->make(
            ['status' => $status],
            ['status' => ['required', Rule::enum(ContactStatus::class)]],
        );

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $this->outcome->record($this, $context, Result::validationError($errors, '対応状態の値が不正です'), resourceType: 'contact', resourceId: (string) $id);
        }

        $item->update(['status' => $status]);

        // 問い合わせ本文・氏名・メールは監査へ入れない（機微情報）
        return $this->outcome->record(
            $this,
            $context,
            Result::success(['item' => ['id' => $item->getKey(), 'status' => $status]]),
            resourceType: 'contact',
            resourceId: (string) $item->getKey(),
            metadata: ['status' => $status],
        );
    }
}
