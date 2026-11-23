<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Support\Facades\DB;

/**
 * 分類の項目を **id から引く**（WP 一周 7 周目 2026-09-03 / 効くもの ③）。
 *
 * メニューが分類の一覧を指すために要る。
 *
 * ## なぜ綴りではなく id で持つか
 *
 * 綴り（`term-1`）は**あとから変えられる**。URL を直接指定で持つと、
 * 変えた瞬間にメニューが黙って 404 になる。
 * `menu_items` は URL を持たない設計（`MenuItem` の docblock）なので、ここもそれに従う。
 *
 * ## 出さないもの
 *
 * 無効にした分類（`status != active`）と、アーカイブ済みの項目は `null`。
 * **公開面と同じ条件**にしておかないと、メニューにだけ生きたリンクが残る
 *（`AssignedTerms` / `TermArchive` と揃えてある）。
 */
final class TermLink
{
    /**
     * @return array{name: string, slug: string, taxonomyKey: string, taxonomyLabel: string}|null
     */
    public function find(string $termId): ?array
    {
        if ($termId === '') {
            return null;
        }

        $row = DB::table('taxonomy_terms as t')
            ->join('taxonomies as x', 'x.id', '=', 't.taxonomy_id')
            ->where('t.id', $termId)
            ->where('x.status', 'active')
            ->whereNull('t.archived_at')
            ->first(['t.name', 't.slug', 'x.key as taxonomy_key', 'x.label as taxonomy_label']);

        if ($row === null) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'taxonomyKey' => (string) $row->taxonomy_key,
            // 表示名が空なら、せめてキーを出す（無言で消さない）
            'taxonomyLabel' => ((string) $row->taxonomy_label) !== ''
                ? (string) $row->taxonomy_label
                : (string) $row->taxonomy_key,
        ];
    }
}
