<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Comments;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Comments\CommentGate;

/**
 * 記事ごとの受付可否（Phase 9.13 / STAGE 26C・SPEC §B.2）。
 *
 * ## 権限は `ContentWrite`
 *
 * これは**記事の設定の一部**である。承認（`CommentModerate`）とは別の軸で、
 * 「自分の記事のコメントを閉じる」のに他人の文章を消す権限は要らない。
 *
 * ## 「型に従う」へ戻せる
 *
 * `null` を渡すと行ごと消える。`false` を書いて代用すると、
 * **型を ON に戻したときに、その記事だけ閉じたまま**になる。
 */
final class SetEntryCommentOpen implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly CommentGate $gate,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'comment.entry.open';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $contentType, int $contentId, ?bool $isOpen, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! $this->gate->enabledForType($contentType)) {
            // 型が OFF のあいだは記事側の設定に意味が無い。黙って保存しない
            return Result::conflict(
                'この投稿タイプではコメント機能が有効になっていません。'
                .'先に投稿タイプの設定で有効にしてください。',
            );
        }

        $this->gate->setEntryOpen($contentType, $contentId, $isOpen);

        return $this->outcome->record(
            $this,
            $context,
            Result::success([
                'contentType' => $contentType,
                'contentId' => $contentId,
                'isOpen' => $isOpen,
                'acceptsNew' => $this->gate->acceptsNew($contentType, $contentId),
            ]),
            resourceType: 'content_entry',
            resourceId: "{$contentType}:{$contentId}",
            metadata: ['isOpen' => $isOpen],
        );
    }
}
