<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Support\Facades\DB;

/**
 * 復元の計画を立てる（Phase 9.8 / STAGE 21D CORRECTION・SPEC §F.2.2・§F.5）。
 *
 * **preview のときも apply のときも、同じここを通る。** 見せた内容と起きることが
 * ずれないのは、2 回とも同じ関数で計算しているからである。
 *
 * 判定に使うのは **版の時点の定義**（保存済み manifest）と **いまの定義**の 2 つで、
 * 型名や field 名を書き並べることはしない。
 */
final class RevisionRestorePlanner
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * @param  list<array<string, mixed>>  $manifest  版の時点の定義
     * @param  array<string, mixed>  $snapshot
     */
    public function plan(ContentSchema $schema, array $manifest, array $snapshot): RevisionRestorePlan
    {
        $current = SchemaManifest::canonical($schema);

        $historicalByIdentity = self::byIdentity($manifest);
        $currentByIdentity = self::byIdentity($current);

        $restorable = [];
        $lost = [];
        $compatible = [];

        foreach ($historicalByIdentity as $identity => $field) {
            $now = $currentByIdentity[$identity] ?? null;

            if ($now === null) {
                /*
                 * その項目は schema から消えている。`identity` は `id ?? key` なので、
                 * **ID を持たない型で key を変えた**場合もここへ来る（別項目として扱う）。
                 * 実測どおりの制約なので隠さず出す。
                 */
                $lost[] = ['key' => (string) $field['key'], 'reason' => '項目が削除されています'];

                continue;
            }

            $reason = FieldCompatibility::reason($field, $now);

            if ($reason !== null) {
                $lost[] = ['key' => (string) $now['key'], 'reason' => $reason];

                continue;
            }

            $restorable[] = (string) $now['key'];
            // 参照の検査は **戻す項目だけ**を対象にする（戻さない値は書かないので見なくてよい）
            $compatible[(string) $now['key']] = $field;
        }

        $added = [];

        foreach ($currentByIdentity as $identity => $field) {
            if (! isset($historicalByIdentity[$identity])) {
                // 版に無い項目。**現在の値をそのまま残す**（空で上書きしない）
                $added[] = (string) $field['key'];
            }
        }

        sort($restorable);
        sort($added);

        return new RevisionRestorePlan(
            restorable: $restorable,
            lost: $lost,
            added: $added,
            droppedTerms: $this->droppedTerms($snapshot),
            missing: $this->missingReferences($compatible, $snapshot),
        );
    }

    /**
     * 版に在ったが**もう存在しない**分類項目。
     *
     * 落とすこと自体は正本どおりだが、**黙っては落とさない**（計画に載せる）。
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{taxonomy: string, term: string}>
     */
    private function droppedTerms(array $snapshot): array
    {
        $taxonomies = is_array($snapshot['taxonomies'] ?? null) ? $snapshot['taxonomies'] : [];

        if ($taxonomies === []) {
            return [];
        }

        $wanted = [];

        foreach ($taxonomies as $key => $terms) {
            foreach (is_array($terms) ? $terms : [] as $term) {
                if (is_scalar($term)) {
                    $wanted[] = ['taxonomy' => (string) $key, 'term' => (string) $term];
                }
            }
        }

        if ($wanted === []) {
            return [];
        }

        $alive = array_map(
            strval(...),
            DB::table('taxonomy_terms')
                ->whereIn('id', array_column($wanted, 'term'))
                ->pluck('id')
                ->all(),
        );

        return array_values(array_filter(
            $wanted,
            static fn (array $entry): bool => ! in_array($entry['term'], $alive, true),
        ));
    }

    /**
     * 参照先や media が消えていないか（§F.5）。
     *
     * **入れ子の中まで見る。** group / repeater の子に置かれた参照を見落とすと、
     * 「戻したら中の参照だけ壊れていた」が起きる。
     * ゴミ箱にあるものは通す（データは失われていない）。消えているものだけ止める。
     *
     * @param  array<string, array<string, mixed>>  $compatible  戻す field（キー → 版の定義）
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function missingReferences(array $compatible, array $snapshot): array
    {
        $values = array_merge(
            is_array($snapshot['columns'] ?? null) ? $snapshot['columns'] : [],
            is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [],
        );

        $wanted = [];

        foreach ($compatible as $key => $field) {
            $this->collectReferences($field, $values[$key] ?? null, (string) $key, $wanted);
        }

        $missing = [];

        foreach ($wanted as $table => $paths) {
            $ids = array_values(array_unique(array_map(intval(...), array_keys($paths))));

            $found = array_map(intval(...), DB::table($table)->whereIn('id', $ids)->pluck('id')->all());

            foreach (array_diff($ids, $found) as $gone) {
                $missing[] = $paths[$gone].': ID '.$gone;
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * 1 つの field（と入れ子）から参照 ID を集める。
     *
     * 値の形は当てにしない。`null`・空配列・行が揃っていない repeater・
     * 数値でない値が混ざっていても、**警告も型エラーも出さずに読み飛ばす**。
     *
     * @param  array<string, mixed>  $field  版の時点の定義
     * @param  array<string, array<int, string>>  $wanted  表 → (ID → 入力上の位置)
     *
     * @phpstan-param array<string, mixed> $field
     */
    private function collectReferences(array $field, mixed $value, string $path, array &$wanted): void
    {
        $children = is_array($field['children'] ?? null) ? $field['children'] : [];

        if ($children !== []) {
            // group は連想配列 1 つ、repeater は行の list。どちらも同じ規則で降りる
            $rows = is_array($value) && array_is_list($value) ? $value : [$value];

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($children as $child) {
                    if (! isset($child['key'])) {
                        continue;
                    }

                    $childKey = (string) $child['key'];
                    $childPath = is_array($value) && array_is_list($value)
                        ? "{$path}.{$index}.{$childKey}"
                        : "{$path}.{$childKey}";

                    $this->collectReferences($child, $row[$childKey] ?? null, $childPath, $wanted);
                }
            }

            return;
        }

        $table = match ($field['type'] ?? null) {
            'relation', 'relations' => $this->tableFor((string) ($field['target'] ?? '')),
            'media', 'image', 'images', 'file' => 'media',
            default => null,
        };

        if ($table === null || $value === null) {
            return;
        }

        foreach (is_array($value) ? $value : [$value] as $raw) {
            if (! is_numeric($raw)) {
                continue;
            }

            $id = (int) $raw;

            if ($id > 0) {
                $wanted[$table][$id] = $path;
            }
        }
    }

    private function tableFor(string $type): ?string
    {
        return $type !== '' && $this->schemas->has($type) ? $this->schemas->get($type)->table : null;
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private static function byIdentity(array $manifest): array
    {
        $indexed = [];

        foreach ($manifest as $field) {
            // identity が無い行は「どの項目か分からない」ので対応づけに使わない
            if (isset($field['identity'])) {
                $indexed[(string) $field['identity']] = $field;
            }
        }

        return $indexed;
    }
}
