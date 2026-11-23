<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Status;

use AgentCms\Core\Comments\CommentStatus;
use InvalidArgumentException;

/**
 * WordPress のコメントの状態を、こちらの 4 値へ写す（Phase 9.14 / STAGE 27D-5b・SPEC §C.4）。
 *
 * ## 記事と違って、畳まない
 *
 * 記事は 2 値しか無いので畳むしかなかった（§C.8）。コメントは**4 値がそのまま在る**ので、
 * 写すだけで足りる。畳む必要が無いということは、**寄せてよい理由も無い**ということである。
 *
 * ## 知らない値を承認待ちへ寄せない
 *
 * 「分からないから安全側へ」は、ここでは安全側にならない。
 * 公開されていたコメントが黙って消えるか、隠されていたものが黙って出るかの
 * **どちらかが必ず起きる**。しかもどちらが起きたのかは、入れた後では分からない。
 *
 * 他人が書いた文章なので、**読めないなら止める**（§A.3 のとおり数えてから止める）。
 *
 * ## `spam` はここでは判定しない
 *
 * 公開面の受付は spam 判定を通るが、移行は**人の操作ではない**（§E.1）。
 * 十年前に人が承認したコメントを、いま機械が spam に落とすことはしない。
 * **元の判定をそのまま写す。**
 */
final class WxrCommentStatus
{
    /**
     * `wp:comment_approved` の値。
     *
     * `post-trashed` は「記事ごとゴミ箱へ入った」ときに WordPress が書く値で、
     * コメント自身が捨てられた `trash` とは**由来が違う**。
     * だがこちらに受け皿は 4 つしか無く、どちらも「人が捨てた」側なので `trash` へ写す。
     */
    private const MAP = [
        '1' => CommentStatus::Approved,
        '0' => CommentStatus::Pending,
        'spam' => CommentStatus::Spam,
        'trash' => CommentStatus::Trash,
        'post-trashed' => CommentStatus::Trash,
    ];

    /**
     * こちらの状態へ写す。
     *
     * @throws InvalidArgumentException 知らない値（`plan` が先に止めているので、ここへは来ない）
     */
    public static function map(string $source): CommentStatus
    {
        if (! self::canRead($source)) {
            /*
             * **値そのものを出さない。** 断る理由は形であって中身ではない。
             */
            throw new InvalidArgumentException('wp:comment_approved に知らない値が入っています。');
        }

        return self::MAP[$source];
    }

    /** 読める値か。**止めるかどうかを決める側**が使う（数えてから止めるため） */
    public static function canRead(string $source): bool
    {
        return array_key_exists($source, self::MAP);
    }
}
