<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * R-2: 子 term（Phase 9.3B / STAGE 2・SPEC §I.1）。
 * taxonomy 側では T-1（配下 term が 1 件でもある）を担う。
 *
 * **archived な子も数える**（T-1 の「archived term も含む」・AR-8）。
 * 親を消して子が黙って root へ昇格することを防ぐ（§H.1 の FK RESTRICT と一致）。
 */
final class ChildTermReferenceProvider implements ReferenceProvider
{
    private const SAMPLE_LIMIT = 5;

    public function kind(): string
    {
        return 'child_term';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        $query = TaxonomyTerm::query()->where('parent_id', $term->id);
        $count = (clone $query)->count();

        if ($count === 0) {
            return ReferenceProbe::none();
        }

        /** @var list<string> $samples */
        $samples = $query->orderBy('sort_order')
            ->limit(self::SAMPLE_LIMIT)
            ->pluck('name')
            ->all();

        return ReferenceProbe::found($count, $samples);
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        $query = TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id);
        $count = (clone $query)->count();

        if ($count === 0) {
            return ReferenceProbe::none();
        }

        /** @var list<string> $samples */
        $samples = $query->orderBy('sort_order')
            ->limit(self::SAMPLE_LIMIT)
            ->pluck('name')
            ->all();

        return ReferenceProbe::found($count, $samples);
    }
}
