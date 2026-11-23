<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Status;

/**
 * 記事がコメントを受け付けるか（Phase 9.14 / STAGE 27D-5c・SPEC §C.4.3）。
 *
 * ## 閉じているときだけ書く
 *
 * 9.13 の `content_comment_settings` は**行が無ければ型の設定を継承する**。
 * 既定値を全記事へ書き込むと、型の設定を変えたときに
 * **過去の記事だけ古い値のまま**になる。だから `open` では行を書かない。
 *
 * ## 取り込まないと、閉じていた記事が開く
 *
 * 元のサイトで閉じていた記事が、移行後は開いた状態になる。
 * 十年前の記事に新しいコメントが付き始める。閉じたのは持ち主の判断である。
 *
 * ## 代償も書いてある（§C.4.3）
 *
 * 型が閉じていて記事が `open` だった場合、移行後は閉じたままになる。
 * ここで `open` も書く設計にすると、9.13 が避けている
 * 「全記事へ既定値を書く」に戻る。**代償を承知でそちらを採らない。**
 */
final class CommentSetting
{
    private const OPEN = 'open';

    private const CLOSED = 'closed';

    /** 読める値か。**知らない値は数えて報告する**（§C.4.3・§A.3） */
    public static function isKnown(string $source): bool
    {
        return $source === self::OPEN || $source === self::CLOSED;
    }

    /**
     * 行を書くべきか。**`closed` のときだけ true。**
     *
     * `open` も、知らない値も、要素が無いのも書かない — どれも
     * 「型の設定に従う」で、9.13 の既定の動きである。
     */
    public static function shouldClose(?string $source): bool
    {
        return $source === self::CLOSED;
    }
}
