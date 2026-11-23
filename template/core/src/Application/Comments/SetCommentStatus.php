<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Comments;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentPresenter;
use AgentCms\Core\Comments\CommentStatus;
use Illuminate\Support\Facades\DB;

/**
 * コメントの状態を変える（Phase 9.13 / STAGE 26C・SPEC §D.2）。
 *
 * ## 動くのは `status` だけ
 *
 * 本文も投稿者名も書き換えない。**他人が書いた文章**を、こちらの都合で直さない。
 *
 * ## まとめて操作できるようにする
 *
 * 承認待ちが 100 件あるサイトで 1 件ずつ押させるのは、
 * 「既定を承認待ちにした」設計の代償を利用者に払わせることになる。
 * ただし**まとめての物理削除は無い** — そもそも物理削除の口を作っていない（OG7）。
 *
 * ## 監査に本文を入れない
 *
 * 記録するのは**種別と対象 ID と前後の状態**だけ（`WP_PARITY_BASELINE.md` §2.5）。
 */
final class SetCommentStatus implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'comment.status';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  list<int>  $ids
     */
    public function execute(array $ids, string $status, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $next = CommentStatus::tryFrom($status);

        if ($next === null) {
            return Result::validationError(['status' => ['状態の指定が正しくありません']]);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return Result::validationError(['ids' => ['対象を 1 件以上選んでください']]);
        }

        /** @var list<Comment> $comments */
        $comments = Comment::query()->whereIn('id', $ids)->get()->all();

        if (count($comments) !== count($ids)) {
            return Result::notFound('選んだコメントの一部が見つかりません');
        }

        $changed = DB::transaction(function () use ($comments, $next): int {
            $count = 0;

            foreach ($comments as $comment) {
                if ($comment->status === $next->value) {
                    continue;
                }

                $comment->forceFill([
                    'status' => $next->value,
                    // 承認した時刻は**承認したときだけ**動かす（取り消しても記録は残る）
                    'approved_at' => $next === CommentStatus::Approved
                        ? ($comment->approved_at ?? now())
                        : $comment->approved_at,
                ])->save();

                $count++;
            }

            return $count;
        });

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'comments' => array_map(
                        static fn (Comment $c): array => CommentPresenter::summary($c->refresh()),
                        $comments,
                    ),
                ],
                ['status' => $next->value, 'changed' => $changed],
            ),
            resourceType: 'comment',
            // **本文も投稿者名も入れない。** 対象が分かれば足りる
            resourceId: implode(',', array_slice($ids, 0, 10)),
            metadata: ['status' => $next->value, 'count' => count($ids)],
        );
    }
}
