<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;

/** ページ 1 件のメタデータ（Phase 9.1D） */
final class GetPage implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly PageRepository $pages,
    ) {}

    public function operationId(): string
    {
        return 'page.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        $page = $this->find($id, $context);

        return $page instanceof Result ? $page : Result::success(['page' => self::present($page)]);
    }

    /** 失敗時は Result を返す（他の Page 操作から再利用する） */
    public function find(int $id, OperationContext $context): Page|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        return $this->pages->find($id);
    }

    /** @return array<string, mixed> */
    public static function present(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'status' => $page->status->value,
            'managedBy' => $page->managed_by,
            'seo' => $page->seo,
            'draftVersionId' => $page->draft_version_id,
            'publishedVersionId' => $page->published_version_id,
            'publishedAt' => $page->published_at?->toIso8601String(),
            // 予約公開（release R02）。画面は `status` ではなく **こちら**で状態を出す
            'publishState' => $page->publishState(),
            'publishStateLabel' => $page->publishStateLabel(),
            'scheduled' => $page->isScheduled(),
            // 人が読む日時はサイトの timezone で。どの timezone かも一緒に渡す
            'publishedAtLocal' => $page->published_at?->copy()->setTimezone(self::timezone())->format('Y-m-d H:i'),
            'timezone' => self::timezone(),
        ];
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
