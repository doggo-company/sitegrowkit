<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

/**
 * PageDocument の木操作（Phase 9.1D）。
 *
 * document は **素の配列**として扱う（JSON と 1:1）。
 * ここが提供するのは「id / key で探す」「JSON Pointer を得る」だけで、
 * 業務判断（認可・確認・監査）は Application Service が行う。
 */
final class DocumentTree
{
    public const SCHEMA_VERSION = 1;

    /**
     * 空の document（新規ページの初期値）。
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'root' => [
                'id' => NodeId::generate(),
                'key' => 'page',
                'type' => 'page',
                'props' => [],
                'children' => [],
            ],
        ];
    }

    /**
     * id（または key）でノードを探す。
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public static function find(array $document, string $target): ?array
    {
        $pointer = self::pointer($document, $target);

        return $pointer === null ? null : self::at($document, $pointer);
    }

    /**
     * id（または key）から JSON Pointer（RFC 6901）を得る。
     * **id を優先**し、見つからないときだけ key を探す（key は identity ではない）。
     *
     * @param  array<string, mixed>  $document
     */
    public static function pointer(array $document, string $target): ?string
    {
        $root = $document['root'] ?? null;

        if (! is_array($root)) {
            return null;
        }

        return self::search($root, '/root', $target, 'id')
            ?? self::search($root, '/root', $target, 'key');
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function search(array $node, string $path, string $target, string $field): ?string
    {
        if (($node[$field] ?? null) === $target) {
            return $path;
        }

        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return null;
        }

        foreach ($children as $index => $child) {
            if (! is_array($child)) {
                continue;
            }

            $found = self::search($child, "{$path}/children/{$index}", $target, $field);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * JSON Pointer の位置の値を読む。
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public static function at(array $document, string $pointer): ?array
    {
        $value = $document;

        foreach (self::segments($pointer) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_array($value) ? $value : null;
    }

    /** @return list<string> */
    public static function segments(string $pointer): array
    {
        if ($pointer === '' || $pointer === '/') {
            return [];
        }

        return array_map(
            static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
            array_slice(explode('/', $pointer), 1),
        );
    }

    /**
     * すべてのノードを深さ優先で辿る。
     *
     * @param  array<string, mixed>  $document
     * @param  callable(array<string, mixed>, string, int): void  $visit  (node, pointer, depth)
     */
    public static function walk(array $document, callable $visit): void
    {
        $root = $document['root'] ?? null;

        if (is_array($root)) {
            self::walkNode($root, '/root', 0, $visit);
        }
    }

    /** @param array<string, mixed> $node */
    private static function walkNode(array $node, string $path, int $depth, callable $visit): void
    {
        $visit($node, $path, $depth);

        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return;
        }

        foreach ($children as $index => $child) {
            if (is_array($child)) {
                self::walkNode($child, "{$path}/children/{$index}", $depth + 1, $visit);
            }
        }
    }

    /**
     * document 内で使われている id / key の集合。
     *
     * @param  array<string, mixed>  $document
     * @return array{ids: array<string, true>, keys: array<string, true>}
     */
    public static function identifiers(array $document): array
    {
        $ids = [];
        $keys = [];

        self::walk($document, static function (array $node) use (&$ids, &$keys): void {
            if (is_string($node['id'] ?? null)) {
                $ids[$node['id']] = true;
            }

            if (is_string($node['key'] ?? null) && $node['key'] !== '') {
                $keys[$node['key']] = true;
            }
        });

        return ['ids' => $ids, 'keys' => $keys];
    }

    /**
     * サブツリーに **新しい id を振り直す**（複製・import 用）。
     * key は衝突する場合のみ採番し直す。
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $takenIds
     * @param  array<string, true>  $takenKeys
     * @return array<string, mixed>
     */
    public static function reidentify(array $node, array $takenIds, array &$takenKeys): array
    {
        $node['id'] = NodeId::generate();
        $takenIds[$node['id']] = true;

        $key = $node['key'] ?? null;

        if (is_string($key) && $key !== '') {
            $node['key'] = isset($takenKeys[$key])
                ? NodeId::suggestKey((string) ($node['type'] ?? 'node'), $takenKeys, $key)
                : $key;

            if (is_string($node['key'])) {
                $takenKeys[$node['key']] = true;
            } else {
                unset($node['key']);
            }
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::reidentify($child, $takenIds, $takenKeys);
                }
            }
        }

        return $node;
    }

    /**
     * ノードとその子孫の数（削除の影響範囲表示に使う）。
     *
     * @param  array<string, mixed>  $node
     */
    public static function countNodes(array $node): int
    {
        $count = 1;

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $count += self::countNodes($child);
            }
        }

        return $count;
    }
}
