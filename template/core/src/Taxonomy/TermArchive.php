<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Support\Facades\DB;

/**
 * 分類ごとの一覧に要るもの（WP 一周 6 周目 2026-09-03 / 効くもの ② の後半）。
 *
 * 記事に分類の名前を出すところまでは入れた。
 * **押せる先が無ければ、名前が出ているだけ**なので、ここで一覧の材料を読む。
 *
 * ## 出さないもの
 *
 * `AssignedTerms` と同じ条件 — 無効にした分類（`status != active`）と、
 * アーカイブ済みの項目は**無いものとして扱う**（404 になる）。
 * 「隠す」ではなく無い、が公開面の作法である。
 *
 * ## 記事そのものは読まない
 *
 * 返すのは**割り当てられた id** までで、公開済みかどうかは呼ぶ側が
 * `published` scope で絞る。公開面の条件を 2 か所に置かないためである
 * （`status` だけ見て `published_at` を忘れる、を繰り返さない）。
 */
final class TermArchive
{
    /**
     * @return array{
     *     taxonomyKey: string,
     *     taxonomyLabel: string,
     *     termName: string,
     *     termSlug: string,
     *     contentIds: list<int>
     * }|null
     */
    public function find(string $contentType, string $taxonomyKey, string $termSlug): ?array
    {
        $term = DB::table('taxonomy_terms as t')
            ->join('taxonomies as x', 'x.id', '=', 't.taxonomy_id')
            ->where('x.key', $taxonomyKey)
            ->where('x.status', 'active')
            ->where('t.slug', TermSlug::canonicalize($termSlug))
            ->whereNull('t.archived_at')
            ->first(['t.id', 't.name', 't.slug', 'x.key as taxonomy_key', 'x.label as taxonomy_label']);

        if ($term === null) {
            return null;
        }

        $ids = DB::table('taxonomy_term_assignments')
            ->where('term_id', $term->id)
            ->where('content_type', $contentType)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'taxonomyKey' => (string) $term->taxonomy_key,
            // 表示名が空なら、せめてキーを出す（無言で消さない）
            'taxonomyLabel' => ((string) $term->taxonomy_label) !== ''
                ? (string) $term->taxonomy_label
                : (string) $term->taxonomy_key,
            'termName' => (string) $term->name,
            'termSlug' => (string) $term->slug,
            'contentIds' => array_values($ids),
        ];
    }
}
