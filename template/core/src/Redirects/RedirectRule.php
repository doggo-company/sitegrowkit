<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 転送のルール 1 件（Phase 9.10 / STAGE 23B・SPEC §B.1）。
 *
 * **Mass Assignment から意図的に外してある**: `source_hash` / `hit_count` /
 * `last_hit_at` / `lock_version`。
 * hash は保存のたびに作り直す値で、外から渡された値を使ってよいものではない。
 * ヒット数と版も、編集の経路から書き換えられてよいものではない。
 *
 * @property int $id
 * @property string $match_type
 * @property string $source_path
 * @property string $source_hash
 * @property string $target_url
 * @property int $status_code
 * @property bool $is_enabled
 * @property bool $keep_query
 * @property string $created_reason
 * @property string|null $note
 * @property int $hit_count
 * @property Carbon|null $last_hit_at
 * @property int $lock_version
 */
final class RedirectRule extends Model
{
    /** 手で作った */
    public const REASON_MANUAL = 'manual';

    /** 404 の記録から作った */
    public const REASON_NOT_FOUND = 'not_found';

    /**
     * slug が変わったので自動で作った。
     *
     * **V1 では書かれない**（OG10）。値だけ予約しておき、あとから足すときに
     * 「手で作ったもの」と混ざらないようにする。
     */
    public const REASON_SLUG_CHANGE = 'slug_change';

    protected $table = 'redirect_rules';

    protected $fillable = [
        'match_type',
        'source_path',
        'target_url',
        'status_code',
        'is_enabled',
        'keep_query',
        'created_reason',
        'note',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'is_enabled' => 'boolean',
        'keep_query' => 'boolean',
        'hit_count' => 'integer',
        'lock_version' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    public function matchType(): RedirectMatchType
    {
        return RedirectMatchType::from($this->match_type);
    }

    public function status(): RedirectStatus
    {
        return RedirectStatus::tryFrom($this->status_code) ?? RedirectStatus::MovedPermanently;
    }

    /** @param  Builder<self>  $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }
}
