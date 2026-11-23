<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use AgentCms\Core\Taxonomy\TaxonomyKey;
use InvalidArgumentException;

/**
 * ContentSchema が宣言する taxonomy への参照（Phase 9.3B / STAGE 3A・SPEC §J.1 / §J.2）。
 *
 * **`Field` とは独立した構造**である。Field へ taxonomy 型を足さないし、
 * `select` を taxonomy として読み替えることもしない（§J.7 の 3 概念の使い分け）。
 *
 * ## checked-in の形
 *
 * ```json
 * { "key": "news_category", "multiple": true, "required": false }
 * ```
 *
 * 許可キーは **`key` / `multiple` / `required` の 3 つだけ**。
 *
 * - `key` … 必須・空でない文字列。文法は **`TaxonomyKey` が単一情報源**（§C.2）
 * - `multiple` … 必須の boolean。`"true"` / `0` / `1` を暗黙変換しない
 * - `required` … 必須の boolean。同上
 *
 * **checked-in JSON へ taxonomy ULID を書かない**（§J.2）。
 * `id` / `taxonomyId` / `name` / `label` / `terms` 等は許可しない。
 * runtime ID への解決は Resolver の責務であり、宣言の責務ではない。
 *
 * `multiple` / `required` は **Content Type 側の制約**であり、
 * Taxonomy model や taxonomy definition 本体の属性ではない（§L / §M）。
 */
final readonly class TaxonomyDeclaration
{
    public const ALLOWED_KEYS = ['key', 'multiple', 'required'];

    public function __construct(
        public string $key,
        public bool $multiple,
        public bool $required,
    ) {
        if (! TaxonomyKey::isValid($key)) {
            throw new InvalidArgumentException(
                "taxonomy key [{$key}] の形式が不正です（".TaxonomyKey::requirement().'）',
            );
        }
    }

    /**
     * @param  mixed  $data  外部入力（JSON）。object 以外や未知キーは拒否する
     * @param  string  $context  エラーメッセージ用の文脈（schema id など）
     */
    public static function fromArray(mixed $data, string $context): self
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException(
                "{$context}: taxonomies の要素はオブジェクトで指定してください",
            );
        }

        foreach (array_keys($data) as $key) {
            $name = is_string($key) ? $key : (string) $key;

            if (! in_array($name, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException(
                    "{$context}: taxonomies に未知のキー [{$name}] があります"
                    .'（使えるのは '.implode(' / ', self::ALLOWED_KEYS).' です）',
                );
            }
        }

        if (! array_key_exists('key', $data)) {
            throw new InvalidArgumentException("{$context}: taxonomies の key は必須です");
        }

        $key = $data['key'];

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException(
                "{$context}: taxonomies の key は空でない文字列で指定してください",
            );
        }

        // 前後空白を黙って落として別の値にしない。書かれたとおりに検証する
        if (! TaxonomyKey::isValid($key)) {
            throw new InvalidArgumentException(
                "{$context}: taxonomy key [{$key}] の形式が不正です（".TaxonomyKey::requirement().'）',
            );
        }

        return new self(
            key: $key,
            multiple: self::requireBool($data, 'multiple', $context),
            required: self::requireBool($data, 'required', $context),
        );
    }

    /** @param  array<array-key, mixed>  $data */
    private static function requireBool(array $data, string $name, string $context): bool
    {
        if (! array_key_exists($name, $data)) {
            throw new InvalidArgumentException("{$context}: taxonomies の {$name} は必須です");
        }

        $value = $data[$name];

        if (! is_bool($value)) {
            // "true" / 0 / 1 を暗黙変換しない
            throw new InvalidArgumentException(
                "{$context}: taxonomies の {$name} は true か false で指定してください",
            );
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'multiple' => $this->multiple,
            'required' => $this->required,
        ];
    }
}
