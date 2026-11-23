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

/** コメント 1 件（Phase 9.13 / STAGE 26C） */
final class GetComment implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'comment.get';
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

        $comment = self::find($id);

        if ($comment === null) {
            return Result::notFound("コメント（ID: {$id}）は見つかりません");
        }

        return Result::success(['comment' => CommentPresenter::admin($comment)]);
    }

    /** 権限を見ない素の取得（同じ層の Operation からだけ使う） */
    public static function find(int $id): ?Comment
    {
        return Comment::query()->find($id);
    }
}
