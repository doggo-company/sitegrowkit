<?php

declare(strict_types=1);

namespace Modules\Works\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Media\Models\Media;

final class Work extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'works';

    public static function schemaId(): string
    {
        return 'works';
    }

    /** @return BelongsTo<Media, $this> */
    public function mainImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'main_image_id');
    }
}
