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
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TermHierarchy;
use AgentCms\Core\Taxonomy\TermSlug;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * 項目の作成（Phase 9.3B / STAGE 2B・SPEC §C / §D / §F）。
 *
 * ## 入力 contract（STAGE 2B CORRECTION A）
 *
 * 受理するキーは **`taxonomyKey` / `name` / `slug` / `description` / `parentId` だけ**。
 * それ以外は正しいキーと一緒に来ても **全体を validation error** にする
 * （`id` / `taxonomyId` / `managedBy` / `archived` / `sortOrder` / 未知キーを黙って無視しない）。
 *
 * | キー | 型 |
 * | --- | --- |
 * | `taxonomyKey` | 必須・空でない文字列 |
 * | `name` | 必須・空でない文字列（trim 後に判定） |
 * | `slug` | 未指定なら自動生成。**指定するなら空でない文字列のみ**（null / 空文字 / 数値 / 配列 / boolean は error） |
 * | `description` | 未指定・null は許可。文字列は許可。**空文字は null へ正規化**。それ以外は error |
 * | `parentId` | 未指定・null は root。**指定するなら空でない文字列のみ**。型不正を null へ変換して root 作成しない |
 *
 * ## lock 順序（STAGE 2B CORRECTION D / E）
 *
 *   transaction → **Taxonomy 行を lock** → parent 行を lock → 兄弟を lock
 *   → **lock 済みの新しい状態で再検証** → insert → commit → Audit
 *
 * transaction の外でも事前検証するが、**保存可否を決めるのは transaction 内の再検証**。
 * 検証のあとに親が archive される窓を残さない。
 */
final class CreateTerm implements Operation
{
    private const NAME_MAX = 120;

    private const ALLOWED_KEYS = ['taxonomyKey', 'name', 'slug', 'description', 'parentId'];

    /** fallback slug が衝突したときの再試行回数（ULID 由来なので実際には起きない） */
    private const SLUG_ATTEMPTS = 5;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly TaxonomyLookup $lookup,
        private readonly OperationAudit $outcome,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function operationId(): string
    {
        return 'term.create';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $reader = new TermInput($input);
        $reader->rejectUnknownKeys(self::ALLOWED_KEYS);

        $taxonomyKey = $reader->requiredString('taxonomyKey', '分類のキー', 64);
        $name = $reader->requiredString('name', '名前', self::NAME_MAX);
        $slug = $reader->optionalNonEmptyString('slug', 'スラッグ');
        $description = $reader->nullableString('description', '説明');
        $parentId = $reader->nullableNonEmptyString('parentId', '親の項目');

        if ($reader->failed()) {
            return $this->outcome->record($this, $context, Result::validationError($reader->errors(), '入力内容を確認してください'));
        }

        $taxonomy = $this->lookup->taxonomy($taxonomyKey);

        if ($taxonomy instanceof Result) {
            // 分類が見つからない・使えないも結末（Correction 2-5）
            return $this->outcome->record($this, $context, $taxonomy, resourceType: 'taxonomy', resourceId: $taxonomyKey);
        }

        try {
            /** @var TaxonomyTerm|Result $outcome */
            $outcome = $this->db->connection()->transaction(
                fn (): TaxonomyTerm|Result => $this->createLocked(
                    $taxonomy->id, $name, $slug, $description, $parentId,
                )
            );
        } catch (QueryException $e) {
            // 並行作成で slug / sort_order が競合しても、生の QueryException を利用者へ返さない
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため作成できませんでした。もう一度お試しください。'
            ));
        }

        if ($outcome instanceof Result) {
            // transaction 内で決まった拒否（親不正 / slug 競合など）も 1 行残す
            return $this->outcome->record($this, $context, $outcome, resourceType: 'taxonomy', resourceId: $taxonomy->key);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['term' => TaxonomyPresenter::term($outcome, $taxonomy)],
                ['taxonomyKey' => $taxonomy->key],
            ),
            resourceType: 'taxonomy_term',
            resourceId: $outcome->id,
            metadata: [
                'taxonomy' => $taxonomy->key,
                'name' => $outcome->name,
                'slug' => $outcome->slug,
                'parentId' => $outcome->parent_id,
            ],
        );
    }

    /**
     * **authoritative validation はここ**（transaction 内・lock 済みの状態）。
     */
    private function createLocked(
        string $taxonomyId,
        string $name,
        ?string $slug,
        ?string $description,
        ?string $parentId,
    ): TaxonomyTerm|Result {
        // 1. Taxonomy 行を lock（共通 mutex）。存在と managed_by 整合を再確認する
        $taxonomy = $this->lookup->lockTaxonomy($taxonomyId);

        if ($taxonomy instanceof Result) {
            return $taxonomy;
        }

        // 2. 親を lock してから検証する。**検証後に archive される窓を作らない**
        if ($parentId !== null) {
            $parent = TaxonomyTerm::query()->whereKey($parentId)->lockForUpdate()->first();

            if ($parent === null) {
                return Result::validationError(['parentId' => ['指定された親の項目が見つかりません']]);
            }
        }

        // 3. lock 済みの状態で階層を再検証（同一 taxonomy / archived / depth）
        $hierarchy = TermHierarchy::validateParent($taxonomy, null, $parentId);

        if ($hierarchy->isCorrupt()) {
            return Result::conflict((string) $hierarchy->corruption);
        }

        if ($hierarchy->isInvalid()) {
            return Result::validationError(['parentId' => $hierarchy->errors]);
        }

        $termId = TaxonomyId::generate();

        // 4. slug を lock 済みの状態で確定する
        if ($slug !== null) {
            $canonical = TermSlug::canonicalize($slug);
            $slugErrors = TermSlug::validate($canonical, $taxonomy->id);

            if ($slugErrors !== []) {
                return Result::validationError(['slug' => $slugErrors]);
            }

            $slug = $canonical;
        } else {
            $slug = $this->generateAvailableSlug($name, $termId, $taxonomy);

            if ($slug === null) {
                return Result::conflict('スラッグを自動生成できませんでした。スラッグを指定して作成してください。');
            }
        }

        // 5. 同じ親の末尾へ置く。**archived な兄弟も席を占める**ため対象に含める
        $max = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomy->id)
            ->where(fn ($q) => $parentId === null
                ? $q->whereNull('parent_id')
                : $q->where('parent_id', $parentId))
            ->lockForUpdate()
            ->max('sort_order');

        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => $termId,
            'taxonomy_id' => $taxonomy->id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parent_id' => $parentId,
            'sort_order' => $max === null ? 0 : ((int) $max) + 1,
            // 新しい項目は必ず active（archived_at は外部入力から受け取らない）
            'archived_at' => null,
        ])->save();

        return $term;
    }

    /**
     * 名前から使える slug を作る。
     * fallback は ULID 由来なので実質衝突しないが、**衝突しても 500 にしない**。
     */
    private function generateAvailableSlug(string $name, string $termId, Taxonomy $taxonomy): ?string
    {
        /*
         * 名前から綴りが作れるなら、それを使う（`Press Release` → `press-release`）。
         * 作れないとき（日本語だけの名前）は**読める通し番号**へ倒す
         * — 分類ごとの一覧ページを作ったとき、そのまま URL になるためである
         * （WP 一周 6 周目 2026-09-03。以前は `term-a2ywwcsg` のような乱数だった）。
         */
        $fromName = TermSlug::generate($name, $termId);

        $candidate = TermSlug::isGenerated($fromName)
            ? TermSlug::numberedFallback($taxonomy->id)
            : $fromName;

        for ($i = 0; $i < self::SLUG_ATTEMPTS; $i++) {
            if (! TermSlug::isTaken($taxonomy->id, $candidate)) {
                return $candidate;
            }

            /*
             * 同義語の自動統合はしない（F-3）。
             * 名前から作れた綴りが埋まっているなら **連番**を足す（`press-release-2`）。
             * 作れなかったものは、次に空いている通し番号を取り直す。
             */
            $candidate = TermSlug::isGenerated($fromName)
                ? TermSlug::numberedFallback($taxonomy->id)
                : $fromName.'-'.($i + 2);
        }

        return null;
    }
}
