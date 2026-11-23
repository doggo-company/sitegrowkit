<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;

/**
 * その版の時点の**項目定義**を、復元の可否に関わる分だけ写し取る
 * （Phase 9.8 / STAGE 21D・SPEC §F.2.0・§F.2.1）。
 *
 * ## hash だけでは足りない
 *
 * hash は「違う」ことしか言えない。**何がどう違うか**を言えないと、
 * 「この項目は復元できません」を利用者へ説明できず、preview も作れない。
 * だから定義そのものを残す。
 *
 * ## 何を入れて、何を入れないか
 *
 * 規準は **「復元できるかどうかに影響するか」** の一点である。
 * 表示名を直しただけで「schema が変わりました」と出るのは、警告として役に立たない。
 *
 * | 入れる | なぜ |
 * | --- | --- |
 * | `identity` / `key` / `type` / `storage` | どこにどう入っていたか |
 * | `required` / `unique` / `from` / `default` | 戻した値がそのまま通るかを左右する |
 * | `max` / `choices` の**値そのもの** | 選択肢から値が消えていれば、その版の値は通らない |
 * | `validation` の規則そのもの | 厳しくなっていれば通らない |
 * | `target` | 参照先が変わっていれば、版の ID は別の型を指す |
 * | `children` | 入れ子も同じ規則で |
 *
 * | 入れない | なぜ |
 * | --- | --- |
 * | `label` / `help` / `fieldset` | 表示だけ |
 * | `searchable` / `filterable` / `sortable` | 一覧の挙動。値の復元に影響しない |
 * | 並び順 | 並べ替えただけで別物にしない（`identity` の昇順へ正規化する） |
 * | 利用者の入力 | ここは定義であって内容ではない |
 */
final class SchemaManifest
{
    /**
     * schema を canonical な形にする。**並び順は `identity()` の昇順**。
     *
     * `identity()` は `id ?? key` である（既存契約）。field ID を持つ schema と
     * 持たない schema が混在しているので、型ごとの分岐を書かずに済むこの規則へ揃える。
     *
     * @return list<array<string, mixed>>
     */
    public static function canonical(ContentSchema $schema): array
    {
        return self::canonicalFields($schema->fields);
    }

    /** canonical JSON の SHA-256。**キー順まで固定**して初めて安定する */
    public static function fingerprint(ContentSchema $schema): string
    {
        return hash('sha256', self::encode(self::canonical($schema)));
    }

    /**
     * 比較・保存に使う JSON。
     *
     * `JSON_UNESCAPED_*` を付けるのは、同じ内容が別のバイト列にならないようにするため。
     *
     * @param  list<array<string, mixed>>  $canonical
     */
    public static function encode(array $canonical): string
    {
        return (string) json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * 2 つの定義が**同じ内容か**。
     *
     * 単純な `===` では足りない。MySQL の `json` 型は保存時にオブジェクトの
     * **キー順を並べ替える**ので、読み戻すと書いたときと順序が違う。
     * バイト列で比べると「壊れている」と誤って判定してしまう
     * （SQLite は文字列のまま持つので、そこでは気づけない）。
     *
     * 見たいのは中身なので、**オブジェクトのキー順だけ**をそろえて比べる。
     * list の順序は内容そのもの（identity の昇順）なので触らない。
     *
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        return self::sortKeys($a) === self::sortKeys($b);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  list<Field>  $fields
     * @return list<array<string, mixed>>
     */
    private static function canonicalFields(array $fields): array
    {
        $canonical = array_map(self::canonicalField(...), $fields);

        // **並べ替えただけで fingerprint を変えない**
        usort(
            $canonical,
            static fn (array $a, array $b): int => strcmp((string) $a['identity'], (string) $b['identity']),
        );

        return $canonical;
    }

    /** @return array<string, mixed> */
    private static function canonicalField(Field $field): array
    {
        /*
         * **キーの順序をここで固定する。** PHP の連想配列は挿入順を保つので、
         * この並びがそのまま JSON の並びになる。並びが揺れると hash も揺れる。
         */
        return [
            'identity' => $field->identity(),
            'key' => $field->key,
            'type' => $field->type,
            'storage' => $field->storage()->value,
            'required' => $field->required,
            'unique' => $field->unique,
            'from' => $field->from,
            'default' => $field->default,
            'max' => $field->max,
            'choices' => $field->choices,
            'validation' => self::sortedByKey($field->validation),
            'target' => $field->target,
            'children' => self::canonicalFields($field->fields),
        ];
    }

    /**
     * 規則は**キーの昇順**で持つ。書いた順で hash が変わらないようにする。
     *
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private static function sortedByKey(array $validation): array
    {
        ksort($validation);

        return $validation;
    }
}
