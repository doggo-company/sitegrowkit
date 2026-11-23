<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * 項目の並べ替え（Phase 9.3B / STAGE 2B・SPEC §E）。
 *
 * **同一 parent 内の並べ替えに限定する。** 親を変えるのは `term.update` の `parentId`。
 *
 * ## full sibling-set contract
 *
 * `orderedTermIds` は **その taxonomy・その parent の兄弟 term 全件**でなければならない。
 *
 * | 規則 | 理由 |
 * | --- | --- |
 * | **archived な兄弟も含める** | 通常の `term.list` は active のみ（AR-1）。部分集合を許すと、**画面に出ていない archived の兄弟を意図せず上書き**してしまう |
 * | 重複 ID を禁止 | 並び順が一意に決まらない |
 * | 欠落 ID を禁止 | 残りの兄弟の位置が未定義になる |
 * | 余分な ID を禁止 | 別の兄弟集合を巻き込む |
 * | 別 taxonomy / 別 parent / 存在しない ID を禁止 | 同上 |
 *
 * したがって管理 tree / 並べ替え UI は **`term.list` を `includeArchived = true`**
 * で取得してから並べ替えを送る（HR-3）。
 *
 * この操作は `sort_order` **だけ**を 0 から連番で更新する。
 * `parent_id` と `archived_at` は変更しない。
 */
final class ReorderTerms implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
        private readonly OperationAudit $outcome,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function operationId(): string
    {
        return 'term.reorder';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * `$orderedTermIds` は **外部入力**なので list<string> と決めつけない。
     * 形と要素の型は `validateElements()` が検証する。
     *
     * @param  array<array-key, mixed>  $orderedTermIds
     */
    public function execute(
        string $taxonomyKey,
        ?string $parentId,
        array $orderedTermIds,
        OperationContext $context,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $taxonomy = $this->lookup->taxonomy($taxonomyKey);

        if ($taxonomy instanceof Result) {
            return $this->outcome->record($this, $context, $taxonomy, resourceType: 'taxonomy', resourceId: $taxonomyKey);
        }

        // 要素の型を厳密に見る。配列 / 数値 / boolean / null を渡されても
        // warning や TypeError にせず validation error を返す（CORRECTION C）
        $elementErrors = $this->validateElements($orderedTermIds);

        if ($elementErrors !== []) {
            return $this->outcome->record($this, $context, Result::validationError(['orderedTermIds' => $elementErrors]), resourceType: 'taxonomy', resourceId: $taxonomy->id);
        }

        /** @var list<string> $ids */
        $ids = array_values($orderedTermIds);

        try {
            /** @var array{before: list<string>, after: list<string>}|Result $outcome */
            $outcome = $this->db->connection()->transaction(
                fn (): array|Result => $this->apply($taxonomy->id, $parentId, $ids)
            );
        } catch (QueryException $e) {
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため並べ替えできませんでした。もう一度お試しください。'
            ), resourceType: 'taxonomy', resourceId: $taxonomy->id);
        }

        if ($outcome instanceof Result) {
            return $this->outcome->record($this, $context, $outcome, resourceType: 'taxonomy', resourceId: $taxonomy->id);
        }

        $result = Result::success(
            ['order' => $outcome['after']],
            [
                'taxonomyKey' => $taxonomy->key,
                'parentId' => $parentId,
                'count' => count($outcome['after']),
            ],
        );

        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'taxonomy',
            resourceId: $taxonomy->id,
            // **要約 1 件だけ**にする（SPEC §AC）。
            // before / after の全 ID を残すと、兄弟が増えるほど 1 回の並べ替えで
            // 監査ログが膨らみ、「何件動いたか」という読みたい情報が埋もれる。
            // 個々の位置は term 自体の現在値で追える
            metadata: [
                'taxonomy' => $taxonomy->key,
                'parentId' => $parentId,
                'moved' => count(array_diff_assoc($outcome['after'], $outcome['before'])),
                'total' => count($outcome['after']),
            ],
        );
    }

    /**
     * `orderedTermIds` の形と要素の型を検証する（CORRECTION C）。
     *
     * @param  array<array-key, mixed>  $ids
     * @return list<string> 日本語のエラー
     */
    private function validateElements(array $ids): array
    {
        if (! array_is_list($ids)) {
            return ['項目の並び順は配列（リスト）で指定してください'];
        }

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                return ['項目の ID は空でない文字列で指定してください'];
            }

            if (! TaxonomyId::isValid($id)) {
                return ['項目の ID の形式が不正です'];
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            return ['同じ項目が複数回指定されています'];
        }

        return [];
    }

    /**
     * **authoritative validation はここ**（transaction 内・lock 済みの状態）。
     *
     * lock 順序は他の term mutation と揃える:
     * Taxonomy 行 → parent 行 → 兄弟行（CORRECTION D / G）。
     *
     * @param  list<string>  $orderedTermIds
     * @return array{before: list<string>, after: list<string>}|Result
     */
    private function apply(string $taxonomyId, ?string $parentId, array $orderedTermIds): array|Result
    {
        // 1. Taxonomy 行を lock（共通 mutex）
        $taxonomy = $this->lookup->lockTaxonomy($taxonomyId);

        if ($taxonomy instanceof Result) {
            return $taxonomy;
        }

        // 2. parent を lock 済みの新しい状態で検証する
        if ($parentId !== null) {
            $parent = TaxonomyTerm::query()->whereKey($parentId)->lockForUpdate()->first();

            if ($parent === null || $parent->taxonomy_id !== $taxonomy->id) {
                return Result::validationError(
                    ['parentId' => ['指定された親の項目が見つかりません']],
                );
            }
            // parent が archived でも、既にある兄弟の並べ替えは行える
        }

        // 3. 兄弟行を lock してから読む。並行 reorder で一部だけ反映される状態を作らない
        $siblings = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomy->id)
            ->where(fn ($q) => $parentId === null
                ? $q->whereNull('parent_id')
                : $q->where('parent_id', $parentId))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var list<string> $before */
        $before = $siblings->pluck('id')->values()->all();

        $missing = array_values(array_diff($before, $orderedTermIds));
        $extra = array_values(array_diff($orderedTermIds, $before));

        if ($missing !== [] || $extra !== []) {
            // full sibling-set contract 違反。**部分集合での上書きを許さない**
            $messages = [];

            if ($missing !== []) {
                $messages[] = sprintf(
                    '並べ替えには同じ階層の項目をすべて含めてください（%d 件不足しています）。'
                    .'アーカイブ済みの項目も含める必要があります。',
                    count($missing),
                );
            }

            if ($extra !== []) {
                $messages[] = sprintf(
                    '同じ階層にない項目が %d 件含まれています。',
                    count($extra),
                );
            }

            return Result::validationError(['orderedTermIds' => $messages]);
        }

        foreach ($orderedTermIds as $position => $id) {
            // sort_order だけを更新する。parent_id / archived_at は触らない
            TaxonomyTerm::query()->whereKey($id)->update(['sort_order' => $position]);
        }

        return ['before' => $before, 'after' => $orderedTermIds];
    }
}
