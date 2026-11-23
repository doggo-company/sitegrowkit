<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Schema\ContentSchema;

/**
 * content payload の `taxonomies` を**構造だけ**検証する（Phase 9.3B / STAGE 3B・SPEC §K.1 / §K.2）。
 *
 * **DB へ触らない。** term の解決・差分・排他は `ContentTaxonomyAssignments` の責務であり、
 * ここは transaction を開く**前**に確定できる形の誤りだけを弾く。
 *
 * ## contract
 *
 * | 入力 | 扱い |
 * | --- | --- |
 * | `taxonomies` キー自体が無い（VO が null） | 変更なし |
 * | `taxonomies: null` | **validation error**（全解除の意味を与えない） |
 * | `taxonomies: "x"` / `1` / `true` | **validation error** |
 * | `taxonomies: {}`（空 map） | 対象 key が無いので**変更なし** |
 * | `taxonomies: ["a"]`（非空の numeric list） | **validation error** |
 * | `taxonomies: {"未宣言key": [...]}` | **validation error**（黙って無視しない） |
 * | `taxonomies: {"key": "x"}` | **validation error**（scalar を 1 件 list へ変換しない） |
 * | `taxonomies: {"key": [1]}` / `[null]` / `[{}]` | **validation error**（文字列へ暗黙変換しない） |
 * | `taxonomies: {"key": []}` | 全解除の要求として受理（required 判定は最終集合で行う） |
 *
 * ## PHP の境界（空 JSON object と空 list）
 *
 * `json_decode('{}', true)` も `json_decode('[]', true)` も **同じ `[]`** になる。
 * したがって「空 map」と「空 list」は PHP では区別できない。
 * 空は**変更なし**として受理し、**非空の numeric list だけ**を拒否する。
 * この境界はテスト（空 map / 非空 list）で固定してある。
 */
final class ContentTaxonomyInput
{
    /**
     * payload / read response のキー。
     *
     * **単一情報源は `ContentSchema::TAXONOMIES_KEY`**（同じ名前が
     * トップレベル field の予約キーでもあるため — STAGE 3B CORRECTION / F-3B-1）。
     */
    public const RESPONSE_KEY = ContentSchema::TAXONOMIES_KEY;

    /**
     * @return array{map: array<string, list<string>>|null, errors: array<string, list<string>>}
     *                                                                                           `map` が null ならエラーあり。空 map は「変更なし」
     */
    public function parse(ContentSchema $schema, ?TaxonomyPayload $payload): array
    {
        if ($payload === null) {
            return ['map' => [], 'errors' => []];
        }

        $raw = $payload->raw;

        if (! is_array($raw)) {
            return ['map' => null, 'errors' => [self::RESPONSE_KEY => [
                $raw === null
                    ? '分類を変更しない場合は taxonomies ごと省略してください（null は使えません）'
                    : '分類は「分類キー → 項目の配列」の形で指定してください',
            ]]];
        }

        // 空は「対象 key が無い」= 変更なし。**非空の numeric list だけ**を拒否する
        if ($raw !== [] && array_is_list($raw)) {
            return ['map' => null, 'errors' => [self::RESPONSE_KEY => [
                '分類は「分類キー → 項目の配列」の形で指定してください（配列だけでは分類を特定できません）',
            ]]];
        }

        $map = [];
        $errors = [];

        foreach ($raw as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;
            $path = self::RESPONSE_KEY.'.'.$name;

            if (! $schema->hasTaxonomy($name)) {
                $errors[$path][] = "{$schema->label}に分類「{$name}」はありません";

                continue;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                $errors[$path][] = '分類の項目は配列で指定してください（1 件の場合も配列にしてください）';

                continue;
            }

            $items = [];

            foreach ($value as $index => $item) {
                if (! is_string($item) || trim($item) === '') {
                    $errors[$path][] = ($index + 1).'番目の項目は ID かスラッグの文字列で指定してください';

                    continue;
                }

                $items[] = $item;
            }

            if (! isset($errors[$path])) {
                $map[$name] = $items;
            }
        }

        return $errors === [] ? ['map' => $map, 'errors' => []] : ['map' => null, 'errors' => $errors];
    }
}
