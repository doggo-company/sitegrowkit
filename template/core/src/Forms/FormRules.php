<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRules;

/**
 * 公開フォームの検証ルール（Phase 9.12 / STAGE 25C・SPEC §C.3-1）。
 *
 * ## 手書きしない
 *
 * ルールは `SchemaRules` から導く。フォーム用に別のルール生成を書くと、
 * **同じ Field 定義が画面によって違う強さで検証される**ことになる。
 * `SchemaRules` は `ContentSchema` を受けるので、その束から使い捨ての
 * schema を組み立てて渡す。
 *
 * ## `unique` だけは落とす
 *
 * `unique` は `Rule::unique($table, $field->key)` になる。フォームの入力値は
 * `form_submissions.data` の JSON の中にあり、**その名前の列は存在しない**。
 * そのまま渡すと検証の瞬間に SQL が落ちる。「一度しか送れない」は JSON への
 * 制約では表現できないので、別の題材として扱う。
 */
final class FormRules
{
    /**
     * @param  list<array<string, mixed>>  $declarations  Field Group の生の宣言
     * @return array<string, list<mixed>>
     */
    public static function fromDeclarations(array $declarations): array
    {
        if ($declarations === []) {
            return [];
        }

        $schema = new ContentSchema(
            id: 'form',
            label: 'フォーム',
            kind: 'submissions',
            fields: array_map(
                static fn (array $one): Field => Field::fromArray(self::withoutUnique($one)),
                $declarations,
            ),
            table: 'form_submissions',
        );

        return SchemaRules::for($schema, 'form_submissions');
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     */
    private static function withoutUnique(array $declaration): array
    {
        unset($declaration['unique']);

        if (is_array($declaration['fields'] ?? null)) {
            $declaration['fields'] = array_map(
                static fn (mixed $child): mixed => is_array($child) ? self::withoutUnique($child) : $child,
                array_values($declaration['fields']),
            );
        }

        return $declaration;
    }
}
