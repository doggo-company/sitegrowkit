<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;

/**
 * read response の `taxonomies` を組み立てる（Phase 9.3B / STAGE 3B・SPEC §K.4）。
 *
 * ## なぜ `GetContent::present()` に足さないのか
 *
 * `present()` は **static かつ DB 非依存**の純粋な写像で、Create / Update / List が共有している。
 * そこから `app()` や DB facade を呼ぶと、静的メソッドが暗黙にコンテナと DB へ依存し、
 * 一覧では 1 行ごとにクエリが飛ぶ（N+1）。
 *
 * そこで **読み取りの責務だけを注入可能なクラスへ分離**し、
 * `present()` は今までどおり値の写像に徹する。
 * 一覧は `forEntries()` で **件数に依存しない固定クエリ数**（最大 3 本）にまとめる。
 *
 * ## contract
 *
 * - **ContentSchema が宣言した taxonomy を必ず返す**（assignment 0 件でも `terms: []`）
 * - 宣言が無ければ**空**（DB へ 1 度も触らない）
 * - **archived な assignment を read から消さない**（AS-11 の round-trip を壊さない）
 * - taxonomy ID を返さない（checked-in schema へ ULID を逆流させない — §J.2）
 */
final class ContentTaxonomyReader
{
    public function __construct(private readonly TaxonomyDefinitionRegistry $definitions) {}

    /** @return array<string, mixed> */
    public function forEntry(ContentSchema $schema, string $type, int $contentId): array
    {
        return $this->forEntries($schema, $type, [$contentId])[$contentId] ?? [];
    }

    /**
     * @param  list<int>  $contentIds
     * @return array<int, array<string, mixed>>
     */
    public function forEntries(ContentSchema $schema, string $type, array $contentIds): array
    {
        if ($schema->taxonomies === []) {
            return array_fill_keys($contentIds, []);
        }

        $taxonomies = $this->taxonomyRows($schema);
        $assignments = $this->assignments($type, $contentIds, array_keys($taxonomies));

        $response = [];

        foreach ($contentIds as $contentId) {
            $block = [];

            foreach ($schema->taxonomies as $declaration) {
                $taxonomy = $taxonomies[$declaration->key] ?? null;

                $block[$declaration->key] = [
                    'label' => $taxonomy->label ?? $this->declaredLabel($declaration->key),
                    'multiple' => $declaration->multiple,
                    'required' => $declaration->required,
                    'terms' => $taxonomy === null
                        ? []
                        : ($assignments[$contentId][$taxonomy->id] ?? []),
                ];
            }

            $response[$contentId] = $block;
        }

        return $response;
    }

    /** @return array<string, Taxonomy> key → 行（未同期なら欠ける） */
    private function taxonomyRows(ContentSchema $schema): array
    {
        $rows = [];

        foreach (Taxonomy::query()->whereIn('key', $schema->taxonomyKeys())->get() as $taxonomy) {
            $rows[$taxonomy->key] = $taxonomy;
        }

        return $rows;
    }

    /**
     * content ID → taxonomy ID → term 表現。**件数に依存しない 2 クエリ**で読む。
     *
     * @param  list<int>  $contentIds
     * @param  list<string>  $taxonomyKeys
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    private function assignments(string $type, array $contentIds, array $taxonomyKeys): array
    {
        if ($contentIds === [] || $taxonomyKeys === []) {
            return [];
        }

        $rows = TaxonomyTermAssignment::query()
            ->where('content_type', $type)
            ->whereIn('content_id', $contentIds)
            ->orderBy('content_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $terms = TaxonomyTerm::query()->whereIn('id', $rows->pluck('term_id')->all())->get();
        $grouped = [];

        foreach ($rows as $row) {
            $term = $terms->firstWhere('id', $row->term_id);

            if (! $term instanceof TaxonomyTerm) {
                continue;
            }

            $grouped[$row->content_id][$term->taxonomy_id][] = [
                'id' => $term->id,
                'name' => $term->name,
                'slug' => $term->slug,
                // archived でも read から消さない（AS-11 / AS-12）
                'archived' => $term->isArchived(),
            ];
        }

        return $grouped;
    }

    /** DB へ未同期でも read を失敗させない（label だけ定義ファイルから補う） */
    private function declaredLabel(string $key): string
    {
        return $this->definitions->has($key) ? $this->definitions->get($key)->label : $key;
    }
}
