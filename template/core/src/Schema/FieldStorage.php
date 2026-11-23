<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

/**
 * フィールドの永続化方式（Phase 9.2B・owner 決定 G2 = Hybrid）。
 *
 * - `Column` … 1 フィールド = 1 カラム。検索・絞り込み・並び替えに使える
 * - `Json`   … `data` JSON カラムの中。入れ子・順序つきの複合フィールド
 *
 * **同じ値を両方へ二重保存しない**（Single Source of Truth）。
 */
enum FieldStorage: string
{
    case Column = 'column';
    case Json = 'json';

    /** JSON へ入れる型（owner 決定の初期分類） */
    private const JSON_TYPES = ['group', 'repeater', 'images', 'relations'];

    public static function forType(string $type): self
    {
        return in_array($type, self::JSON_TYPES, true) ? self::Json : self::Column;
    }
}
