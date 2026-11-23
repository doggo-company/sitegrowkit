<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

/**
 * URL から読み取れた「どの型の、どの記事か」（P1 ③ 段 2）。
 *
 * **見つけたとは言っていない。** 行が在るかどうかは、これを受け取った側が引く。
 * URL の読み取りと DB の照会を 1 つにすると、
 * 「その形の URL ではない」と「その記事が無い」が混ざる。
 */
final readonly class PermalinkEntryRef
{
    /**
     * @param  string  $column  記事を特定する列（`slug` / `id`）
     * @param  string|null  $yearMonth  `YYYY-MM`。日付を含む構造のときだけ。
     *                                  **URL の日付と記事の公開月が一致するか**を照らすために持つ
     */
    public function __construct(
        public string $type,
        public string $column,
        public string $value,
        public ?string $yearMonth = null,
    ) {}
}
