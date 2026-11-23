<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Taxonomy（Phase 9.3B / STAGE 1・SPEC §B / §C）。
 *
 * identity は immutable ULID。`key` は machine key であり **rename しない**（§C.2）。
 *
 * **Mass Assignment から意図的に外してある不変値**: `id` / `key` / `managed_by`。
 * 外部入力でこれらを書き換えさせない。作成は taxonomy-sync（code 管理）か、
 * 将来の CMS 管理操作（Phase 9.4）だけが行う。
 *
 * Model event は identity の採番だけを行い、暗黙の sync / cascade cleanup はしない。
 *
 * @property string $id
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property bool $hierarchical
 * @property string $status
 * @property int $lock_version
 * @property TaxonomyManagedBy $managed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Taxonomy extends Model
{
    use HasTaxonomyId;

    protected $table = 'taxonomies';

    protected $fillable = [
        'label',
        'description',
        'hierarchical',
    ];

    protected $casts = [
        'hierarchical' => 'boolean',
        'managed_by' => TaxonomyManagedBy::class,
    ];

    /** @return HasMany<TaxonomyTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(TaxonomyTerm::class, 'taxonomy_id');
    }

    public function isCodeManaged(): bool
    {
        return $this->managed_by === TaxonomyManagedBy::Code;
    }

    /**
     * 無効にされているか（Phase 9.7 / STAGE 20D）。
     *
     * **未知の値は無効として読む**（fail-closed）。投稿タイプの `status` と同じ作法で、
     * 意味の分からない状態の分類へ新しく項目を付けさせない。
     */
    public function isArchived(): bool
    {
        return $this->getAttribute('status') !== 'active';
    }
}
