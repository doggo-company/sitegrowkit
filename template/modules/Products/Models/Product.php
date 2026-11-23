<?php

declare(strict_types=1);

namespace Modules\Products\Models;

use AgentCms\Core\Content\HasContentSchema;
use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品（Phase 9.2B の Custom Fields 実証用）。
 *
 * 複合フィールドは `data` JSON に入る。モデル側に個別の accessor は書かない
 * （schema から導出される — Schema First）。
 */
final class Product extends Model implements SchemaModel
{
    use HasContentSchema;

    protected $table = 'products';

    public static function schemaId(): string
    {
        return 'product';
    }
}
