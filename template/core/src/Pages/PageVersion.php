<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PageDocument の 1 版（Phase 9.1D・owner 決定 G17 / G23）。
 *
 * draft も published も同じテーブルの別レコード。
 * `revision` は楽観ロック（expectedVersion）の基準になる。
 *
 * @property int $id
 * @property int $page_id
 * @property int $revision
 * @property int $schema_version
 * @property array<string, mixed> $document
 * @property string $created_interface
 * @property int|null $created_by
 * @property bool $autosave 自動保存で増えた版か（間引きの対象になるのはこちらだけ — §4.11）
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PageVersion extends Model
{
    protected $table = 'page_versions';

    protected $fillable = [
        'page_id', 'revision', 'schema_version', 'document',
        'created_interface', 'created_by', 'autosave',
    ];

    protected $casts = [
        'document' => 'array',
        'revision' => 'integer',
        'schema_version' => 'integer',
        'autosave' => 'boolean',
    ];

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
