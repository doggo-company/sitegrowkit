<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

use Illuminate\Support\Str;

/**
 * ノードの identity（Phase 9.1D・owner override）。
 *
 * - `id`  … **immutable な機械 ID**（ULID）。並べ替え・内容変更・公開で変わらない。
 *           複製・import でも衝突しない。**これだけが identity**
 * - `key` … 任意の人間 / AI 向けキー（`hero-heading` など）。**rename 可能**で、
 *           identity としては扱わない。無くてもよい
 *
 * ULID は Laravel 標準（`Str::ulid()`）で生成する。新しい依存は追加しない。
 */
final class NodeId
{
    /** key に許す形（小文字英数とハイフン。先頭は英字） */
    private const KEY_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    private const KEY_MAX = 64;

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function isValid(string $id): bool
    {
        // ULID: 26 文字の Crockford Base32（I / L / O / U を含まない）
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1;
    }

    public static function isValidKey(string $key): bool
    {
        return $key !== ''
            && mb_strlen($key) <= self::KEY_MAX
            && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * type と既存 key 集合から、衝突しない読みやすい key を作る。
     * key は identity ではないので、生成できなければ null を返してよい。
     *
     * @param  array<string, true>  $taken
     */
    public static function suggestKey(string $type, array $taken, ?string $hint = null): ?string
    {
        $base = Str::slug($hint ?? $type);

        if ($base === '' || ! self::isValidKey($base)) {
            $base = Str::slug($type);
        }

        if ($base === '' || ! self::isValidKey($base)) {
            return null;
        }

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($i = 2; $i <= 999; $i++) {
            $candidate = "{$base}-{$i}";

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
