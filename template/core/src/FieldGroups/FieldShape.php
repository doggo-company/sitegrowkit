<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use InvalidArgumentException;

/**
 * DB から来た field 宣言の **形**だけを確かめる（Phase 9.5-A CORRECTION / ADR-0015）。
 *
 * ## なぜ `Field::fromArray()` の検証だけでは足りないのか
 *
 * `Field::fromArray()` は「型が既知か」「select に choices があるか」といった
 * **意味**を見る。前提として、渡される配列の各キーが期待した形であることを信じている。
 * ファイル由来の schema はレビューを通るのでそれで足りるが、
 * **DB は外部入力**であり、JSON として有効なまま形だけ違う値が入り得る。
 *
 * | 入力 | `Field::fromArray()` の中で起きること |
 * | --- | --- |
 * | `"choices": "text"` | `array_map()` が **TypeError** |
 * | `"fields": "text"` | `array_values()` が **TypeError** |
 * | `"label": []` | `Array to string conversion` → Laravel では **ErrorException** |
 *
 * どれも `InvalidArgumentException` ではないので fail closed に落ちず、
 * **DB の 1 行で `SchemaRegistry` を使う全画面が 500 になる**。
 *
 * ## 役割分担
 *
 * | | 見るもの | 置き場所 |
 * | --- | --- | --- |
 * | 形 | 配列か / 文字列か / list か | **このクラス**（DB 境界だけ） |
 * | 意味 | 既知の型か / choices が要るか / 参照先があるか | `Field::fromArray()`（唯一の情報源） |
 *
 * 型の一覧や必須条件をここへ書き写さない。写すと 2 か所が食い違う。
 *
 * ## 理由に値を載せない
 *
 * 例外文には **キー名と期待する形**しか入れない。値そのものは入れない
 * （`OperationAudit` の metadata 規約と同じ作法）。
 */
final class FieldShape
{
    /** `(string)` へ渡されるキー。配列や object が来ると警告 → ErrorException になる */
    private const TEXT_KEYS = ['key', 'type', 'label', 'from', 'fieldset', 'group', 'id', 'help', 'target', 'storage'];

    /**
     * DB の宣言に**書いてはいけない**キー。
     *
     * `storage` は「この項目をカラムに置く」という宣言で、カラム化は
     * migration を伴う opt-in（owner 決定 G2-(c)）である。DB 側から選ばせると
     * **存在しないカラムへ書きに行って投稿の保存が壊れる**。
     * overlay は読み込み時に必ず `json` を付けるので、保存する必要も無い。
     *
     * @var list<string>
     */
    private const DB_FORBIDDEN_KEYS = ['storage'];

    /** `(bool)` へ渡されるキー。配列でも落ちないが、意味のある値ではない */
    private const FLAG_KEYS = ['required', 'unique', 'searchable', 'filterable', 'sortable'];

    /**
     * 1 件の宣言を検証する（入れ子も同じ保証で辿る）。
     *
     * @throws InvalidArgumentException 形が違うとき
     */
    public static function assert(mixed $entry, string $path, int $depth = 1): void
    {
        // **上限は我々が持つ**（Phase 9.5-C / ADR-0017）。
        // decoder の偶発的な最大深さを仕様上限として使わない
        if ($depth > FieldGroupLimits::MAX_DEPTH) {
            throw new InvalidArgumentException(FieldGroupLimits::depthMessage($path));
        }

        if (! is_array($entry) || array_is_list($entry)) {
            throw new InvalidArgumentException("{$path} はオブジェクトで指定してください");
        }

        foreach (['key', 'type'] as $required) {
            if (! array_key_exists($required, $entry)) {
                throw new InvalidArgumentException("{$path}.{$required} が指定されていません");
            }
        }

        foreach (self::DB_FORBIDDEN_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $entry)) {
                throw new InvalidArgumentException("{$path}.{$forbidden} は指定できません");
            }
        }

        foreach (self::TEXT_KEYS as $key) {
            self::assertText($entry, $key, $path);
        }

        foreach (self::FLAG_KEYS as $key) {
            self::assertFlag($entry, $key, $path);
        }

        self::assertMax($entry, $path);
        self::assertChoices($entry, $path);
        self::assertValidation($entry, $path);
        self::assertNestedFields($entry, $path, $depth);

        // `default` は `Field` が cast せずそのまま持つので、形の制約を課さない
    }

    /** @param array<string, mixed> $entry */
    private static function assertText(array $entry, string $key, string $path): void
    {
        $value = $entry[$key] ?? null;

        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return;
        }

        throw new InvalidArgumentException("{$path}.{$key} は文字列で指定してください");
    }

    /** @param array<string, mixed> $entry */
    private static function assertFlag(array $entry, string $key, string $path): void
    {
        $value = $entry[$key] ?? null;

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        throw new InvalidArgumentException("{$path}.{$key} は true か false で指定してください");
    }

    /**
     * `max` は **整数**（`Field::$max` は `?int`）。
     *
     * `is_numeric` を許すと `"1.5"` や `"1e2"` が通り、あとの `(int)` キャストで
     * **黙って別の値へ変わる**（1.5 → 1 / 1e2 → 1）。書いた人は 1.5 のつもりで、
     * 効くのは 1 になる。小数・指数・符号付きの曖昧な表記は受け取らない。
     *
     * @param  array<string, mixed>  $entry
     */
    private static function assertMax(array $entry, string $path): void
    {
        $value = $entry['max'] ?? null;

        if ($value === null || is_int($value) || (is_string($value) && ctype_digit($value))) {
            return;
        }

        throw new InvalidArgumentException("{$path}.max は整数で指定してください");
    }

    /** @param array<string, mixed> $entry */
    private static function assertChoices(array $entry, string $path): void
    {
        if (! array_key_exists('choices', $entry)) {
            return;
        }

        $choices = $entry['choices'];

        // object で書かれた choices は `array_map` を素通りして
        // **キーを黙って捨てた list** になる。通さない
        if (! is_array($choices) || ! array_is_list($choices)) {
            throw new InvalidArgumentException("{$path}.choices は配列で指定してください");
        }

        foreach ($choices as $index => $choice) {
            if (is_string($choice) || is_int($choice) || is_float($choice)) {
                continue;
            }

            throw new InvalidArgumentException("{$path}.choices[{$index}] は文字列で指定してください");
        }
    }

    /**
     * `validation` は **JSON の object だけ**受理する（CORRECTION #2）。
     *
     * `Field::$validation` の契約は `array<string, mixed>` で、
     * `SchemaRules` は **文字キーしか見ない**。list で書かれた validation は
     * `is_array()` を素通りして保存できてしまい、**全ルールが黙って無視される**。
     * 「書いた人は効いていると誤解する」経路なので、非空 list は通さない。
     *
     * **空だけは例外。** `json_decode($json, true)` は `{}` と `[]` を
     * どちらも空配列にするため、**空 object と空 list を区別できない**。
     * 空は「ルールなし」で害が無いので、後方互換として受理する。
     *
     * @param  array<string, mixed>  $entry
     */
    private static function assertValidation(array $entry, string $path): void
    {
        $validation = $entry['validation'] ?? null;

        if ($validation === null) {
            return;
        }

        // 空配列 = 空 object か空 list。JSON へ復号した時点で見分けが付かず、
        // どちらでも結果は「ルールなし」なので通す
        if (is_array($validation) && ($validation === [] || ! array_is_list($validation))) {
            return;
        }

        throw new InvalidArgumentException(
            "{$path}.validation はオブジェクトで指定してください（配列で書くとルールが無視されます）",
        );
    }

    /** @param array<string, mixed> $entry */
    private static function assertNestedFields(array $entry, string $path, int $depth): void
    {
        if (! array_key_exists('fields', $entry)) {
            return;
        }

        $fields = $entry['fields'];

        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new InvalidArgumentException("{$path}.fields は配列で指定してください");
        }

        foreach ($fields as $index => $nested) {
            // 入れ子も **同じ保証**で辿る（group / repeater の中だけ緩い、を作らない）
            self::assert($nested, "{$path}.fields[{$index}]", $depth + 1);
        }
    }
}
