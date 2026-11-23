<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * 参照元を 1 種類調べる部品（Phase 9.3B / STAGE 2・SPEC §I.3 の拡張点）。
 *
 * **menu / listing / 再利用ブロック等が増えたら provider を登録するだけ**で
 * RESTRICT へ反映される（R-5 / R-6）。判定側（`ReferenceInspector`）は書き換えない。
 *
 * 実装は **read-only** であること。DB を書き換えてはならない。
 */
interface ReferenceProvider
{
    /** 機械可読な種別（`assignment` / `page_document` …） */
    public function kind(): string;

    /**
     * この term を参照しているものを数える。
     *
     * 確定できなかった場合は `ReferenceProbe::undetermined()` を返す。
     * **「0 件」と「確定できなかった」を混同しない。**
     */
    public function forTerm(TaxonomyTerm $term): ReferenceProbe;

    /**
     * この taxonomy を参照しているものを数える。
     * 配下 term 経由の参照を含めてよい。
     */
    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe;
}
