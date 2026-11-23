<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Term ⇄ Content Entry の割り当て（Phase 9.3B / STAGE 1・SPEC §G）。
 *
 * `content_type` に入れてよいのは **`ContentTypeResolver::knows()` が true の type だけ**
 * （§G.3 CT-1〜CT-9）。判定は Application Service 側の責務であり、
 * **STAGE 1 では書き込み経路そのものを公開しない**。
 *
 * `taxonomy_id` は term から導出できるため**非正規化して持たない**（§G.6 の注記）。
 * `updated_at` を持たない（作成と削除しかない行のため）。
 *
 * @property int $id
 * @property string $term_id
 * @property string $content_type
 * @property int $content_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 */
final class TaxonomyTermAssignment extends Model
{
    protected $table = 'taxonomy_term_assignments';

    /** created_at のみ（マイグレーションに updated_at が無い） */
    public const UPDATED_AT = null;

    protected $fillable = [
        'term_id',
        'content_type',
        'content_id',
        'sort_order',
    ];

    protected $casts = [
        'content_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<TaxonomyTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }
}
