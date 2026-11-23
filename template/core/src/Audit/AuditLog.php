<?php

declare(strict_types=1);

namespace AgentCms\Core\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * 監査ログの読み取りモデル（PHASE WP-BASELINE / STAGE #18A）。
 *
 * `audit_logs` は **追記専用**の履歴である。
 * このモデルからは更新も削除もできない（保持期間の整理だけが
 * `agentcms:audit-prune` から行う）。書き込みは従来どおり
 * `DatabaseAuditRecorder` だけが行う。
 *
 * @property int $id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $actor_label
 * @property string $interface
 * @property string $operation
 * @property string $classification
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property string $result
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $anonymized_at
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /** 履歴なので更新日時は持たない（created_at だけ） */
    public $timestamps = false;

    /** 追記専用。アプリケーションからは書き換えない */
    protected $guarded = ['*'];

    protected $casts = [
        'metadata' => 'array',
        'anonymized_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        throw new RuntimeException('監査ログは追記専用です（DatabaseAuditRecorder 以外から書き込めません）');
    }

    public function delete(): bool
    {
        throw new RuntimeException('監査ログは削除できません（保持期間の整理は agentcms:audit-prune が行います）');
    }
}
