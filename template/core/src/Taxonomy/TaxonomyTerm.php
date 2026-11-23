<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Term（Phase 9.3B / STAGE 1・SPEC §B / §C / §I.5）。
 *
 * identity は immutable ULID。`slug` / `name` は rename 可能で **identity ではない**。
 * `archived_at` は null = active / 値あり = archived（§I.5・G3-e）。
 *
 * **Mass Assignment から意図的に外してある不変値**: `id` / `taxonomy_id`。
 * term を別 taxonomy へ移す操作は存在しない（§D.3 D-2）。
 *
 * STAGE 1 では **作成・更新・削除・archive の Application Service を公開しない**。
 * 本クラスは永続化の形を固定するためだけに存在する。
 *
 * @property string $id
 * @property string $taxonomy_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $parent_id
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TaxonomyTerm extends Model
{
    use HasTaxonomyId;

    protected $table = 'taxonomy_terms';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'sort_order',
        'archived_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsTo<Taxonomy, $this> */
    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    /** @return BelongsTo<TaxonomyTerm, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<TaxonomyTerm, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<TaxonomyTermAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaxonomyTermAssignment::class, 'term_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * 既定の一覧は active のみ（AR-1）。
     *
     * @param  Builder<TaxonomyTerm>  $query
     * @return Builder<TaxonomyTerm>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
