<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Comments;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentPresenter;
use AgentCms\Core\Comments\CommentStatus;

/**
 * コメントの一覧（Phase 9.13 / STAGE 26C・SPEC §D.1）。
 *
 * **一覧に本文を全部積まない。** 先頭 80 文字だけを出す。
 * 他人の文章と個人情報を、必要のない画面へ広く展開しない。
 *
 * 既定の絞り込みは **承認待ち**。最初に見るべきものを最初に出す。
 */
final class ListComments implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'comment.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        OperationContext $context,
        string $status = 'pending',
        ?string $contentType = null,
        ?int $contentId = null,
        string $search = '',
        int $page = 1,
        int $perPage = 50,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $filter = CommentStatus::tryFrom($status);

        if ($status !== '' && $filter === null) {
            return Result::validationError(['status' => ['状態の指定が正しくありません']]);
        }

        $search = trim($search);

        $paginator = Comment::query()
            ->when($filter !== null, static fn ($q) => $q->where('status', $filter?->value))
            ->when($contentType !== null, static fn ($q) => $q->where('content_type', $contentType))
            ->when($contentId !== null, static fn ($q) => $q->where('content_id', $contentId))
            ->when($search !== '', static fn ($q) => $q->where(function ($inner) use ($search): void {
                $inner->where('body', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%");
            }))
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return Result::success(
            [
                'comments' => array_map(
                    static fn (Comment $c): array => CommentPresenter::summary($c),
                    $paginator->items(),
                ),
            ],
            [
                'status' => $filter === null ? '' : $filter->value,
                'contentType' => $contentType,
                'contentId' => $contentId,
                'search' => $search,
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'pending' => Comment::query()->where('status', CommentStatus::Pending->value)->count(),
            ],
        );
    }
}
