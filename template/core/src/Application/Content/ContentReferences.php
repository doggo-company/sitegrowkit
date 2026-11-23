<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relation の参照整合（Phase 9.2B・owner 決定 G2-b）。
 *
 * V1 は **DB の外部キーを必須にしない**。動的な Content Schema・将来の
 * Admin Content Type Builder・共有ホスティングとの整合を優先し、
 * **Application 層で参照整合を守る**。
 *
 * - 書き込み時: 参照先 Content Type の存在 → エントリの実在 を検証
 * - 削除時: 逆参照を調べ、**既定は RESTRICT**（参照中のコンテンツを勝手に消さない）
 *
 * CASCADE DELETE は V1 では行わない。
 */
final class ContentReferences
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeRegistry $types,
    ) {}

    /**
     * 参照先が実在するかを検証する。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>> 空なら妥当
     */
    public function validate(ContentSchema $schema, array $values): array
    {
        $errors = [];

        // **入れ子の中も見る**（Phase 9.5-C / ADR-0017）。
        // トップレベルだけ見ると、group / repeater の中の参照が素通りする
        foreach ($this->referenceValues($schema->fields, $values) as $found) {
            $field = $found['field'];
            $target = (string) $field->target;

            if (! $this->types->has($target) || ! $this->schemas->has($target)) {
                $errors[$found['path']][] = "参照先のコンテンツタイプ [{$target}] は存在しません";

                continue;
            }

            $missing = $this->missingIds($target, $found['ids']);

            if ($missing !== []) {
                $errors[$found['path']][] = "{$this->schemas->get($target)->label}に存在しない参照が含まれています（ID: "
                    .implode(', ', $missing).'）';
            }
        }

        return $errors;
    }

    /**
     * 入力に含まれる参照先を **(type, id, 入力上の位置)** の一覧にする（SPEC §G.5）。
     *
     * `validate()` は「在るか」を答えるだけだが、transaction の中で行を押さえるには
     * **どの型のどの ID か**が要る。拾い方は同じなので、ここで 1 回だけ書く。
     *
     * @param  array<string, mixed>  $values
     * @return list<array{type: string, id: int, path: string|null}>
     */
    public function targets(ContentSchema $schema, array $values): array
    {
        $targets = [];

        foreach ($this->referenceValues($schema->fields, $values) as $found) {
            $target = (string) $found['field']->target;

            foreach ($found['ids'] as $id) {
                $targets[] = ['type' => $target, 'id' => $id, 'path' => $found['path']];
            }
        }

        return $targets;
    }

    /**
     * 入力の中にある参照を、入れ子も含めて拾う。
     *
     * `path` は画面が error を項目へ結びつけられる形にする
     * （`spec_group.spec_ref` / `spec_rows.0.row_ref`）。
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     * @return list<array{field: Field, path: string, ids: list<int>}>
     */
    private function referenceValues(array $fields, array $values, string $prefix = ''): array
    {
        $found = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $value = $values[$field->key];
            $path = $prefix.$field->key;

            if ($field->referencesContent()) {
                $ids = $field->type === 'relation'
                    ? array_filter([$value], static fn ($v): bool => $v !== null)
                    : (is_array($value) ? $value : []);

                $found[] = [
                    'field' => $field,
                    'path' => $path,
                    'ids' => array_map(intval(...), array_values($ids)),
                ];

                continue;
            }

            if ($field->type === 'group' && is_array($value)) {
                $found = [...$found, ...$this->referenceValues($field->fields, $value, $path.'.')];

                continue;
            }

            if ($field->type === 'repeater' && is_array($value)) {
                foreach (array_values($value) as $index => $row) {
                    if (is_array($row)) {
                        $found = [...$found, ...$this->referenceValues($field->fields, $row, "{$path}.{$index}.")];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * このエントリを参照している他のコンテンツ。
     * 削除の可否判定に使う（**使用中なら消さない**）。
     *
     * @return list<array{type: string, label: string, field: string, items: list<array{id: int, title: string}>}>
     */
    public function referencesTo(string $type, int $id): array
    {
        $result = [];

        foreach ($this->types->ids() as $candidate) {
            if (! $this->schemas->has($candidate)) {
                continue;
            }

            $schema = $this->schemas->get($candidate);

            // **入れ子の中の参照も探す**（Phase 9.5-C / ADR-0017）。
            // ここが top-level だけだと、group / repeater の中から参照されている
            // エントリを削除できてしまう（RESTRICT が効かない）
            foreach ($this->referenceChains($schema->fields) as $chain) {
                $field = $chain[count($chain) - 1];

                if ($field->target !== $type) {
                    continue;
                }

                $items = count($chain) === 1 && $field->isColumnBacked()
                    ? $this->columnReferences($schema, $field, $id)
                    : $this->jsonReferences($schema, $chain, $id);

                if ($items !== []) {
                    $result[] = [
                        'type' => $schema->id,
                        'label' => $schema->label,
                        'field' => implode(' / ', array_map(static fn (Field $f): string => $f->label, $chain)),
                        'items' => $items,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function missingIds(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $found = DB::table($this->schemas->get($type)->table)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_diff($ids, $found));
    }

    /** @return list<array{id: int, title: string}> */
    private function columnReferences(ContentSchema $schema, Field $field, int $id): array
    {
        return $this->present(
            $schema,
            DB::table($schema->table)->where($field->key, $id)->limit(20)->get(),
        );
    }

    /**
     * `data` JSON の中の参照。
     *
     * `relations` はもともとここにあり、9.5-C からは
     * **入れ子（group / repeater）の中の relation / relations** も同じ道を通る。
     *
     * DB 依存の JSON 関数（`JSON_CONTAINS` 等）は使わない。
     * SQLite（テスト）と MySQL（本番・共有ホスティング）で同じ結果になることを優先し、
     * `data` を持つ行を読んで PHP 側で判定する。
     * これは MediaUsageInspector と同じ方針で、件数が問題になったら
     * 「filterable として宣言されたフィールドだけ生成列 + index」を後から足せる
     * （Phase 9.7 Listing Toolkit。先回りの最適化はしない）。
     *
     * @param  list<Field>  $chain  トップレベルから参照 field までの経路
     * @return list<array{id: int, title: string}>
     */
    private function jsonReferences(ContentSchema $schema, array $chain, int $id): array
    {
        $rows = DB::table($schema->table)
            ->whereNotNull('data')
            ->get()
            ->filter(function (\stdClass $row) use ($chain, $id): bool {
                $data = json_decode((string) $row->data, true);

                return in_array($id, $this->idsAtChain(is_array($data) ? $data : [], $chain), true);
            })
            ->take(20)
            ->values();

        return $this->present($schema, $rows);
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{id: int, title: string}>
     */
    private function present(ContentSchema $schema, Collection $rows): array
    {
        $titleKey = $schema->titleKey();

        /** @var list<array{id: int, title: string}> $items */
        $items = $rows->map(static fn (\stdClass $row): array => [
            'id' => (int) $row->id,
            'title' => (string) ($titleKey !== null && isset($row->{$titleKey}) ? $row->{$titleKey} : '#'.$row->id),
        ])->values()->all();

        return $items;
    }

    /**
     * 参照 field までの経路を列挙する（入れ子を含む）。
     *
     * @param  list<Field>  $fields
     * @param  list<Field>  $prefix
     * @return list<list<Field>>
     */
    private function referenceChains(array $fields, array $prefix = []): array
    {
        $chains = [];

        foreach ($fields as $field) {
            $chain = [...$prefix, $field];

            if ($field->referencesContent()) {
                $chains[] = $chain;

                continue;
            }

            if ($field->fields !== []) {
                $chains = [...$chains, ...$this->referenceChains($field->fields, $chain)];
            }
        }

        return $chains;
    }

    /**
     * 経路をたどって参照 ID を集める。
     *
     * `repeater` は行の list なので、その段で**すべての行へ枝分かれ**する。
     *
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  list<Field>  $chain
     * @return list<int>
     */
    private function idsAtChain(array $data, array $chain): array
    {
        $containers = [$data];
        $last = count($chain) - 1;

        foreach ($chain as $index => $field) {
            $next = [];

            foreach ($containers as $container) {
                if (! is_array($container) || ! array_key_exists($field->key, $container)) {
                    continue;
                }

                $value = $container[$field->key];

                if ($index === $last) {
                    foreach (is_array($value) ? $value : [$value] as $id) {
                        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                            $next[] = (int) $id;
                        }
                    }

                    continue;
                }

                if ($field->type === 'repeater' && is_array($value)) {
                    foreach ($value as $row) {
                        $next[] = $row;
                    }

                    continue;
                }

                $next[] = $value;
            }

            $containers = $next;
        }

        /** @var list<int> $containers */
        return array_values(array_unique($containers));
    }
}
