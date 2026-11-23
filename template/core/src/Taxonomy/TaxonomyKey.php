<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * taxonomy key の文法（Phase 9.3B / STAGE 3A・SPEC §C.2）。
 *
 * **この 1 箇所だけが taxonomy key の規則を持つ。**
 * 定義ファイル（`content/taxonomies/*.json`）と ContentSchema の `taxonomies[].key` は
 * **同じ文法**でなければならない。別実装を置くと将来乖離し、
 * 「定義では通るが schema では弾かれる」といった不整合を生む。
 *
 * key は machine key であり rename しない（rename は MIGRATION_REQUIRED — §C.2）。
 */
final class TaxonomyKey
{
    /** 小文字英字で始まり、小文字英数字と `_` のみ */
    public const PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** DB の `taxonomies.key` は varchar(64) */
    public const MAX_LENGTH = 64;

    public static function isValid(string $key): bool
    {
        return $key !== ''
            && mb_strlen($key) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * 表示名からキーを作る（WP 一周 6 周目 2026-09-03）。
     *
     * 作れなければ**空**を返す。推測で埋めない —
     * 通し番号へ倒すかどうかは、既に使われているキーを知っている側が決める。
     *
     * 🔴 `-` はキーに使えない（`^[a-z][a-z0-9_]*$`）。`_` へ寄せる。
     */
    public static function suggestFrom(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        $slug = mb_substr($slug, 0, self::MAX_LENGTH);

        return self::isValid($slug) ? $slug : '';
    }

    /** 利用者向けの説明（定義ファイルと ContentSchema で同じ文言を使う） */
    public static function requirement(): string
    {
        return '小文字英字で始まり、小文字英数字と _ のみ・'.self::MAX_LENGTH.' 文字以内';
    }
}
