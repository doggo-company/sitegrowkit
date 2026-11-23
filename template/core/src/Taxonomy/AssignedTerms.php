<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Support\Facades\DB;

/**
 * 記事に付いている分類を、**訪問者に見せる形**で読む
 * （WP 一周 6 周目 2026-09-03 / 効くもの ②）。
 *
 * ## なぜ管理側の読み方を使い回さないか
 *
 * 管理画面は「付いていたものを黙って落とさない」ために、
 * **アーカイブ済みの項目も選択済みのまま見せる**（AS-12）。
 * 訪問者に見せてよいのは**いま使っているもの**だけなので、条件が違う。
 * 同じ関数で両方を賄うと、片方の都合がもう片方へ漏れる。
 *
 * ## 出さないもの
 *
 * - `taxonomies.status` が `active` でない分類（無効にしたもの）
 * - `taxonomy_terms.archived_at` が入っている項目
 *
 * 並びは**管理画面と同じ**（分類のキー順 → 割り当ての `sort_order`）。
 * 訪問者から見た順番が、編集した人の意図とずれないようにする。
 */
final class AssignedTerms
{
    /**
     * @return list<array{key: string, label: string, terms: list<array{name: string, slug: string}>}>
     */
    public function forEntry(string $contentType, int $contentId): array
    {
        $rows = DB::table('taxonomy_term_assignments as a')
            ->join('taxonomy_terms as t', 't.id', '=', 'a.term_id')
            ->join('taxonomies as x', 'x.id', '=', 't.taxonomy_id')
            ->where('a.content_type', $contentType)
            ->where('a.content_id', $contentId)
            ->where('x.status', 'active')
            ->whereNull('t.archived_at')
            ->orderBy('x.key')
            ->orderBy('a.sort_order')
            ->orderBy('a.id')
            ->get(['x.key as taxonomy_key', 'x.label as taxonomy_label', 't.name', 't.slug']);

        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) $row->taxonomy_key;

            $grouped[$key] ??= [
                'key' => $key,
                // 表示名が空なら、せめてキーを出す（無言で消さない）
                'label' => ((string) $row->taxonomy_label) !== '' ? (string) $row->taxonomy_label : $key,
                'terms' => [],
            ];

            $grouped[$key]['terms'][] = [
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
            ];
        }

        return array_values($grouped);
    }
}
