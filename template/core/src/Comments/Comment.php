<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * コメント 1 件（Phase 9.13 / STAGE 26C・SPEC §B.1）。
 *
 * **`author_email` は公開面へ 1 度も出さない。** presenter が落とす（§G.1）。
 * ここで `$hidden` に頼らないのは、`toArray()` を通らない経路
 * （Blade へ model を直接渡す等）では効かないためである。
 * 「出さない」は、出す形を作る側で保証する。
 *
 * @property int $id
 * @property string $content_type
 * @property int $content_id
 * @property int|null $parent_id
 * @property string $status
 * @property string $author_name
 * @property string|null $author_email
 * @property string|null $author_url
 * @property string $body
 * @property bool $is_staff
 * @property int|null $author_user_id
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Comment extends Model
{
    protected $table = 'content_comments';

    protected $guarded = ['id'];

    protected $casts = [
        'is_staff' => 'boolean',
        'approved_at' => 'datetime',
    ];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function status(): CommentStatus
    {
        return CommentStatus::tryFrom($this->status) ?? CommentStatus::Pending;
    }
}
