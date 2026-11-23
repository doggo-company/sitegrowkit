<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use Illuminate\Support\Str;

/**
 * 検証済みの値を「カラム」と「data JSON」へ分ける（Phase 9.2B・owner 決定 G2）。
 *
 * ここが担うのは 3 つだけ:
 * 1. **同じ値を両方へ二重保存しない**（Single Source of Truth）
 * 2. richtext は **書き込み境界でサニタイズ**する（owner 決定 G5）。
 *    Admin / API / MCP / Import のどこから来ても同じ sanitizer を通る
 * 3. repeater の行に **immutable な `_id`（ULID）**を採番する。
 *    並べ替えても内容を変えても変わらないので、AI は 1 行だけ安全に更新できる
 */
final class ContentValues
{
    public function __construct(private readonly Purifier $purifier) {}

    /**
     * 値を「列へ入れるもの」と「data JSON へ入れるもの」へ分ける。
     *
     * **検証済みでなくてよい。ここは自分で守る。**
     * 自動保存（Phase 9.15 / STAGE 28B）は**書きかけの値**をそのまま渡してくる —
     * 検証して弾いてしまうと、**書きかけほど守られない**ことになるからである。
     *
     * だから `normalize()` は型を仮定しない。想定と違う形が来たら
     * null や空 list へ倒し、**richtext は必ず Purifier を通す**。
     * この主張は `ContentValuesUnvalidatedTest` が固定している。
     *
     * ▷ **「検証済みだから」を理由に、ここの守りを外さないこと。**
     *
     * @param  array<string, mixed>  $values  **検証されていないことがある**
     * @param  array<string, mixed>  $existingData  更新時の既存 data JSON
     * @return array{columns: array<string, mixed>, data: array<string, mixed>|null}
     */
    public function split(ContentSchema $schema, array $values, array $existingData = []): array
    {
        $columns = [];
        $data = $existingData;

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $value = $this->normalize($field, $values[$field->key], $existingData[$field->key] ?? null);

            if ($field->isColumnBacked()) {
                $columns[$field->key] = $value;

                continue;
            }

            $data[$field->key] = $value;
        }

        return [
            'columns' => $columns,
            'data' => $schema->needsDataColumn() ? $data : null,
        ];
    }

    /** 型ごとの正規化（サニタイズ・ID 採番・入れ子の再帰） */
    private function normalize(Field $field, mixed $value, mixed $existing): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            'richtext' => is_string($value) ? $this->purifier->clean($value) : null,
            'group' => is_array($value) ? $this->normalizeGroup($field, $value, is_array($existing) ? $existing : []) : null,
            'repeater' => is_array($value)
                ? $this->normalizeRepeater($field, array_values($value), is_array($existing) ? array_values($existing) : [])
                : [],
            'images', 'relations' => is_array($value) ? array_values(array_map(intval(...), $value)) : [],
            'checkbox' => is_array($value) ? array_values(array_map(strval(...), $value)) : [],
            'number' => is_numeric($value) ? $value + 0 : null,
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeGroup(Field $field, array $value, array $existing): array
    {
        $out = [];

        foreach ($field->fields as $child) {
            if (array_key_exists($child->key, $value)) {
                $out[$child->key] = $this->normalize($child, $value[$child->key], $existing[$child->key] ?? null);
            } elseif (array_key_exists($child->key, $existing)) {
                $out[$child->key] = $existing[$child->key];
            }
        }

        return $out;
    }

    /**
     * 行の `_id` は **既存行のものを維持し、新しい行にだけ採番する**。
     * 送られてきた `_id` が既存に無ければ、新規行として採番し直す（識別子の偽装を防ぐ）。
     *
     * @param  list<mixed>  $value
     * @param  list<mixed>  $existing
     * @return list<array<string, mixed>>
     */
    private function normalizeRepeater(Field $field, array $value, array $existing): array
    {
        $known = [];

        foreach ($existing as $row) {
            if (is_array($row) && is_string($row['_id'] ?? null)) {
                $known[$row['_id']] = $row;
            }
        }

        $out = [];
        $used = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['_id'] ?? null;
            $isKnown = is_string($id) && isset($known[$id]) && ! isset($used[$id]);
            $id = $isKnown ? (string) $id : (string) Str::ulid();
            $used[$id] = true;
            $previous = $isKnown ? $known[$id] : [];

            $item = ['_id' => $id];

            foreach ($field->fields as $child) {
                if (array_key_exists($child->key, $row)) {
                    $item[$child->key] = $this->normalize($child, $row[$child->key], $previous[$child->key] ?? null);
                } elseif (array_key_exists($child->key, $previous)) {
                    $item[$child->key] = $previous[$child->key];
                }
            }

            $out[] = $item;
        }

        return $out;
    }
}
