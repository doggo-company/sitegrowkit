<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages\Events;

use AgentCms\Core\Pages\Page;

/**
 * ページが複製された（`DuplicatePage` の取引の中で発火する）。
 *
 * ## なぜ event なのか
 *
 * 複製は「見た目も内容も元と一致する」ものでなければならない。ところが、ページに
 * 付随する情報の一部は **project 層**が持つ（例: 取り込みが原本の枠を結び付ける
 * `imported_page_layouts`）。core が project の表を知ると依存の向きが逆になる
 * （`rules/boundaries.md`）。だから core は「複製した」とだけ言い、付随情報を写すのは
 * project 側の listener の仕事にする。
 *
 * ## 取引の中で発火する
 *
 * listener は `DuplicatePage` の `DB::transaction` の**内側**で呼ばれる。listener が例外を
 * 投げれば複製ごと巻き戻る — 付随情報だけ欠けた複製を作らない。
 * listener は同期で動くこと（queue に流すと取引の外へ出る）。
 */
final class PageDuplicated
{
    public function __construct(
        public readonly Page $source,
        public readonly Page $copy,
    ) {}
}
