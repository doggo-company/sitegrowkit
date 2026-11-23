<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

/**
 * その変更で**何が動くのか**（P1 ③ 段 3・F-41）。
 *
 * ## 「入口が在るか」と「記事 URL に入口が入るか」は別の問い
 *
 * 最初はこの 2 つを `usesBase()` 1 つで兼ねていた。その結果、
 * **`slug` 構造のまま入口の名前だけ変える**とき（記事 URL は `/hello` のままで動かず、
 * 動くのは一覧の `/blog` だけ）に、**起きないことを理由に変更を断っていた**。
 *
 * 動くものを 4 つに分けて名前を付ける。分ければ、どれが転送で書けるかは一目で決まる。
 */
enum PermalinkMoveKind
{
    /** 何も動かない（設定を保存し直しただけ／守る URL が無い） */
    case Nothing;

    /** **記事の URL が動く**。入口の付け替え・取り外しなので、接頭辞 1 本で書ける */
    case Entries;

    /** **一覧の URL だけが動く**（記事は `/hello` のまま）。これも接頭辞 1 本で書ける */
    case ArchiveOnly;

    /**
     * **1 本では書けない。**
     *
     * 入口を足す向き（source が `/` になり**サイト全体**を巻き込む）か、
     * 入口より後ろの形が変わる場合（途中への挿入・削除は接頭辞で表せない）。
     */
    case Unwritable;

    /** 転送を置くべきか */
    public function needsRedirect(): bool
    {
        return $this === self::Entries || $this === self::ArchiveOnly;
    }
}
