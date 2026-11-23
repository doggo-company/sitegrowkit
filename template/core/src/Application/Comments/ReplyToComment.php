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
use AgentCms\Core\Comments\CommentBody;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentPresenter;
use AgentCms\Core\Comments\CommentStatus;

/**
 * 管理画面から返信する（Phase 9.13 / STAGE 26C・SPEC §D.5）。
 *
 * ## 別の表を作らない
 *
 * 返信も `content_comments` の 1 行（`is_staff = true`）である。
 * 別の表にすると、表示と moderation の経路が 2 本になり、
 * 「返信だけ承認されていない」のような食い違いが起きる。
 *
 * ## 返信は 1 段まで（OG4）
 *
 * 返信への返信は作れない。深い入れ子は描画・moderation・通知の
 * すべてを一段複雑にする。得られるものが釣り合わない。
 *
 * ## 承認済みで保存する
 *
 * 書いた人が既に管理者である。自分の返信を自分で承認させる意味が無い。
 */
final class ReplyToComment implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly CommentGate $gate,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'comment.reply';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $parentId, string $body, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $parent = GetComment::find($parentId);

        if ($parent === null) {
            return Result::notFound("コメント（ID: {$parentId}）は見つかりません");
        }

        if ($parent->parent_id !== null) {
            // 返信への返信は作らない。**元のコメントへ返信してください**と言う
            return Result::validationError([
                'parent_id' => ['返信への返信はできません。元のコメントに返信してください'],
            ]);
        }

        if (! $this->gate->enabledForType($parent->content_type)) {
            return Result::conflict(
                'この投稿タイプではコメント機能が無効になっています。'
                .'先に投稿タイプの設定で有効にしてください。',
            );
        }

        $text = CommentBody::normalize($body);

        if ($text === '') {
            return Result::validationError(['body' => ['返信の内容を入力してください']]);
        }

        if (mb_strlen($text) > CommentBody::MAX_LENGTH) {
            return Result::validationError([
                'body' => ['返信が長すぎます（'.CommentBody::MAX_LENGTH.' 文字まで）'],
            ]);
        }

        $reply = new Comment;
        $reply->forceFill([
            'content_type' => $parent->content_type,
            'content_id' => $parent->content_id,
            'parent_id' => $parent->getKey(),
            // 書いた人が管理者なので、承認を挟まない
            'status' => CommentStatus::Approved->value,
            'author_name' => $context->actorLabel !== '' ? $context->actorLabel : '管理者',
            'body' => $text,
            'is_staff' => true,
            'author_user_id' => $context->actorId,
            'approved_at' => now(),
        ]);
        $reply->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['comment' => CommentPresenter::admin($reply)]),
            resourceType: 'comment',
            // **本文を入れない**（監査に他人の文章も自分の文章も残さない）
            resourceId: (string) $reply->getKey(),
            metadata: ['parent' => $parentId],
        );
    }
}
