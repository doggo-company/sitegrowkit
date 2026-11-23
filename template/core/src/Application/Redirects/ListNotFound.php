<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\NotFoundLog;
use AgentCms\Core\Redirects\RedirectPresenter;

/**
 * 404 の記録の一覧（Phase 9.10 / STAGE 23C・SPEC §E.1）。
 *
 * 既定は **未解決を、よく踏まれている順**。直したものは既定で出さない —
 * 見たいのは「まだ壊れているもの」だからである。
 *
 * **AI へは公開しない**（OG8）。referrer と user-agent は個人に近づく。
 */
final class ListNotFound implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'not_found.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        OperationContext $context,
        string $search = '',
        bool $includeResolved = false,
        int $page = 1,
        int $perPage = 50,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $search = trim($search);

        $paginator = NotFoundLog::query()
            ->when(! $includeResolved, static fn ($q) => $q->whereNull('resolved_at'))
            ->when($search !== '', static fn ($q) => $q->where('path', 'like', "%{$search}%"))
            ->orderByDesc('hit_count')
            ->orderByDesc('last_seen_at')
            ->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return Result::success(
            [
                'logs' => array_map(
                    static fn (NotFoundLog $log): array => RedirectPresenter::notFound($log),
                    $paginator->items(),
                ),
            ],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'includeResolved' => $includeResolved,
                'search' => $search,
            ],
        );
    }
}
