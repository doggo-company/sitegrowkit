<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TermSlug;

/**
 * Admin / API / MCP へ返す形（Phase 9.3B / STAGE 2B）。
 *
 * **adapter ごとに別の形を作らない**ため 1 箇所へ集約する。
 * AI / UI 向けには `id` と `key` / `slug` / `name` を **併記**する（§J.3）。
 */
final class TaxonomyPresenter
{
    /**
     * @param  array{total: int, active: int, archived: int}  $counts
     * @return array<string, mixed>
     */
    public static function taxonomy(Taxonomy $taxonomy, array $counts): array
    {
        return [
            'id' => $taxonomy->id,
            'key' => $taxonomy->key,
            'label' => $taxonomy->label,
            'description' => $taxonomy->getAttribute('description'),
            'hierarchical' => $taxonomy->hierarchical,
            'managedBy' => $taxonomy->managed_by->value,
            // 運用状態（Phase 9.7 / STAGE 20D）。**項目の archived とは別の軸**である
            'status' => $taxonomy->isArchived() ? 'archived' : 'active',
            'lockVersion' => (int) ($taxonomy->getAttribute('lock_version') ?? 0),
            'termCount' => $counts['total'],
            'activeTermCount' => $counts['active'],
            'archivedTermCount' => $counts['archived'],
        ];
    }

    /**
     * term の表示形。
     *
     * **`parentId` を null へ潰さない**（HR-6）。親が archived で一覧から外れていても、
     * 子は自分の親を持ち続ける。呼び出し側が孤児 root として描画しないための材料。
     *
     * `name` / `slug` は archive 後も維持する（AR-6）。
     *
     * @return array<string, mixed>
     */
    public static function term(TaxonomyTerm $term, Taxonomy $taxonomy): array
    {
        return [
            'id' => $term->id,
            'taxonomyId' => $taxonomy->id,
            'taxonomyKey' => $taxonomy->key,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parentId' => $term->parent_id,
            'sortOrder' => $term->sort_order,
            'archived' => $term->isArchived(),
            'archivedAt' => $term->archived_at?->toIso8601String(),
            // 自動生成 slug は「一意だが意味を持たない」ので Admin が rename を案内できる（F-2）
            'generatedSlug' => TermSlug::isGenerated($term->slug),
        ];
    }

    /**
     * taxonomy ごとの term 件数。**導出値なので保存しない**（§B）。
     *
     * @return array{total: int, active: int, archived: int}
     */
    public static function countsFor(string $taxonomyId): array
    {
        $total = TaxonomyTerm::query()->where('taxonomy_id', $taxonomyId)->count();
        $archived = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->whereNotNull('archived_at')
            ->count();

        return [
            'total' => $total,
            'active' => $total - $archived,
            'archived' => $archived,
        ];
    }
}
