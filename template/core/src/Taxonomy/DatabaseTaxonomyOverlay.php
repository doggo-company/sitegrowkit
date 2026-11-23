<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use AgentCms\Core\Schema\SchemaTaxonomyOverlay;
use Illuminate\Database\Connection;
use Throwable;

/**
 * 管理画面で付けた分類の関連付けを schema へ重ねる（Phase 9.7 / STAGE 20D・SPEC §H.3）。
 *
 * **1 リクエストに 1 回だけ読む。** 投稿タイプの数だけ query を投げない。
 *
 * **無効にした分類は重ねない。** 関連付けの行は残したまま、編集画面と保存の対象から外す
 * — 「無効にしたのに新しく付けられる」を作らないためである。
 * 既に付いている `taxonomy_term_assignments` は 1 件も消えない。
 */
final class DatabaseTaxonomyOverlay implements SchemaTaxonomyOverlay
{
    public const TABLE = 'content_type_taxonomies';

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $rows = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function taxonomiesFor(string $schemaId): array
    {
        return $this->load()[$schemaId] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function load(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $builder = $this->connection->getSchemaBuilder();

            if (! $builder->hasTable(self::TABLE) || ! $builder->hasColumn('taxonomies', 'status')) {
                return $this->rows = [];
            }

            $rows = [];

            $records = $this->connection->table(self::TABLE)
                ->join('taxonomies', 'taxonomies.id', '=', self::TABLE.'.taxonomy_id')
                ->where('taxonomies.status', 'active')
                ->orderBy(self::TABLE.'.sort_order')
                ->orderBy('taxonomies.key')
                ->get([self::TABLE.'.content_type', 'taxonomies.key', self::TABLE.'.multiple', self::TABLE.'.required']);

            foreach ($records as $record) {
                $contentType = (string) $record->content_type;
                $key = (string) $record->key;

                if (! TaxonomyKey::isValid($key)) {
                    continue;
                }

                $rows[$contentType][] = [
                    'key' => $key,
                    'multiple' => (bool) $record->multiple,
                    'required' => (bool) $record->required,
                ];
            }

            return $this->rows = $rows;
        } catch (Throwable) {
            // **重ねられなかったときは何も足さない。** ファイル宣言だけで動く状態へ倒す
            // （落とすと、分類を持たない投稿タイプまで開けなくなる）
            return $this->rows = [];
        }
    }
}
