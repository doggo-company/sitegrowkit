<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\PageStatus;

/**
 * ゴミ箱からの復元（Phase 9.1D）。
 *
 * 復元しても **公開状態には戻さない**（下書きへ戻す）。
 * 「消したものが勝手に公開される」ことがないようにするため。
 */
final class RestorePage implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $pageId, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $this->outcome->record($this, $context, $page, resourceType: 'page', resourceId: (string) $pageId);
        }

        if ($page->status !== PageStatus::Trashed) {
            return $this->outcome->record($this, $context, Result::conflict('このページはゴミ箱にありません'), resourceType: 'page', resourceId: (string) $page->id);
        }

        $page->forceFill([
            'status' => PageStatus::Draft->value,
            'trashed_at' => null,
        ])->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['page' => GetPage::present($page->refresh())]),
            resourceType: 'page',
            resourceId: (string) $page->id,
            metadata: ['slug' => $page->slug],
        );
    }
}
