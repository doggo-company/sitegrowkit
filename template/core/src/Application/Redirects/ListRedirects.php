<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectPresenter;
use AgentCms\Core\Redirects\RedirectRule;

/**
 * 転送ルールの一覧（Phase 9.10 / STAGE 23B・SPEC §E.1）。
 *
 * 既定は **よく踏まれている順**。転送は「効いているか」を見たいものなので、
 * 作成順よりヒット数のほうが役に立つ。
 */
final class ListRedirects implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'redirect.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        OperationContext $context,
        string $search = '',
        ?bool $enabled = null,
        int $page = 1,
        int $perPage = 50,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $search = trim($search);

        $query = RedirectRule::query()
            ->when($enabled !== null, static fn ($q) => $q->where('is_enabled', $enabled))
            ->when($search !== '', static fn ($q) => $q->where(function ($inner) use ($search): void {
                $inner->where('source_path', 'like', "%{$search}%")
                    ->orWhere('target_url', 'like', "%{$search}%");
            }))
            ->orderByDesc('hit_count')
            ->orderByDesc('id');

        $paginator = $query->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return Result::success(
            [
                'rules' => array_map(
                    static fn (RedirectRule $rule): array => RedirectPresenter::rule($rule),
                    $paginator->items(),
                ),
            ],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'search' => $search,
            ],
        );
    }
}
