<?php

declare(strict_types=1);

namespace App\Listeners;

use AgentCms\Core\Pages\Events\PageDuplicated;
use App\Models\ImportedPageLayout;

/**
 * ページの複製に、取り込みの枠（layout）の結び付きも写す。
 *
 * 複製は「見た目も内容も元と一致する」もの。元が取り込みの原本の枠に載っているなら、
 * 複製も同じ枠に載らなければ、複製した瞬間に kit 既定の枠へ落ちて意匠が別物になる。
 *
 * `DuplicatePage` の取引の内側で同期に呼ばれる（`PageDuplicated` の宣言）。
 * ここが失敗すれば複製ごと巻き戻る — 枠だけ欠けた複製は作らない。
 * 元に結び付きが無ければ何もしない（管理画面で作ったページの複製は kit 既定のまま）。
 *
 * 登録は Laravel の event discovery（`app/Listeners` の `handle()` の型）に任せる。
 * `AppServiceProvider` にも書くと**二重に登録され、2 行目の insert が unique 制約で落ちる**（実測）。
 */
final class CopyImportedPageLayout
{
    public function handle(PageDuplicated $event): void
    {
        $bound = ImportedPageLayout::query()->where('page_id', $event->source->id)->first();

        if ($bound === null) {
            return;
        }

        // 枠だけを引き継ぐ。**元の URL の所有権（owned_view）は写さない** — 複製が元の URL を奪わない
        ImportedPageLayout::query()->create([
            'page_id' => $event->copy->id,
            'view' => $bound->view,
            'source' => $bound->source,
            'owned_view' => null,
        ]);
    }
}
