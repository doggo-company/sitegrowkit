<?php

declare(strict_types=1);

namespace Modules\News\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;

final class News extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'news';

    public static function schemaId(): string
    {
        return 'news';
    }
}
