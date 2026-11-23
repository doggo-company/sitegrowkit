<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\PageStatus;

/** 公開・予約を取り消して下書きへ戻す。本文と版の履歴は保持する。 */
final class UnpublishPage implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.unpublish';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::ExternalEffect;
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
        if (! $page->isDocumentManaged() || $page->status !== PageStatus::Published) {
            return $this->outcome->record($this, $context, Result::conflict('公開中または公開予約中のページだけ下書きへ戻せます'));
        }

        // 管理画面の明示ボタンは公開操作と同じ確認。AI/API には状態に結び付けた承認を求める。
        if ($context->interface !== OperationInterface::Admin) {
            $confirmation = $this->confirmations->require(
                $this->operationId(), $this->classification(), $context,
                ['pageId' => $page->id, 'versionId' => $page->published_version_id, 'publishedAt' => $page->published_at?->toIso8601String()],
                summary: "「{$page->label()}」を下書きへ戻します",
                impact: ['公開・予約を取り消します', '公開サイト・メニュー・sitemap に表示されなくなります', '本文と版の履歴は残ります'],
            );
            if ($confirmation !== null) {
                return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
            }
        }

        $page->forceFill(['status' => PageStatus::Draft->value, 'published_at' => null])->save();

        return $this->outcome->record($this, $context,
            Result::success(['page' => GetPage::present($page->refresh())]),
            resourceType: 'page', resourceId: (string) $page->id,
            metadata: ['slug' => $page->slug],
        );
    }
}
