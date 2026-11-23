<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

use InvalidArgumentException;

/**
 * RFC 6902 JSON Patch の適用（Phase 9.1D・owner 決定 G22）。
 *
 * **外向きの契約は semantic command**（`component.update` 等）で、
 * その内部表現としてここを使う。AI へ生の JSON Pointer を書かせない。
 *
 * 対応 op: add / remove / replace / move。
 * copy / test は現状の operation 集合で必要にならないため実装しない
 * （必要になった時点で足す。使わない汎用性を先に作らない）。
 */
final class JsonPatch
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $operations
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException 適用できない patch
     */
    public static function apply(array $document, array $operations): array
    {
        foreach ($operations as $operation) {
            $op = $operation['op'] ?? null;
            $path = $operation['path'] ?? null;

            if (! is_string($op) || ! is_string($path)) {
                throw new InvalidArgumentException('patch の op / path が不正です');
            }

            $document = match ($op) {
                'add' => self::add($document, $path, $operation['value'] ?? null),
                'remove' => self::remove($document, $path),
                'replace' => self::replace($document, $path, $operation['value'] ?? null),
                'move' => self::move($document, self::requireString($operation, 'from'), $path),
                default => throw new InvalidArgumentException("未対応の patch op: {$op}"),
            };
        }

        return $document;
    }

    /**
     * patch を打ち消す逆 patch を作る（undo の材料）。
     * **適用前の document**を基準に生成する。
     *
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public static function invert(array $document, array $operations): array
    {
        $inverse = [];
        $state = $document;

        foreach ($operations as $operation) {
            $op = (string) ($operation['op'] ?? '');
            $path = (string) ($operation['path'] ?? '');

            $inverse[] = match ($op) {
                'add' => ['op' => 'remove', 'path' => $path],
                'remove' => ['op' => 'add', 'path' => $path, 'value' => self::read($state, $path)],
                'replace' => ['op' => 'replace', 'path' => $path, 'value' => self::read($state, $path)],
                'move' => ['op' => 'move', 'from' => $path, 'path' => self::requireString($operation, 'from')],
                default => throw new InvalidArgumentException("未対応の patch op: {$op}"),
            };

            $state = self::apply($state, [$operation]);
        }

        return array_reverse($inverse);
    }

    /** @param array<string, mixed> $operation */
    private static function requireString(array $operation, string $key): string
    {
        $value = $operation[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("patch の {$key} が不正です");
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    public static function read(array $document, string $path): mixed
    {
        $segments = DocumentTree::segments($path);
        $value = $document;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new InvalidArgumentException("patch の path が存在しません: {$path}");
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function replace(array $document, string $path, mixed $value): array
    {
        self::read($document, $path); // 存在確認（RFC: replace は対象が無ければ失敗）

        return self::write($document, DocumentTree::segments($path), $value, replace: true);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function add(array $document, string $path, mixed $value): array
    {
        return self::write($document, DocumentTree::segments($path), $value, replace: false);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function remove(array $document, string $path): array
    {
        self::read($document, $path);

        return self::unset($document, DocumentTree::segments($path));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<array-key, mixed>
     */
    private static function move(array $document, string $from, string $path): array
    {
        $value = self::read($document, $from);
        $document = self::unset($document, DocumentTree::segments($from));

        return self::write($document, self::afterRemoval($from, $path), $value, replace: false);
    }

    /**
     * 移動先を **取り出したあとの並び**へ合わせる。
     *
     * `move` は「取り出して入れ直す」で実現するが、移動先の位置は
     * 取り出す**前**の木を見て決められている。取り出した位置より後ろへ入れる場合、
     * 後ろの要素は 1 つずつ前へ詰むので、そのままの添字を使うと 1 つ行き過ぎる。
     *
     * 効くのは 2 つの場合:
     *
     * - 同じ親の中で**下へ**動かす（`[A,B,C]` の A を B の後ろへ → `[B,A,C]`）
     * - **後ろの兄弟の中へ**動かす（その兄弟自身の添字も 1 つ前へ詰む）
     *
     * @return list<string>
     */
    private static function afterRemoval(string $from, string $path): array
    {
        $fromSegments = DocumentTree::segments($from);
        $pathSegments = DocumentTree::segments($path);

        $shared = min(count($fromSegments), count($pathSegments));

        for ($i = 0; $i < $shared; $i++) {
            if ($fromSegments[$i] === $pathSegments[$i]) {
                continue;
            }

            // 枝が分かれる位置。同じ配列の添字どうしのときだけ詰める
            if (ctype_digit($fromSegments[$i])
                && ctype_digit($pathSegments[$i])
                && (int) $fromSegments[$i] < (int) $pathSegments[$i]
            ) {
                $pathSegments[$i] = (string) ((int) $pathSegments[$i] - 1);
            }

            break;
        }

        return $pathSegments;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $target
     * @param  list<string>  $segments
     * @return array<string, mixed>|list<mixed>
     */
    private static function write(array $target, array $segments, mixed $value, bool $replace): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            throw new InvalidArgumentException('ドキュメント全体は patch できません');
        }

        if ($segments === []) {
            if (array_is_list($target) && ! $replace) {
                $index = $segment === '-' ? count($target) : (int) $segment;
                array_splice($target, $index, 0, [$value]);

                return $target;
            }

            $target[$segment] = $value;

            return $target;
        }

        $child = $target[$segment] ?? null;

        if (! is_array($child)) {
            throw new InvalidArgumentException('patch の path が存在しません');
        }

        $target[$segment] = self::write($child, $segments, $value, $replace);

        return $target;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $target
     * @param  list<string>  $segments
     * @return array<string, mixed>|list<mixed>
     */
    private static function unset(array $target, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            throw new InvalidArgumentException('ドキュメント全体は削除できません');
        }

        if ($segments === []) {
            if (array_is_list($target)) {
                array_splice($target, (int) $segment, 1);

                return $target;
            }

            unset($target[$segment]);

            return $target;
        }

        $child = $target[$segment] ?? null;

        if (! is_array($child)) {
            throw new InvalidArgumentException('patch の path が存在しません');
        }

        $target[$segment] = self::unset($child, $segments);

        return $target;
    }
}
