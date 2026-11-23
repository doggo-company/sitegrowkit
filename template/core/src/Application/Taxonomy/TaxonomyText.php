<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

/**
 * 分類の表示テキストの受け取り方（Phase 9.7 / STAGE 20D）。
 *
 * 表示名も説明も **1 行のテキスト**である。改行と制御文字を通すと、
 * 一覧の高さが崩れるだけでなく、ログや監査の 1 行 1 件が壊れる。
 */
final class TaxonomyText
{
    /** 妥当なら trim 済みの文字列、そうでなければ null */
    public static function line(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > $max) {
            return null;
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $trimmed) === 1 || ! mb_check_encoding($trimmed, 'UTF-8')) {
            return null;
        }

        return $trimmed;
    }
}
