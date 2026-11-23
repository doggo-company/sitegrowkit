<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TermHierarchy;
use AgentCms\Core\Taxonomy\TermSlug;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * 項目の更新（Phase 9.3B / STAGE 2B・SPEC §I.5）。
 *
 * ## 入力 contract（STAGE 2B CORRECTION B）
 *
 * 受理するキーは **`name` / `slug` / `description` / `parentId` / `archived` だけ**。
 * **未知キーも禁止キーも、正しいキーと一緒に来たら全体を validation error** にする。
 * `{"name": "新名称", "unexpected": true}` で name だけ更新することはしない。
 *
 * | キー | 型 |
 * | --- | --- |
 * | `name` | 空でない文字列 |
 * | `slug` | 空でない文字列（null / 空文字 / 非文字列は error） |
 * | `description` | null または文字列（**空文字は null へ正規化**） |
 * | `parentId` | null（明示 root）または空でない文字列。型不正を null へ変換して root 移動しない |
 * | `archived` | **boolean のみ**（`"true"` / `0` / `1` を暗黙変換しない） |
 *
 * ## archive semantics
 *
 * `archived: true` → `archived_at` に現在時刻／`false` → null（AR-7）／未指定 → 維持。
 * 同値更新は idempotent。archive は assignment（AR-4）・PageDocument filter（AR-5）・
 * name / slug（AR-6）・子 term（AR-10）を変更せず、削除の RESTRICT も緩めない（AR-8）。
 * WRITE なので ConfirmationGate を通さない。Audit に action を残す（AR-9）。
 *
 * ## lock 順序（STAGE 2B CORRECTION D / F）
 *
 *   transaction → **Taxonomy 行 lock** → **対象 Term を lock して再取得** →
 *   parent 行 lock → **lock 済みの新しい状態で再検証・差分計算** → save → commit → Audit
 *
 * transaction 前に取得した stale な model へは保存しない。
 */
final class UpdateTerm implements Operation
{
    private const NAME_MAX = 120;

    private const ALLOWED_KEYS = ['name', 'slug', 'description', 'parentId', 'archived'];

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
        private readonly OperationAudit $outcome,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function operationId(): string
    {
        return 'term.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(string $termId, array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        // 未知キー・禁止キーは黙って無視せず、全体を拒否する
        $keyCheck = new TermInput($input);
        $keyCheck->rejectUnknownKeys(self::ALLOWED_KEYS);

        if ($keyCheck->failed()) {
            return $this->outcome->record($this, $context, Result::validationError($keyCheck->errors(), '変更できない項目が含まれています'), resourceType: 'taxonomy_term', resourceId: $termId);
        }

        if ($input === []) {
            return $this->outcome->record($this, $context, Result::validationError(
                ['input' => ['変更する内容を 1 つ以上指定してください']],
                '変更内容がありません',
            ), resourceType: 'taxonomy_term', resourceId: $termId);
        }

        $term = $this->lookup->term($termId);

        if ($term instanceof Result) {
            return $this->outcome->record($this, $context, $term, resourceType: 'taxonomy_term', resourceId: $termId);
        }

        try {
            /** @var array{term: TaxonomyTerm, taxonomy: Taxonomy, action: string|null, changed: list<string>}|Result $outcome */
            $outcome = $this->db->connection()->transaction(
                fn (): array|Result => $this->updateLocked($term->id, $term->taxonomy_id, $input)
            );
        } catch (QueryException $e) {
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため更新できませんでした。もう一度お試しください。'
            ), resourceType: 'taxonomy_term', resourceId: $termId);
        }

        if ($outcome instanceof Result) {
            return $this->outcome->record($this, $context, $outcome, resourceType: 'taxonomy_term', resourceId: $termId);
        }

        $updated = $outcome['term'];

        // AR-10: 子は自動 archive しない。Admin が「子 N 件は active のままです」と言える材料
        $activeChildCount = TaxonomyTerm::query()
            ->where('parent_id', $updated->id)
            ->whereNull('archived_at')
            ->count();

        $result = Result::success(
            ['term' => TaxonomyPresenter::term($updated, $outcome['taxonomy'])],
            [
                'taxonomyKey' => $outcome['taxonomy']->key,
                'action' => $outcome['action'],
                'activeChildCount' => $activeChildCount,
                'childrenRemainActive' => $activeChildCount > 0,
            ],
        );

        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'taxonomy_term',
            resourceId: $updated->id,
            metadata: [
                'taxonomy' => $outcome['taxonomy']->key,
                'slug' => $updated->slug,
                // AR-9: archive / unarchive を後から判別できるようにする
                'action' => $outcome['action'] ?? 'update',
                'changed' => $outcome['changed'],
            ],
        );
    }

    /**
     * **authoritative validation はここ**（transaction 内・lock 済みの状態）。
     *
     * @param  array<string, mixed>  $input
     * @return array{term: TaxonomyTerm, taxonomy: Taxonomy, action: string|null, changed: list<string>}|Result
     */
    private function updateLocked(string $termId, string $taxonomyId, array $input): array|Result
    {
        // 1. Taxonomy 行を lock（共通 mutex）
        $taxonomy = $this->lookup->lockTaxonomy($taxonomyId);

        if ($taxonomy instanceof Result) {
            return $taxonomy;
        }

        // 2. 対象 Term を lock して**再取得**する。stale な model へ保存しない
        $term = TaxonomyTerm::query()->whereKey($termId)->lockForUpdate()->first();

        if ($term === null) {
            return Result::notFound("項目 [{$termId}] が見つかりません");
        }

        if ($term->taxonomy_id !== $taxonomy->id) {
            return Result::conflict('この項目が属する分類が変わりました。もう一度お試しください。');
        }

        // 3. lock 済みの現在状態を基準に値を読む
        $reader = new TermInput($input);
        $changes = [];

        if ($reader->has('name')) {
            $name = $reader->requiredString('name', '名前', self::NAME_MAX);

            if ($name !== '') {
                $changes['name'] = $name;
            }
        }

        if ($reader->has('slug')) {
            $slug = $reader->optionalNonEmptyString('slug', 'スラッグ');

            if ($slug !== null) {
                $canonical = TermSlug::canonicalize($slug);
                // 自分自身は重複から除外する
                $slugErrors = TermSlug::validate($canonical, $taxonomy->id, $term->id);

                if ($slugErrors !== []) {
                    $reader->addErrors('slug', $slugErrors);
                } else {
                    $changes['slug'] = $canonical;
                }
            }
        }

        if ($reader->has('description')) {
            $changes['description'] = $reader->nullableString('description', '説明');
        }

        // parentId: **未指定なら locked term の現在の親**を使う
        $parentId = $reader->nullableNonEmptyString('parentId', '親の項目', $term->parent_id);

        if ($reader->has('archived')) {
            $archived = $reader->boolean('archived', 'アーカイブ状態');

            // archive の判定は locked term の現在状態から行う
            if ($archived !== null && $archived !== $term->isArchived()) {
                $changes['archived_at'] = $archived ? now() : null;
            }
        }

        if ($reader->failed()) {
            return Result::validationError($reader->errors(), '入力内容を確認してください');
        }

        // 4. 親を lock してから階層を再検証する（検証後に archive される窓を作らない）
        if ($parentId !== null) {
            $parent = TaxonomyTerm::query()->whereKey($parentId)->lockForUpdate()->first();

            if ($parent === null) {
                return Result::validationError(['parentId' => ['指定された親の項目が見つかりません']]);
            }
        }

        // **parentId 指定の有無に関わらず**検証する（root 移動・他項目更新でも破損を通さない）
        $hierarchy = TermHierarchy::validateParent($taxonomy, $term, $parentId);

        if ($hierarchy->isCorrupt()) {
            return Result::conflict((string) $hierarchy->corruption);
        }

        if ($hierarchy->isInvalid()) {
            return Result::validationError(['parentId' => $hierarchy->errors], '入力内容を確認してください');
        }

        if ($reader->has('parentId') && $parentId !== $term->parent_id) {
            // 子孫は付いていく（D-6）。再配置しない
            $changes['parent_id'] = $parentId;
        }

        // 5. locked model へ保存する
        if ($changes !== []) {
            $term->forceFill($changes)->save();
        }

        $action = null;

        if (array_key_exists('archived_at', $changes)) {
            $action = $changes['archived_at'] === null ? 'unarchive' : 'archive';
        }

        return [
            'term' => $term->refresh(),
            'taxonomy' => $taxonomy,
            'action' => $action,
            'changed' => array_keys($changes),
        ];
    }
}
