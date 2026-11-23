<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Status;

use InvalidArgumentException;

/**
 * WordPress の公開状態を、こちらの 2 値へ写す（Phase 9.14 / STAGE 27C・SPEC §C.8）。
 *
 * ## 知らない状態を公開側へ倒さない
 *
 * WordPress は `publish` / `draft` / `private` / `pending` / `future` / `trash` /
 * `inherit` / `auto-draft` を持ち、プラグインはさらに足せる。
 * こちらは 2 つしかないので、**公開してよいと確かめられたものだけ**を公開にする。
 *
 * 白い一覧に無いものを通す作りにすると、プラグインが状態を 1 つ足すたびに
 * **他人のサイトの非公開だった記事がこちらで公開される**。
 * 取り消すまでの間、それは世界に出ている。
 *
 * ## 写すことと残すことは別
 *
 * 読めない値でも「公開ではない」とは読めるので、下書きへは倒せる。
 * 止めるのは**元の値を残せないこと**の方である（§C.8.4）。
 * 2 つを同じ関数にすると、「写せなかった」と「残せなかった」が同じ顔になる。
 */
final class WxrStatus
{
    /** こちらで公開になる唯一の値 */
    private const PUBLISHED = 'publish';

    /**
     * 残せる形（§C.8.4）。
     *
     * `wp_posts.post_status` は varchar(20) で、登録済みの状態の slug しか入らない。
     * **`auto-draft` は core の正規の状態なので、ハイフンを必ず通す。**
     * 他所の状態名の規則（`RelevantTargetState`）を流用するとここで弾かれる。
     */
    private const PATTERN = '/^[A-Za-z0-9_-]{1,20}$/';

    /**
     * こちらの状態へ畳む。**`publish` だけが公開になる。**
     *
     * 前後の空白や大文字は WordPress の値として正しくないので、
     * **寄せて読まない**。寄せると「似ているから公開」を認めることになり、
     * どこまで似ていれば公開なのかを誰も答えられなくなる。
     */
    public static function map(string $source): ContentStatus
    {
        return $source === self::PUBLISHED ? ContentStatus::Published : ContentStatus::Draft;
    }

    /**
     * 元の値を**そのまま**返す。残せない形なら投げる（§C.8.4）。
     *
     * 切り詰めれば元へ戻せず、置き換えれば別の値を捏造し、
     * 黙って落とせばその 1 件だけ出自が消える。**3 つとも取れない。**
     *
     * @throws InvalidArgumentException 識別子の形でない
     */
    public static function preserve(string $source): string
    {
        if (! self::canPreserve($source)) {
            /*
             * **値そのものを出さない。** 断る理由は形であって中身ではなく、
             * ここで echo すると本文がそのまま例外の文面に載る。
             */
            throw new InvalidArgumentException(
                'wp:status は識別子の形だけを残せます（半角の英数字と `_` `-`、20 byte まで）。',
            );
        }

        return $source;
    }

    /** 残せる形か。**止めるかどうかを決める側**が使う（数えてから止めるため） */
    public static function canPreserve(string $source): bool
    {
        return preg_match(self::PATTERN, $source) === 1;
    }
}
