<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;

/**
 * R-1: Content assignment（Phase 9.3B / STAGE 2・SPEC §I.1）。
 *
 * **archived かどうかは判定に影響しない**（AR-8）。
 * archive は「一覧から外す」だけで、参照を消す操作ではない。
 */
final class AssignmentReferenceProvider implements ReferenceProvider
{
    /** samples に載せる最大件数 */
    private const SAMPLE_LIMIT = 5;

    public function kind(): string
    {
        return 'assignment';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        $count = TaxonomyTermAssignment::query()
            ->where('term_id', $term->id)
            ->count();

        if ($count === 0) {
            return ReferenceProbe::none();
        }

        /** @var list<array{content_type: string, content_id: int}> $rows */
        $rows = TaxonomyTermAssignment::query()
            ->where('term_id', $term->id)
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['content_type', 'content_id'])
            ->map(static fn (TaxonomyTermAssignment $a): array => [
                'content_type' => $a->content_type,
                'content_id' => $a->content_id,
            ])
            ->all();

        return ReferenceProbe::found($count, array_map(
            static fn (array $r): string => "{$r['content_type']}#{$r['content_id']}",
            $rows,
        ));
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        $count = TaxonomyTermAssignment::query()
            ->whereIn(
                'term_id',
                TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->select('id'),
            )
            ->count();

        return $count === 0 ? ReferenceProbe::none() : ReferenceProbe::found($count);
    }
}
