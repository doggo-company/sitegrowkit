<?php

declare(strict_types=1);

namespace Modules\Media\Models;

use AgentCms\Core\Media\VariantFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 原本から作った派生物（Phase 9.5-D / ADR-0018）。
 *
 * **行が在れば ready、無ければ未生成**である。状態を列で持たない。
 * 「失敗した」と書いてしまうと、環境を直したあとに再生成できなくなる。
 *
 * 原本は必ず残るので、この行が 1 つも無くても配信は成立する。
 *
 * @property int $id
 * @property int $media_id
 * @property string $format
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 */
final class MediaVariant extends Model
{
    /** created_at だけを持つ（作り直しは delete → create で行うので更新時刻に意味が無い） */
    public const UPDATED_AT = null;

    protected $table = 'media_variants';

    protected $fillable = ['media_id', 'format', 'disk', 'path', 'mime', 'size', 'width', 'height'];

    protected $casts = [
        'media_id' => 'integer',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function format(): ?VariantFormat
    {
        return VariantFormat::tryFrom($this->format);
    }

    /** URL は disk + path から**作る**。導出できる値を列で持たない */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
