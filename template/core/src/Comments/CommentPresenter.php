<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

/**
 * コメントを外へ出す形（Phase 9.13 / STAGE 26C・SPEC §G.1）。
 *
 * ## 公開面と管理画面で出すものが違う
 *
 * **メールアドレスは管理画面にしか出さない。** 公開面の形にそもそも入れない
 * （入れておいて view で出さない、にすると 1 か所忘れただけで漏れる）。
 * `ip_hash` はどちらにも出さない — 在ることが分かれば足りる（9.12 と同じ扱い）。
 */
final class CommentPresenter
{
    /**
     * 公開面。**メールアドレスも IP も入っていない。**
     *
     * @return array<string, mixed>
     */
    public static function publicView(Comment $comment): array
    {
        return [
            'id' => (int) $comment->getKey(),
            'parent_id' => $comment->parent_id,
            'author_name' => $comment->author_name,
            'author_url' => $comment->author_url,
            'body' => $comment->body,
            'is_staff' => $comment->is_staff,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    /**
     * 管理画面。
     *
     * @return array<string, mixed>
     */
    public static function admin(Comment $comment): array
    {
        return self::publicView($comment) + [
            'content_type' => $comment->content_type,
            'content_id' => $comment->content_id,
            'status' => $comment->status,
            'status_label' => $comment->status()->label(),
            // 返信するために要る。**公開面には出さない**
            'author_email' => $comment->author_email,
            // 生の IP も hash も出さない。在ることだけ分かれば足りる
            'has_ip' => $comment->ip_hash !== null,
            'approved_at' => $comment->approved_at?->toIso8601String(),
        ];
    }

    /**
     * 一覧に出す短い形（本文を全部積まない）。
     *
     * @return array<string, mixed>
     */
    public static function summary(Comment $comment): array
    {
        return [
            'id' => (int) $comment->getKey(),
            'content_type' => $comment->content_type,
            'content_id' => $comment->content_id,
            'status' => $comment->status,
            'status_label' => $comment->status()->label(),
            'author_name' => $comment->author_name,
            'excerpt' => mb_substr($comment->body, 0, 80),
            'is_reply' => $comment->parent_id !== null,
            'is_staff' => $comment->is_staff,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
