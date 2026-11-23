<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 404 の記録 1 行（Phase 9.10 / STAGE 23B・SPEC §B.6）。
 *
 * **1 URL 1 行。** アクセスごとに行を作ると bot の探索で膨れ、
 * 本当に直すべきリンク切れが埋もれる。
 * `referrer` / `user_agent` / `ip_hash` は**最後の 1 件だけ**持つ。
 *
 * `updated_at` は持たない（`last_seen_at` が同じ意味を持つので二重に持たない）。
 *
 * @property int $id
 * @property string $path
 * @property string $path_hash
 * @property string|null $last_query
 * @property string|null $last_referrer
 * @property string|null $last_user_agent
 * @property string|null $last_ip_hash
 * @property int $hit_count
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $resolved_at
 */
final class NotFoundLog extends Model
{
    protected $table = 'not_found_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'hit_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
