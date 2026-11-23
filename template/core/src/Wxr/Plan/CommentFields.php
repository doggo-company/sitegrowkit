<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Wxr\Status\WxrCommentStatus;
use AgentCms\Core\Wxr\WxrComment;

/**
 * WXR のどの値が、コメントのどの欄へ入るか（Phase 9.14 / STAGE 27D-5b・SPEC §C.4）。
 *
 * ## 対応表を 2 か所に持たない
 *
 * `plan`（入るかどうかを確かめる側）と `apply`（実際に入れる側）が別々に持つと、
 * **plan が言ったのと違うものが入る**。plan hash はそれを見つけられない —
 * hash は plan 側の判断から作るので、両方が同じ誤りをすれば一致してしまう
 * （`ItemFields` / `ItemTarget` と同じ理由。F-4・F-9 で 2 度踏んでいる）。
 *
 * ## 埋めない列は、ここにも出てこない
 *
 * `ip_hash`（§G）・`user_agent`（WXR に無い）・`author_user_id`（利用者を作らない）・
 * `approved_at`（WordPress は承認時刻を記録しない）は**この表に無い**。
 * 表に無いものは入らない、が読んで分かる形にしてある。
 */
final class CommentFields
{
    /**
     * 名前が空のときに入れる値（§C.4.1）。
     *
     * **永続化される既定値なので、実装の都合で変えてよい値ではない。**
     * 空のまま入れると、一覧が空行の並びになってどれがどれだか分からなくなる。
     */
    public const NAMELESS = '(名前なし)';

    /**
     * `content_comments` の桁。**文字数**であって byte 数ではない
     * （varchar(80) に日本語の名前は 80 文字入る。byte で見ると 26 文字で止まる）。
     */
    private const LIMITS = [
        'author' => 80,
        'email' => 255,
        'url' => 255,
    ];

    /**
     * こちらの欄へ入る値。
     *
     * @return array{author_name: string, author_email: ?string, author_url: ?string, body: string, status: string, created_at: ?string}
     */
    public static function of(WxrComment $comment): array
    {
        return [
            'author_name' => $comment->author === '' ? self::NAMELESS : $comment->author,
            'author_email' => $comment->email,
            'author_url' => $comment->url,
            'body' => $comment->content,
            'status' => WxrCommentStatus::map($comment->approved)->value,
            'created_at' => $comment->createdAt,
        ];
    }

    /**
     * 桁に入らない欄。**切らずに止めるため**に使う（§C.4.1・§C.8.4）。
     *
     * 1 件のコメントについて 1 つだけ返す。2 つ長くても直す先はその 1 件なので、
     * 2 度数えても直す手間は変わらない。
     *
     * @return 'author'|'email'|'url'|null
     */
    public static function tooLong(WxrComment $comment): ?string
    {
        $values = [
            'author' => $comment->author,
            'email' => $comment->email ?? '',
            'url' => $comment->url ?? '',
        ];

        foreach (self::LIMITS as $field => $limit) {
            if (mb_strlen($values[$field]) > $limit) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> 桁を見る欄。**内訳の鍵になる** */
    public static function limited(): array
    {
        return array_keys(self::LIMITS);
    }
}
