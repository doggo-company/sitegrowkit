<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

/**
 * 記事に出すコメント（Phase 9.13 / STAGE 26D・SPEC §C.3）。
 *
 * ## 出すのは承認済みだけ
 *
 * 件数も `approved` だけを数える（`WP_PARITY_BASELINE.md` §2.5）。
 * 「3 件」と出ているのに 1 件しか見えない、を作らない。
 *
 * ## 返信は親にぶら下げる
 *
 * 1 段しかないので、木を組み立てる仕組みは要らない。
 * 親の下に並べるだけで足りる（OG4）。
 *
 * ## 型が OFF のときはここを呼ばない
 *
 * 判定は `CommentGate` が持つ。ここは**渡されたものを並べるだけ**である。
 */
final class CommentThread
{
    /**
     * @param  list<array<string, mixed>>  $items  親と返信を平らに並べた表示用の形
     */
    private function __construct(
        public readonly array $items,
        public readonly int $count,
    ) {}

    public static function of(string $contentType, int $contentId): self
    {
        /** @var list<Comment> $comments */
        $comments = Comment::query()
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('status', CommentStatus::Approved->value)
            ->orderBy('id')
            ->get()
            ->all();

        /** @var array<int, list<array<string, mixed>>> $replies */
        $replies = [];
        $roots = [];

        foreach ($comments as $comment) {
            if ($comment->parent_id === null) {
                $roots[] = $comment;

                continue;
            }

            $replies[$comment->parent_id][] = CommentPresenter::publicView($comment);
        }

        $items = [];

        foreach ($roots as $root) {
            $items[] = CommentPresenter::publicView($root) + [
                'replies' => $replies[(int) $root->getKey()] ?? [],
            ];
        }

        return new self($items, count($comments));
    }

    public static function empty(): self
    {
        return new self([], 0);
    }
}
