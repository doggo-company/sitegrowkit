<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Support\Facades\DB;

/**
 * 分類の一覧（Phase 9.3B / STAGE 2B・SPEC §P.3）。
 *
 * **read-only。DB へ 1 行も書かない。**
 * - runtime で taxonomy-sync を実行しない（PV-3 / SY-3）
 * - 未同期・`managed_by` 競合を黙って修復しない
 * - `managed_by = code` の taxonomy は read-only。**作成・更新・削除の操作は用意しない**
 *   （CMS-managed taxonomy は Phase 9.4 — §P.2）
 * - checked-in JSON へ ULID を書き戻さない（§J.2）
 */
final class ListTaxonomies implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'taxonomy.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        // taxonomy ごとの件数を 1 クエリで集める（N+1 を作らない）。
        // 件数は導出値なので保存しない（§B）
        /** @var array<string, array{total: int, archived: int}> $counts */
        $counts = [];

        foreach (TaxonomyTerm::query()
            ->toBase()
            ->selectRaw('taxonomy_id, count(*) as total, sum(case when archived_at is null then 0 else 1 end) as archived')
            ->groupBy('taxonomy_id')
            ->get() as $row) {
            $counts[(string) $row->taxonomy_id] = [
                'total' => (int) $row->total,
                'archived' => (int) $row->archived,
            ];
        }

        /*
         * 関連付けられている投稿タイプ（Phase 9.7 / STAGE 20D）。
         * これも導出値なので保存しない。1 クエリでまとめて集める
         */
        /** @var array<string, list<string>> $associations */
        $associations = [];

        try {
            foreach (DB::table('content_type_taxonomies')->orderBy('content_type')->get() as $row) {
                $associations[(string) $row->taxonomy_id][] = (string) $row->content_type;
            }
        } catch (\Throwable) {
            // 表がまだ無い環境では「関連付けなし」として出す（一覧は落とさない）
            $associations = [];
        }

        $items = Taxonomy::query()
            ->orderBy('key')
            ->get()
            ->map(static function (Taxonomy $taxonomy) use ($counts, $associations): array {
                $total = $counts[$taxonomy->id]['total'] ?? 0;
                $archived = $counts[$taxonomy->id]['archived'] ?? 0;

                return [
                    ...TaxonomyPresenter::taxonomy($taxonomy, [
                        'total' => $total,
                        'active' => $total - $archived,
                        'archived' => $archived,
                    ]),
                    'contentTypes' => $associations[$taxonomy->id] ?? [],
                ];
            })
            ->values()
            ->all();

        return Result::success(['taxonomies' => $items], ['count' => count($items)]);
    }
}
