<?php

declare(strict_types=1);

namespace Modules\Contact\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use Modules\Contact\Models\ContactSubmission;

/** 問い合わせ 1 件の取得（Phase 9.1B / STEP 9） */
final class GetContact implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'contact.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        $item = $this->find($id, $context);

        if ($item instanceof Result) {
            return $item;
        }

        return Result::success(['item' => $this->present($item)]);
    }

    /** 失敗時は Result を返す */
    public function find(int $id, OperationContext $context): ContactSubmission|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $item = ContactSubmission::query()->find($id);

        return $item ?? Result::notFound("問い合わせ（ID: {$id}）は見つかりません");
    }

    /** @return array<string, mixed> */
    public function present(ContactSubmission $item): array
    {
        return [
            ...$item->toArray(),
            'notification_state' => $item->notificationState(),
            'status_label' => $item->status->label(),
        ];
    }
}
