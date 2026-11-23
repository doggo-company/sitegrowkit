<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\Reference\ReferenceReport;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * 項目の削除（Phase 9.3B / STAGE 2B・SPEC §I.1 / §I.3）。
 *
 * **DESTRUCTIVE。** 処理順序を固定する。
 *
 *   1. OperationPolicy
 *   2. term の存在確認
 *   3. **ReferenceInspector** で参照調査
 *   4. 参照あり **または** 調査未完了なら CONFLICT（confirmation を発行しない）
 *   5. 参照ゼロ かつ complete のときだけ ConfirmationGate
 *   6. 正しい token 受領後、**削除直前に ReferenceInspector を再実行**
 *   7. 再検査でも deletable のときだけ delete
 *   8. 成功時に Audit
 *
 * ## 拒否された削除も記録する（SPEC §AC）
 *
 * 「誰が何を消そうとしたか」は、成功した削除と同じかそれ以上に監査上の価値がある。
 * 記録するのは **削除を拒否した 3 経路**だけ:
 * 初回の参照調査 / 確認後の再検査 / DB の FK が最後に止めた場合。
 * `confirmation_required`・`forbidden`・`notFound`・validation error は
 * 「削除を拒否した」ではないので記録しない。記録は **transaction の外**で 1 回だけ行う。
 *
 * **削除可否を独自の count で判定しない。** 判定者は常に `ReferenceInspector` である。
 * archived term でも条件は同じ（AR-8）。archive は削除の代替であって RESTRICT を緩めない。
 *
 * DB の外部キー RESTRICT は **最後の防御**として残す。
 * assignment の自動解除も CASCADE も行わない。
 */
final class DeleteTerm implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
        private readonly ReferenceInspector $inspector,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function operationId(): string
    {
        return 'term.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $termId, OperationContext $context): Result
    {
        // 1. 認可
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // 2. 存在確認
        $term = $this->lookup->term($termId);

        if ($term instanceof Result) {
            // 見つからない項目への削除試行も 1 行残す（Correction 2-5）
            return $this->outcome->record($this, $context, $term, resourceType: 'taxonomy_term', resourceId: $termId);
        }

        $taxonomy = $term->taxonomy()->first();
        $label = $term->name !== '' ? $term->name : $term->slug;

        // 3. 参照調査（唯一の判定者）
        $report = $this->inspector->referencesToTerm($term);

        // 4. 参照あり / 調査未完了なら CONFLICT。**confirmation は発行しない**
        if (! $report->isDeletable()) {
            // 「誰が何を消そうとして、なぜ止まったか」は監査の対象（SPEC §AC）
            return $this->recordRejection($term, $taxonomy, $context, $this->conflict($label, $report), $report);
        }

        // 5. ここではじめて確認を求める。token は操作 ID + term ID + actor へ束縛される
        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['termId' => $term->id],
            summary: "分類の項目「{$label}」を削除します",
            impact: [
                '削除した項目は元に戻せません',
                '使わなくなっただけであれば、アーカイブで一覧から外せます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
        }

        // 6-7. 削除直前の再検査と削除を同一 transaction に入れる。
        // lock 順序は他の term mutation と揃える: Taxonomy 行 → Term 行（CORRECTION D / G）
        // Audit は **transaction の外**で記録する。
        // 中で書くと、rollback したときに「拒否した記録」まで一緒に消える
        try {
            /** @var array{result: Result, report: ReferenceReport|null}|null $stopped */
            $stopped = $this->db->connection()->transaction(function () use ($term, $label): ?array {
                // 1. Taxonomy 行を lock（共通 mutex）
                $lockedTaxonomy = $this->lookup->lockTaxonomy($term->taxonomy_id);

                if ($lockedTaxonomy instanceof Result) {
                    return ['result' => $lockedTaxonomy, 'report' => null];
                }

                // 2. 対象 Term を lock（同一行に対する競合はここで直列化される）
                $locked = TaxonomyTerm::query()->whereKey($term->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return [
                        'result' => Result::notFound("項目 [{$term->id}] が見つかりません"),
                        'report' => null,
                    ];
                }

                // 3. lock 済みの状態で参照を再検査する
                $recheck = $this->inspector->referencesToTerm($locked);

                if (! $recheck->isDeletable()) {
                    // 確認後に新しい参照が生まれていた / 調査できなくなった
                    return ['result' => $this->conflict($label, $recheck), 'report' => $recheck];
                }

                $locked->delete();

                return null;
            });
        } catch (QueryException $e) {
            // DB の FK RESTRICT が最後に止めた場合もここへ来る
            report($e);

            // ここまで来て止められた＝Application の判定をすり抜けている。
            // **一番残しておきたい削除試行**なので落とさない
            return $this->recordRejection($term, $taxonomy, $context, Result::conflict(
                "「{$label}」は使用中のため削除できませんでした。"
                .'使わなくなっただけであれば、アーカイブで一覧から外せます。'
            ), null);
        }

        if ($stopped !== null) {
            /*
             * 参照で止めた場合は内訳つきで、それ以外（既に消えていた・
             * 分類が使えなくなっていた）は最小限の項目で記録する。
             * **どちらも記録する** — 「試みたが通らなかった」ことこそ監査の対象（Correction 2-5）。
             */
            if ($stopped['report'] !== null) {
                return $this->recordRejection($term, $taxonomy, $context, $stopped['result'], $stopped['report']);
            }

            return $this->outcome->record(
                $this,
                $context,
                $stopped['result'],
                resourceType: 'taxonomy_term',
                resourceId: $term->id,
                metadata: ['taxonomy' => $taxonomy?->key, 'taxonomyId' => $taxonomy?->id],
            );
        }

        // 8. 削除できた
        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['id' => $term->id],
                ['taxonomyKey' => $taxonomy?->key, 'name' => $label],
            ),
            resourceType: 'taxonomy_term',
            resourceId: $term->id,
            metadata: [
                'taxonomy' => $taxonomy?->key,
                'taxonomyId' => $taxonomy?->id,
                'name' => $term->name,
                'slug' => $term->slug,
                'archived' => $term->isArchived(),
            ],
        );
    }

    /**
     * 拒否された削除試行を記録する（SPEC §AC）。
     *
     * 監査で読みたいのは **「誰が」「どれを」「なぜ消せなかったか」**であって、
     * 何がその項目を使っているかの一覧ではない。
     * `ReferenceReport` をそのまま入れると参照先の題名や slug まで監査ログへ複製され、
     * 権限の違う相手にコンテンツの中身が漏れる。だから **件数と確定したかどうか**だけ残す。
     *
     * @param  ReferenceReport|null  $report  null は DB の FK が最後に止めた場合（内訳は不明）
     */
    private function recordRejection(
        TaxonomyTerm $term,
        ?Taxonomy $taxonomy,
        OperationContext $context,
        Result $result,
        ?ReferenceReport $report,
    ): Result {
        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'taxonomy_term',
            resourceId: $term->id,
            metadata: [
                'taxonomy' => $taxonomy?->key,
                'taxonomyId' => $taxonomy?->id,
                'name' => $term->name,
                'slug' => $term->slug,
                'archived' => $term->isArchived(),
                // 何が止めたかの種別。参照先そのものは残さない
                'rejected' => match (true) {
                    $report === null => 'db_restrict',
                    $report->isComplete() => 'references',
                    default => 'undetermined',
                },
                // 調査できていない場合の件数は「参照ゼロ」ではないので、
                // 数だけを見て安全と読まれないよう complete と対で残す
                'references' => $report?->totalCount(),
                'complete' => $report?->isComplete(),
            ],
        );
    }

    /** 参照あり / 判定不能の CONFLICT。**archive という代替手段を必ず案内する** */
    private function conflict(string $label, ReferenceReport $report): Result
    {
        $message = $report->isComplete()
            ? "「{$label}」は{$report->summary()}削除できません。"
                .'先に付け替えるか、アーカイブで一覧から外してください。'
            : "「{$label}」は{$report->summary()}安全のため削除しません。"
                .'時間をおいて再試行するか、アーカイブで一覧から外してください。';

        return Result::conflict($message, ['references' => $report->toArray()]);
    }
}
