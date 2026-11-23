<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 管理画面が作った分類の書き込み（Phase 9.7 / STAGE 20D・SPEC §H）。
 *
 * **後勝ちにしない。** 更新は `lock_version` の CAS で行い、期待した版と違えば
 * 0 行更新になる。呼び出し側はそれを競合として扱う。
 *
 * `key` と `managed_by` はここでも変えられない（作成時にだけ書く）。
 */
final class TaxonomyDefinitionWriter
{
    public const TABLE = 'taxonomies';

    public const ASSOCIATIONS = 'content_type_taxonomies';

    public function __construct(private readonly Connection $connection) {}

    /** 作成。**key が重なっていれば false**（code 管理の定義とも重ならない） */
    public function create(string $key, string $label, ?string $description, bool $hierarchical): ?string
    {
        $id = TaxonomyId::generate();

        try {
            $this->connection->table(self::TABLE)->insert([
                'id' => $id,
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'hierarchical' => $hierarchical,
                'status' => 'active',
                'managed_by' => TaxonomyManagedBy::Cms->value,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $id;
    }

    /**
     * 更新。**適用できたら true、競合なら false（何も変えない）**。
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(string $id, array $changes, int $expectedLockVersion): bool
    {
        if ($changes === []) {
            return true;
        }

        return $this->connection->table(self::TABLE)
            ->where('id', $id)
            ->where('lock_version', $expectedLockVersion)
            ->update([...$changes, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => now()]) === 1;
    }

    /** 定義を消す。**呼ぶ前に厳しい条件を確かめること**（このメソッドは確かめない） */
    public function delete(string $id, int $expectedLockVersion): bool
    {
        return $this->connection->table(self::TABLE)
            ->where('id', $id)
            ->where('lock_version', $expectedLockVersion)
            ->delete() === 1;
    }

    /** 投稿タイプへ関連付ける。既にあれば false（二重に足さない） */
    public function attach(string $contentType, string $taxonomyId, bool $multiple, bool $required): bool
    {
        try {
            $this->connection->table(self::ASSOCIATIONS)->insert([
                'content_type' => $contentType,
                'taxonomy_id' => $taxonomyId,
                'multiple' => $multiple,
                'required' => $required,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * 関連付けを外す。
     *
     * **`taxonomy_term_assignments` には一切触れない。** 外すのは「この型の編集画面に
     * この分類を出す」という設定だけで、既に付いている分類は残る。
     * 付け直せば、そのまま元どおり見える。
     */
    public function detach(string $contentType, string $taxonomyId): bool
    {
        return $this->connection->table(self::ASSOCIATIONS)
            ->where('content_type', $contentType)
            ->where('taxonomy_id', $taxonomyId)
            ->delete() === 1;
    }

    public function lockVersionOf(string $id): int
    {
        $value = $this->connection->table(self::TABLE)->where('id', $id)->value('lock_version');

        return is_numeric($value) ? (int) $value : 0;
    }

    /** この分類が関連付けられている投稿タイプ */
    public function associationCount(string $taxonomyId): int
    {
        return $this->connection->table(self::ASSOCIATIONS)->where('taxonomy_id', $taxonomyId)->count();
    }
}
