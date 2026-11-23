<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 取り込んだ分類項目を取り消す（Phase 9.14 / STAGE 27E-2・SPEC §D.3）。
 *
 * ## 削除可否を自分で数えない
 *
 * 判定者は常に `ReferenceInspector` である（9.3B / §I.3）。
 * ここで `taxonomy_term_assignments` を数え直すと、参照の種類が増えたときに
 * **こちらだけ古い数え方のまま**になり、まだ使われている項目を消す。
 *
 * ## 子から先に消す
 *
 * `TermImporter` は `creationOrder()`（親が先）の順で台帳へ書くので、
 * **台帳の行を id の降順に見れば子から先になる**。順を自分で組み直さない —
 * 組み直すと、作った順と消す順の正本が 2 つになる。
 * 親を先に消そうとしても `fk_term_parent` が止めるが、止まってから直すのでは
 * 「参照が残っている」と「順が違う」が同じ報告に見える。
 *
 * ## 所有していない項目は対象にしない
 *
 * 使い回した項目・重なった `wp:term_id` の alias は `created_by_import = false` で、
 * こちらが作っていない。消すと**元からあった分類が移行の取り消しで消える**。
 * その行の始末は `LedgerSweeper` が行う（対象には触れず、記録だけ）。
 */
final class TermReverter
{
    public function __construct(
        private readonly ReferenceInspector $references,
    ) {}

    /**
     * batch の分類項目を取り消し、結果を数えて返す。
     *
     * @return array<string, int>
     */
    public function revert(string $batchId): array
    {
        $counts = [];

        $rows = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Term->value)
            ->where('created_by_import', true)
            // **子から先**（`creationOrder()` の逆順）
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $key = $this->countKey((string) $row->target_id, Carbon::parse((string) $row->created_at));

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** 1 件を見て、消すか残すかを決める。返すのは内訳の鍵 */
    private function countKey(string $termId, Carbon $importedAt): string
    {
        $term = TaxonomyTerm::query()->find($termId);

        if (! $term instanceof TaxonomyTerm) {
            // 台帳の行は残す。再実行が「対象が無い」と言えるようにする
            return 'kept_missing';
        }

        if ($term->updated_at !== null && Carbon::parse($term->updated_at)->greaterThan($importedAt)) {
            // 人が名前や親を直した項目は、移行の取り消しで消さない
            return 'kept_touched';
        }

        if (! $this->references->referencesToTerm($term)->isDeletable()) {
            /*
             * まだ使われている（他の記事が付けている・子が居る・調査が終わらない）。
             * **残して報告する**（§D.3 の表）。台帳の行も残す。
             */
            return 'kept_referenced';
        }

        DB::transaction(function () use ($termId): void {
            DB::table('taxonomy_terms')->where('id', $termId)->delete();

            DB::table('import_records')
                ->where('kind', ImportKind::Term->value)
                ->where('target_id', $termId)
                ->delete();
        });

        return 'reverted_term';
    }
}
