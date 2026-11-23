<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\TaxonomyLookup;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\TaxonomyDeclaration;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;

/**
 * Content Entry の taxonomy assignment を**全置換**で更新する
 * （Phase 9.3B / STAGE 3B・SPEC §G.6 / §I.6 / §K.2 / §K.3）。
 *
 * ## 呼び出し規約（**必ず守る**）
 *
 * 1. 呼び出し元が transaction を開いていること
 * 2. **Content Entry 本体の行を `lockForUpdate()` 済み**であること（§G.6 の主 mutex）
 * 3. 失敗は戻り値ではなく `ContentWriteAbort` で通知する（部分適用を残さないため）
 *
 * ## 排他の役割分担
 *
 * | 対象 | 役割 |
 * | --- | --- |
 * | **Content Entry 行** | **唯一の主 mutex**。assignment が 0 件でも並行更新を直列化する |
 * | term 行 | 検証中の archive / 削除 / rename を止める。**主 mutex ではない** |
 * | taxonomy 行 | assignment 経路では **lock しない**（term mutation 側の mutex） |
 * | **assignment 行** | **lock しない**（読み書きは Content Entry lock の配下で行う） |
 * | DB unique / FK RESTRICT + 事後検証 | 最後の防御線 |
 *
 * **assignment に範囲ロックを掛けない**（STAGE 3B CORRECTION / F-3B-3）。
 * `where(content_type, content_id) … for update` は MySQL では 0 件でも
 * `idx_assignment_entry` に **gap lock** を掛け、index 上の隙間を共有する
 * **無関係な Content Entry** の更新まで待たせて誤った conflict を生む。
 * `sharedLock()` も gap lock が残るので使わない。
 * fresh state は「Content Entry 行ロック → 同一 transaction 内で読む」で担保する
 * （詳細は `currentAssignments()` のコメント）。
 *
 * **MySQL と SQLite の保証差**: SQLite は `lockForUpdate()` を無視する（no-op）。
 * したがって SQLite では「transaction + unique 制約 + 事後検証」だけが保証になる。
 * MySQL では行ロックが加わり、待機または直列化が実際に発生する。
 */
final class ContentTaxonomyAssignments
{
    public function __construct(private readonly TaxonomyLookup $lookup) {}

    /**
     * @param  array<string, list<string>>  $map  宣言済み taxonomy key → 入力（ID / slug）
     *
     * @throws ContentWriteAbort validation error / conflict（transaction ごと巻き戻す）
     */
    public function apply(
        ContentSchema $schema,
        string $type,
        int $contentId,
        array $map,
        bool $isCreate,
    ): AssignmentOutcome {
        $errors = [];

        // create は現在集合が必ず空なので、required 宣言の key が payload に無ければ成立しない
        if ($isCreate) {
            foreach ($schema->taxonomies as $declaration) {
                if ($declaration->required && ! array_key_exists($declaration->key, $map)) {
                    $errors[$this->path($declaration->key)][] = $this->requiredMessage($declaration);
                }
            }
        }

        if ($map === []) {
            $this->abortOnErrors($schema, $errors);

            return AssignmentOutcome::untouched();
        }

        // key 順に固定して、taxonomy の読み取り順を決定的にする
        ksort($map);

        $taxonomies = $this->loadTaxonomies(array_keys($map));
        $current = $this->currentAssignments($type, $contentId);

        /** @var array<string, list<string>> $desired taxonomy key → term ID */
        $desired = [];
        /** @var array<string, string> $slugInputs term ID → 入力された slug（再検証用） */
        $slugInputs = [];
        $warnings = [];

        foreach ($map as $key => $items) {
            $taxonomy = $taxonomies[$key];
            $ids = [];

            foreach ($items as $item) {
                $resolved = $this->resolveTerm($taxonomy, $item);

                if ($resolved['error'] !== null) {
                    $errors[$this->path($key)][] = $resolved['error'];

                    continue;
                }

                $term = $resolved['term'];

                if ($resolved['warning'] !== null) {
                    $warnings[] = $resolved['warning'];
                }

                if ($resolved['viaSlug']) {
                    $slugInputs[$term->id] = $item;
                }

                $ids[] = $term->id;
            }

            $unique = array_values(array_unique($ids));

            if (count($unique) !== count($ids)) {
                // 重複はエラーにせず正規化し、warning として返す（§K.2）
                $warnings[] = "分類「{$key}」に同じ項目が複数回指定されていたため 1 件にまとめました";
            }

            $desired[$key] = $unique;
        }

        $this->abortOnErrors($schema, $errors);

        // ── term 行を **ID の昇順**で lock する（taxonomy ごとに順番を変えない）──
        $locked = $this->lockTerms($current['termIds'], $desired);

        $counts = [];

        foreach ($map as $key => $_items) {
            $taxonomy = $taxonomies[$key];
            $declaration = $schema->taxonomy($key);
            $path = $this->path($key);
            $ids = $desired[$key];

            // lock 後の fresh state で再検証する（同時 archive / 削除 / rename を検出）
            foreach ($ids as $id) {
                $term = $locked[$id] ?? null;

                if ($term === null || $term->taxonomy_id !== $taxonomy->id) {
                    throw ContentWriteAbort::with(Result::conflict(
                        '分類の項目が同時に変更されたため保存できませんでした。もう一度お試しください。',
                    ));
                }

                if (isset($slugInputs[$id]) && $term->slug !== $slugInputs[$id]) {
                    throw ContentWriteAbort::with(Result::conflict(
                        '分類の項目のスラッグが同時に変更されたため保存できませんでした。もう一度お試しください。',
                    ));
                }
            }

            $currentIds = $current['byTaxonomy'][$taxonomy->id] ?? [];
            $added = array_values(array_diff($ids, $currentIds));
            $kept = array_values(array_intersect($ids, $currentIds));
            $removed = array_values(array_diff($currentIds, $ids));

            // **added に archived が含まれる場合だけ**拒否する（AS-1〜AS-7 / AR-3・AR-4）
            foreach ($added as $id) {
                if ($locked[$id]->isArchived()) {
                    $errors[$path][] = 'アーカイブ済みの分類は新しく設定できません'
                        .'（すでに設定されているものは、そのまま残せます）';
                }
            }

            // required / cardinality は **最終集合**に対して判定する（AS-8）
            if (! $declaration->multiple && count($ids) > 1) {
                $errors[$path][] = "{$this->labelOf($taxonomy, $declaration)}は 1 つだけ選べます";
            }

            if ($declaration->required && $ids === []) {
                $errors[$path][] = $this->requiredMessage($declaration, $taxonomy);
            }

            $counts[$key] = [
                'added' => count($added),
                'kept' => count($kept),
                'removed' => count($removed),
                '_added' => $added,
                '_kept' => $kept,
                '_removed' => $removed,
            ];
        }

        $this->abortOnErrors($schema, $errors);

        $this->persist($type, $contentId, $current['rows'], $desired, $counts);
        $this->verifyFinalState($schema, $type, $contentId, $taxonomies, $desired);

        $summary = [];

        foreach ($counts as $key => $c) {
            $summary[$key] = ['added' => $c['added'], 'kept' => $c['kept'], 'removed' => $c['removed']];
        }

        return AssignmentOutcome::of($summary, array_values(array_unique($warnings)));
    }

    /**
     * key → Taxonomy 行。未同期・managed_by 不一致は **conflict**（自動作成しない — PV-3 / PV-4）。
     *
     * @param  list<string>  $keys
     * @return array<string, Taxonomy>
     */
    private function loadTaxonomies(array $keys): array
    {
        $taxonomies = [];

        foreach ($keys as $key) {
            $found = $this->lookup->taxonomy($key);

            if ($found instanceof Result) {
                throw ContentWriteAbort::with($found);
            }

            $taxonomies[$key] = $found;
        }

        return $taxonomies;
    }

    /**
     * Content Entry 行を lock した**後**に読む現在集合。
     *
     * **assignment 側に行ロック（`FOR UPDATE`）を掛けない**（STAGE 3B CORRECTION / F-3B-3）。
     * MySQL では `where(content_type, content_id) … for update` が
     * **0 件でも `idx_assignment_entry` に gap lock** を掛けてしまい、
     * index 上の隙間を共有する**別の Content Entry** の更新まで待たせて
     * 誤った conflict を生む。`sharedLock()` も gap lock が残るため採用しない。
     *
     * ## それでも fresh state が保証される理由（MySQL REPEATABLE READ）
     *
     * - 呼び出し元が **同一 transaction の最初**に Content Entry 行を
     *   `lockForUpdate()` している（§G.6 の主 mutex）
     * - InnoDB の consistent read スナップショットは
     *   **最初の非ロック読み取り**で確定する。それがこのメソッドなので、
     *   スナップショットは **Content Entry 行ロックを取得した後**に張られる
     * - よって「ロック取得時点までに commit 済みの assignment」はすべて見える
     * - 自分の INSERT / UPDATE / DELETE は常に自分から見える
     * - 同じ Content Entry へ書く Application 経路は**すべてこの mutex を共有する**
     *
     * **Application contract を迂回する書き込み（直接 SQL 等）までは保証しない。**
     * その場合の最後の防御線は DB unique / FK RESTRICT と事後検証である。
     *
     * @return array{rows: array<string, TaxonomyTermAssignment>, termIds: list<string>, byTaxonomy: array<string, list<string>>}
     */
    private function currentAssignments(string $type, int $contentId): array
    {
        $rows = TaxonomyTermAssignment::query()
            ->where('content_type', $type)
            ->where('content_id', $contentId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $termIds = [];
        $keyed = [];

        foreach ($rows as $row) {
            $termIds[] = $row->term_id;
            $keyed[$row->term_id] = $row;
        }

        $terms = TaxonomyTerm::query()->whereIn('id', $termIds)->get();
        $byTaxonomy = [];

        foreach ($rows as $row) {
            $term = $terms->firstWhere('id', $row->term_id);

            if (! $term instanceof TaxonomyTerm) {
                // FK RESTRICT があるので通常起きない。起きたら黙って進めない
                throw ContentWriteAbort::with(Result::conflict(
                    '分類の割り当てを読み取れませんでした。安全のため保存を中止しました。',
                ));
            }

            $byTaxonomy[$term->taxonomy_id][] = $row->term_id;
        }

        return ['rows' => $keyed, 'termIds' => array_values(array_unique($termIds)), 'byTaxonomy' => $byTaxonomy];
    }

    /**
     * 現在集合と希望集合に関係する term 行を **ID 昇順**で lock する。
     *
     * taxonomy ごとに別々の順序で取らないことで deadlock を作りにくくする。
     *
     * @param  list<string>  $currentTermIds
     * @param  array<string, list<string>>  $desired
     * @return array<string, TaxonomyTerm>
     */
    private function lockTerms(array $currentTermIds, array $desired): array
    {
        $ids = $currentTermIds;

        foreach ($desired as $termIds) {
            foreach ($termIds as $id) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        sort($ids, SORT_STRING);

        $locked = [];

        foreach (
            TaxonomyTerm::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $term
        ) {
            $locked[$term->id] = $term;
        }

        return $locked;
    }

    /**
     * 入力 1 件を term へ解決する（§K.3）。
     *
     * - **ID を第一候補**にし、対象 taxonomy 内で解決する
     * - ID として解決できない要素だけ slug 解決を試す（K-4）
     * - slug は **対象 taxonomy 内・canonical 完全一致のみ**（K-1 / K-2）
     * - 取得後に**保存値とのバイト一致**を確認する。DB collation の
     *   大文字小文字非区別・PAD SPACE に判定を委ねない
     *
     * @return array{term: TaxonomyTerm|null, error: string|null, warning: string|null, viaSlug: bool}
     */
    private function resolveTerm(Taxonomy $taxonomy, string $input): array
    {
        if (TaxonomyId::isValid($input)) {
            $byId = TaxonomyTerm::query()->whereKey($input)->first();

            if ($byId instanceof TaxonomyTerm && $byId->taxonomy_id === $taxonomy->id) {
                $warning = $this->slugMatch($taxonomy, $input, exceptId: $byId->id) !== null
                    ? "「{$input}」は ID とスラッグの両方に一致したため、ID として解釈しました"
                    : null;

                return ['term' => $byId, 'error' => null, 'warning' => $warning, 'viaSlug' => false];
            }

            if ($byId instanceof TaxonomyTerm) {
                return [
                    'term' => null,
                    'error' => "「{$input}」は別の分類の項目です",
                    'warning' => null,
                    'viaSlug' => false,
                ];
            }
        }

        $bySlug = $this->slugMatch($taxonomy, $input);

        if ($bySlug === null) {
            return [
                'term' => null,
                'error' => "「{$input}」に一致する項目がありません（自動では作成しません）",
                'warning' => null,
                'viaSlug' => false,
            ];
        }

        return ['term' => $bySlug, 'error' => null, 'warning' => null, 'viaSlug' => true];
    }

    /** 対象 taxonomy 内でスラッグが**バイト一致**する term */
    private function slugMatch(Taxonomy $taxonomy, string $slug, ?string $exceptId = null): ?TaxonomyTerm
    {
        $query = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomy->id)
            ->where('slug', $slug);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        foreach ($query->get() as $term) {
            // collation が緩い（ci / PAD SPACE）場合に備えて、必ずバイト一致を確認する
            if ($term->slug === $slug) {
                return $term;
            }
        }

        return null;
    }

    /**
     * added だけ INSERT / removed だけ DELETE / kept は順序が変わったときだけ UPDATE。
     *
     * **kept を DELETE→INSERT で作り直さない**（AS-9: `created_at` が変わると監査が壊れる）。
     * `sort_order` は payload の並び順（0 始まり）を表す。
     * 並び順が変わらない kept 行には UPDATE も発行しない。
     *
     * @param  array<string, TaxonomyTermAssignment>  $currentRows
     * @param  array<string, list<string>>  $desired
     * @param  array<string, array{added: int, kept: int, removed: int, _added: list<string>, _kept: list<string>, _removed: list<string>}>  $counts
     */
    private function persist(string $type, int $contentId, array $currentRows, array $desired, array $counts): void
    {
        foreach ($counts as $key => $c) {
            if ($c['_removed'] !== []) {
                // **primary key で消す**（隙間ロックを作らないため）。
                //
                // `where(content_type, content_id)->whereIn(term_id, …)` だと、
                // optimiser が **外す件数と統計次第で** `uq_assignment_entry` から
                // `idx_assignment_entry` へ乗り換える（実測: 3 件で乗り換えた）。
                // 後者は範囲走査なので **X,GAP が無関係な content_id へ届き**、
                // その Entry の書き込みが lock timeout で失敗する（実測 1205 / 2 秒）。
                // 消す行は既に `$currentRows` に居るので、その id で消せば
                // **件数にも統計にも左右されず** PRIMARY の record lock だけになる。
                //
                // **依存**: id を集めてから消すまでの間に行が足されると消し漏らす。
                // ここは呼ぶ側が **Content Entry 行を `lockForUpdate()` 済み**の
                // 同一 transaction なので、その窓で誰も足せない（§G.6 の主 mutex）。
                // **この lock を外すと、ここが静かに壊れる。**
                $ids = [];

                foreach ($c['_removed'] as $termId) {
                    if (isset($currentRows[$termId])) {
                        $ids[] = $currentRows[$termId]->getKey();
                    }
                }

                if ($ids !== []) {
                    TaxonomyTermAssignment::query()->whereIn('id', $ids)->delete();
                }
            }

            foreach ($desired[$key] as $index => $termId) {
                if (in_array($termId, $c['_kept'], true)) {
                    $row = $currentRows[$termId];

                    if ($row->sort_order !== $index) {
                        $row->forceFill(['sort_order' => $index])->save();
                    }

                    continue;
                }

                TaxonomyTermAssignment::query()->create([
                    'term_id' => $termId,
                    'content_type' => $type,
                    'content_id' => $contentId,
                    'sort_order' => $index,
                ]);
            }
        }
    }

    /**
     * 書き込み後の**最終状態**を DB から読み直して検証する（§G.6 手順 7）。
     *
     * **ここでも assignment に `FOR UPDATE` を掛けない**（F-3B-3）。
     * 読むのは「Content Entry 行ロックを取得した後のスナップショット
     * ＋ 自分の書き込み」であり、**自分の INSERT / UPDATE / DELETE を含んだ
     * 最終状態**が必ず見える。gap lock で無関係な Content Entry を巻き込まない。
     *
     * SQLite では行ロック自体が no-op なので、
     * **transaction + unique / FK + この事後検証**へ縮退する。
     *
     * @param  array<string, Taxonomy>  $taxonomies
     * @param  array<string, list<string>>  $desired
     */
    private function verifyFinalState(
        ContentSchema $schema,
        string $type,
        int $contentId,
        array $taxonomies,
        array $desired,
    ): void {
        $rows = TaxonomyTermAssignment::query()
            ->where('content_type', $type)
            ->where('content_id', $contentId)
            ->get();

        $terms = TaxonomyTerm::query()->whereIn('id', $rows->pluck('term_id')->all())->get();
        $final = [];

        foreach ($rows as $row) {
            $term = $terms->firstWhere('id', $row->term_id);

            if ($term instanceof TaxonomyTerm) {
                $final[$term->taxonomy_id][] = $row->term_id;
            }
        }

        foreach ($taxonomies as $key => $taxonomy) {
            $actual = $final[$taxonomy->id] ?? [];
            $expected = $desired[$key];

            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);

            $declaration = $schema->taxonomy($key);

            if (
                $actual !== $expected
                || (! $declaration->multiple && count($actual) > 1)
                || ($declaration->required && $actual === [])
            ) {
                throw ContentWriteAbort::with(Result::conflict(
                    '分類の設定が同時に変更されたため保存できませんでした。もう一度お試しください。',
                ));
            }
        }
    }

    /** @param  array<string, list<string>>  $errors */
    private function abortOnErrors(ContentSchema $schema, array $errors): void
    {
        if ($errors === []) {
            return;
        }

        throw ContentWriteAbort::with(
            Result::validationError($errors, "{$schema->label}の分類を確認してください"),
        );
    }

    private function path(string $key): string
    {
        return ContentTaxonomyInput::RESPONSE_KEY.'.'.$key;
    }

    private function requiredMessage(TaxonomyDeclaration $declaration, ?Taxonomy $taxonomy = null): string
    {
        return $this->labelOf($taxonomy, $declaration).'を 1 つ以上選んでください';
    }

    private function labelOf(?Taxonomy $taxonomy, TaxonomyDeclaration $declaration): string
    {
        return $taxonomy->label ?? $declaration->key;
    }
}
