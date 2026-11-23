<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;

/** ページ一覧（Phase 9.1D） */
final class ListPages implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'page.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context, string $status = '', bool $includeTrashed = false): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $query = Page::query()->orderBy('slug');

        if ($status !== '' && PageStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        } elseif (! $includeTrashed) {
            $query->where('status', '!=', PageStatus::Trashed->value);
        }

        /** @var list<array<string, mixed>> $items */
        $items = $query->get()->map(static fn (Page $page): array => [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'status' => $page->status->value,
            // 予約中は「公開予約」。`status` 列は published のまま（`Page::publishState()`）
            'statusLabel' => $page->publishStateLabel(),
            'publishState' => $page->publishState(),
            'managedBy' => $page->managed_by,
            'publishedVersionId' => $page->published_version_id,
            'publishedAt' => $page->published_at?->toIso8601String(),
            'publishedAtLocal' => $page->published_at?->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            'updatedAt' => $page->updated_at?->toIso8601String(),
        ])->values()->all();

        return Result::success(['items' => $items], ['total' => count($items)]);
    }
}
