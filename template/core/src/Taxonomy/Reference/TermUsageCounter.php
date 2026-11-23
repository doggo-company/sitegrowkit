<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;

/**
 * 管理画面の「使用件数」（Phase 9.3B / #16・SPEC §R）。
 *
 * ## contract
 *
 * | 規則 | 理由 |
 * | --- | --- |
 * | **導出値。保存しない** | `terms.count` のようなキャッシュ列を持つと必ず実データとずれる（§B / §R） |
 * | **削除可否を判定しない** | 判定者は常に `ReferenceInspector`。ここは表示のための件数だけを返す |
 * | **数えるのは R-1（コンテンツの割り当て）だけ** | 子の項目・ページ・コンテンツタイプ定義からの参照は含まない |
 * | archived かどうかで件数を変えない | archive は「一覧から外す」だけで参照を消さない（AR-8） |
 *
 * **この件数が 0 でも削除できるとは限らない。** 画面のラベルも「コンテンツ N 件」とし、
 * 「使用 0 件だから消せる」と読めないようにしてある。削除は必ず Inspector の結果で決まる。
 *
 * `AssignmentReferenceProvider`（R-1）と**同じ表・同じ意味**を、
 * 管理 tree のために **term 件数に依存しない 1 クエリ**でまとめて数える形。
 * 1 件ずつ Inspector へ問うと tree 表示のたびに PageVersion 全走査まで走ってしまうため、
 * **表示用の件数と削除可否の判定を明確に分ける**。
 *
 * **read-only。DB を書き換えない。**
 */
final class TermUsageCounter
{
    /**
     * term ID → コンテンツからの参照件数。**指定した ID は 0 件でも必ず返す**。
     *
     * @param  list<string>  $termIds
     * @return array<string, int>
     */
    public function forTerms(array $termIds): array
    {
        if ($termIds === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = array_fill_keys($termIds, 0);

        $rows = TaxonomyTermAssignment::query()
            ->toBase()
            ->selectRaw('term_id, count(*) as total')
            ->whereIn('term_id', $termIds)
            ->groupBy('term_id')
            ->get();

        foreach ($rows as $row) {
            $id = (string) $row->term_id;

            if (array_key_exists($id, $counts)) {
                $counts[$id] = (int) $row->total;
            }
        }

        return $counts;
    }
}
