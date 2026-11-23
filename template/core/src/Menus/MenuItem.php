<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * メニューの 1 項目（Phase 9.9 / STAGE 22B・SPEC §B.2）。
 *
 * **URL は持たない。** 持てるのは `link_type = custom` の `link_url` だけで、
 * 他は参照（route 名 / page_id / content_type + content_id）だけを持ち、
 * URL は読むときに作る（SPEC §B.3・OG2）。
 *
 * @property int $id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property int $sort_order
 * @property string|null $title
 * @property string $link_type
 * @property string|null $link_url
 * @property string|null $link_route
 * @property int|null $page_id
 * @property string|null $content_type
 * @property int|null $content_id
 * @property string|null $taxonomy_term_id
 * @property string $target
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MenuItem extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'parent_id',
        'sort_order',
        'title',
        'link_type',
        'link_url',
        'link_route',
        'page_id',
        'content_type',
        'content_id',
        'taxonomy_term_id',
        'target',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'page_id' => 'integer',
        'content_id' => 'integer',
    ];

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** @return HasMany<MenuItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function linkType(): MenuLinkType
    {
        return MenuLinkType::from($this->link_type);
    }

    public function targetKind(): MenuTarget
    {
        return MenuTarget::tryFrom($this->target) ?? MenuTarget::Self;
    }
}
