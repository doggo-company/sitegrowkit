<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

/**
 * 公開 URL の構造（P1 ③ 段 2）。
 *
 * ## 自由なパターンにしない
 *
 * `%year%/%postname%` のような文字列を受けると、3 つ壊れる:
 *
 * 1. **slug を含まない構造を書けてしまう**（`/%year%/` — URL が一意にならない）
 * 2. **解く側が正規表現地獄になる**（どの segment が何かを毎回推測することになる）
 * 3. **入口の衝突検査が効かなくなる**（何が top-level を占めるか静的に言えない）
 *
 * だから**4 つから選ぶ**。足りなければ値を増やす — それは後からできる。
 *
 * ## 既定は「いまの形」
 *
 * 書かなければ `base_slug`。**更新を当てただけで URL が変わってはいけない**。
 */
enum PermalinkStructure: string
{
    /** `/{base}/{slug}` — 既定。いまの形 */
    case BaseSlug = 'base_slug';

    /** `/{slug}` — **その型が top-level を占める**。選べるのは 1 型だけ */
    case Slug = 'slug';

    /** `/{base}/{YYYY}/{MM}/{slug}` */
    case BaseDateSlug = 'base_date_slug';

    /** `/{base}/{id}` — slug を持たない型でも作れる */
    case BaseId = 'base_id';

    /** 書かれていない / 読めないときはこれ */
    public const DEFAULT = self::BaseSlug;

    /**
     * 知っている値なら返す。**知らなければ null**（呼ぶ側が既定へ倒す）。
     *
     * ここで既定へ倒さないのは、**倒したことを記録したい側**が居るからである
     * （`ContentTypeSettings::$unrecognized`）。
     */
    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** 入口（`publicSlug`）を URL に含むか */
    public function usesBase(): bool
    {
        return $this !== self::Slug;
    }

    /** 公開日を URL に含むか（**日付が無ければ URL を作れない**） */
    public function usesDate(): bool
    {
        return $this === self::BaseDateSlug;
    }

    /** 記事を特定する列 */
    public function keyColumn(): string
    {
        return $this === self::BaseId ? 'id' : 'slug';
    }

    /** 画面に出す名前。**形の説明は 1 か所に置く**（画面と core で言い方がずれないように） */
    public function label(): string
    {
        return match ($this) {
            self::BaseSlug => '入口 + 記事の slug',
            self::Slug => '記事の slug だけ（サイトの入口を占めます）',
            self::BaseDateSlug => '入口 + 年 / 月 + 記事の slug',
            self::BaseId => '入口 + 記事の番号',
        };
    }

    /** その形の例（`$base` は入口。空なら `blog` を仮に置く） */
    public function example(string $base = 'blog'): string
    {
        $base = $base === '' ? 'blog' : $base;

        return match ($this) {
            self::BaseSlug => "/{$base}/hello",
            self::Slug => '/hello',
            self::BaseDateSlug => "/{$base}/2026/08/hello",
            self::BaseId => "/{$base}/42",
        };
    }

    /** URL の segment 数（入口を含む） */
    public function segmentCount(): int
    {
        return match ($this) {
            self::Slug => 1,
            self::BaseSlug, self::BaseId => 2,
            self::BaseDateSlug => 4,
        };
    }
}
