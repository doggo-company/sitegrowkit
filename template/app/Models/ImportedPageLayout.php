<?php

declare(strict_types=1);

namespace App\Models;

use AgentCms\Core\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 取り込んだページ 1 枚と、その原本の枠（layout）の結び付き（R03 / B-42）。
 *
 * 行があるページだけが `view` の layout に載る（`DocumentPageView::layoutFor()`）。
 * `owned_view`（= view）を持つ行は **元の URL の所有者**（取り込みが作った 1 枚）。複製は null。
 * 書くのは `agentcms:import-page` だけで、ページの作成と同じ取引で入る。
 * 綴りの規則は `DocumentPageView::PRESERVED_LAYOUT_PREFIX` に従う。
 */
final class ImportedPageLayout extends Model
{
    protected $table = 'imported_page_layouts';

    protected $fillable = ['page_id', 'view', 'source', 'owned_view'];

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
