<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Support\Str;

/**
 * Taxonomy / Term の identity（Phase 9.3B / STAGE 1・SPEC §C.1）。
 *
 * **ULID 生成はここ 1 箇所に集約する。** 他のクラスが `Str::ulid()` を直接呼ばない。
 *
 * Laravel の `HasUlids` trait は **小文字**の ULID を返すため使わない。
 * キット既存の `Document\NodeId` が大文字の Crockford Base32 を使っており、
 * PageDocument の filter が term ID を保持する（§O）ため、
 * **表記を揃えないと将来の参照探索が取りこぼす**。
 */
final class TaxonomyId
{
    /** ULID: 26 文字の Crockford Base32（I / L / O / U を含まない） */
    private const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
