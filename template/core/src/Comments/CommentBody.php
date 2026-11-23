<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

/**
 * 本文の扱い（Phase 9.13 / STAGE 26C・SPEC §F.2・§F.3）。
 *
 * ## 保存は原文のまま
 *
 * 改行と前後の空白だけを整える。**書かれた言葉を改変しない。**
 * HTML を無害化するのは**表示のとき**であって、保存のときではない
 * （保存時に削ると、あとから「本当は何と書かれていたか」を確かめられなくなる）。
 *
 * ## 出すときはエスケープする
 *
 * `richtext` のような許可 tag の一覧を持たない。**HTML を 1 つも許さない。**
 * コメント欄に装飾が要る場面より、未認証の相手に tag を通す危険の方が大きい。
 */
final class CommentBody
{
    public const MAX_LENGTH = 2000;

    /** スパムの典型。これを超えたら自動で `spam` へ落とす（拒否はしない — OG6） */
    public const MAX_LINKS = 3;

    /** 保存する形（改行を揃え、前後の空白を落とすだけ） */
    public static function normalize(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }

    /** 含まれる URL の本数 */
    public static function linkCount(string $body): int
    {
        return preg_match_all('#https?://#i', $body) ?: 0;
    }

    /** リンクが多すぎるか（スパムの典型） */
    public static function looksLikeLinkSpam(string $body): bool
    {
        return self::linkCount($body) > self::MAX_LINKS;
    }
}
