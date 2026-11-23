<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Term 階層の不変条件（Phase 9.3B / STAGE 2A・SPEC §D）。
 *
 * **root を depth 1 と数える。**
 * `DocumentValidator::MAX_DEPTH` が root を 0 と数えるのと**意図的に違う**ので、
 * 両方の数え方が混ざらないようここに明記する（§D.2）。
 *
 * ```text
 * depth 1: お知らせ            (parent_id = null)
 * depth 2:   └ プレスリリース   (parent_id = お知らせ)
 * depth 3:       └ 製品発表     (parent_id = プレスリリース)
 * depth 4:           └ ✗ 不可
 * ```
 *
 * `depth` は **導出値なので DB へ保存しない**（§D.4）。上限は domain policy として持つ。
 *
 * **既存データ破損を正常値として扱わない**（STAGE 2A CORRECTION F）。
 * 親が見つからない・循環している・taxonomy をまたいでいる場合は
 * `TaxonomyDataCorruptionException` を投げ、`validateParent()` は
 * `HierarchyCheck::corrupt()` を返す。**途中の depth を返して黙って続行しない。**
 */
final class TermHierarchy
{
    /** V1 の上限。DB 属性ではなく domain policy（§D.4） */
    public const MAX_TERM_DEPTH = 3;

    /**
     * root からの深さ。`parent_id = null` は **1**。
     *
     * @throws TaxonomyDataCorruptionException 親欠落 / 循環 / taxonomy またぎ / DB エラー
     */
    public static function depthOf(TaxonomyTerm $term): int
    {
        $depth = 1;
        $seen = [$term->id => true];
        $current = $term;

        try {
            while ($current->parent_id !== null) {
                if (isset($seen[$current->parent_id])) {
                    throw TaxonomyDataCorruptionException::cycle($term->id);
                }

                $parent = TaxonomyTerm::query()->find($current->parent_id);

                if ($parent === null) {
                    // 親が消えている = FK RESTRICT を迂回した破損。黙って root 扱いにしない
                    throw TaxonomyDataCorruptionException::missingParent(
                        $current->id,
                        $current->parent_id,
                    );
                }

                if ($parent->taxonomy_id !== $current->taxonomy_id) {
                    throw TaxonomyDataCorruptionException::crossTaxonomy($current->id, $parent->id);
                }

                $seen[$parent->id] = true;
                $current = $parent;
                $depth++;

                // seen による循環検出をすり抜ける形の破損に対する最後の歯止め
                if ($depth > self::MAX_TERM_DEPTH + 1) {
                    throw TaxonomyDataCorruptionException::cycle($term->id);
                }
            }
        } catch (QueryException $e) {
            report($e);

            throw TaxonomyDataCorruptionException::unreadable();
        }

        return $depth;
    }

    /**
     * 部分木の高さ。自分だけなら 1、子がいれば 2 …（D-5 の判定に使う）。
     *
     * 「移動対象そのものが上限に収まるか」だけを見ると
     * **子孫が上限を超える移動を通してしまう**ため、部分木の高さを足して判定する。
     *
     * @throws TaxonomyDataCorruptionException 子孫循環 / taxonomy またぎ / DB エラー
     */
    public static function subtreeHeight(string $termId): int
    {
        return count(self::levels($termId));
    }

    /**
     * 自分自身と全子孫の ID（D-4 の循環判定に使う）。
     *
     * @return list<string>
     *
     * @throws TaxonomyDataCorruptionException
     */
    public static function descendantIds(string $termId): array
    {
        return array_merge(...self::levels($termId));
    }

    /**
     * 部分木を 1 段ずつ返す（`[[root], [子…], [孫…]]`）。
     *
     * 同じ ID が 2 回現れたら **子孫循環**として破損扱いにする。
     * term は親を 1 つしか持てないため、正常なデータでは再訪が起きない。
     *
     * @return non-empty-list<list<string>>
     *
     * @throws TaxonomyDataCorruptionException
     */
    private static function levels(string $termId): array
    {
        $root = TaxonomyTerm::query()->find($termId);
        $taxonomyId = $root?->taxonomy_id;

        $levels = [[$termId]];
        $frontier = [$termId];
        $seen = [$termId => true];

        try {
            while (true) {
                /** @var list<array{id: string, taxonomy_id: string}> $children */
                $children = TaxonomyTerm::query()
                    ->whereIn('parent_id', $frontier)
                    ->get(['id', 'taxonomy_id'])
                    ->map(static fn (TaxonomyTerm $t): array => [
                        'id' => $t->id,
                        'taxonomy_id' => $t->taxonomy_id,
                    ])
                    ->all();

                $next = [];

                foreach ($children as $child) {
                    if (isset($seen[$child['id']])) {
                        throw TaxonomyDataCorruptionException::cycle($termId);
                    }

                    if ($taxonomyId !== null && $child['taxonomy_id'] !== $taxonomyId) {
                        throw TaxonomyDataCorruptionException::crossTaxonomy($child['id'], $termId);
                    }

                    $seen[$child['id']] = true;
                    $next[] = $child['id'];
                }

                if ($next === []) {
                    return $levels;
                }

                $levels[] = $next;
                $frontier = $next;

                if (count($levels) > self::MAX_TERM_DEPTH + 1) {
                    throw TaxonomyDataCorruptionException::cycle($termId);
                }
            }
        } catch (QueryException $e) {
            report($e);

            throw TaxonomyDataCorruptionException::unreadable();
        }
    }

    /**
     * 既存 term の部分木が健全かを確認する（CORRECTION #2 C）。
     *
     * `validateParent()` が内部で必ず呼ぶので、Application Service は
     * **`validateParent()` を 1 回呼べば足りる**。個別に呼ぶこともできる。
     *
     * 検出するもの:
     * - 対象 term が DB に存在しない
     * - descendant cycle
     * - taxonomy をまたぐ子
     * - 部分木を読み取れない DB 障害
     *
     * `$term` が null（新規作成）なら検査対象の部分木がまだ無いので ok。
     */
    public static function validateSubtreeIntegrity(?TaxonomyTerm $term): HierarchyCheck
    {
        if ($term === null) {
            return HierarchyCheck::ok();
        }

        try {
            $exists = TaxonomyTerm::query()->whereKey($term->id)->exists();
        } catch (QueryException $e) {
            report($e);

            return HierarchyCheck::corrupt(TaxonomyDataCorruptionException::unreadable()->getMessage());
        }

        if (! $exists) {
            return HierarchyCheck::corrupt(
                "項目 [{$term->id}] がデータベースに存在しません。データが壊れている可能性があります。"
            );
        }

        try {
            // 部分木を最後まで降りる。cycle / taxonomy またぎ / DB 障害はここで露見する
            self::descendantIds($term->id);
        } catch (TaxonomyDataCorruptionException $e) {
            return HierarchyCheck::corrupt($e->getMessage());
        }

        return HierarchyCheck::ok();
    }

    /**
     * 親を設定・変更してよいかを検証する（D-1〜D-5・AR-11）。
     *
     * **これ 1 回で次をすべて判断できる**（CORRECTION #2 C の公開契約）:
     * 親指定の validation / 自己参照 / cycle / taxonomy またぎ /
     * 部分木込みの最大 depth / 既存データ破損 / DB 読み取り不能。
     *
     * `$term` が null のときは **新規作成**の検証（まだ ID が無いので D-3 / D-4 は不要）。
     *
     * 戻り値は 3 状態（正常 / 利用者入力エラー / 既存データ破損）を区別する。
     */
    public static function validateParent(
        Taxonomy $taxonomy,
        ?TaxonomyTerm $term,
        ?string $parentId,
    ): HierarchyCheck {
        // **親指定の前に**既存 term の健全性を確かめる。
        // root へ移す（parentId = null）操作や、階層なし taxonomy でも
        // 既存の破損を黙って ok にしない
        $integrity = self::validateSubtreeIntegrity($term);

        if (! $integrity->isOk()) {
            return $integrity;
        }

        // D-1: 階層なしの taxonomy では parent を持てない
        if (! $taxonomy->hierarchical) {
            return $parentId === null
                ? HierarchyCheck::ok()
                : HierarchyCheck::invalid(['この分類は階層を使わない設定のため、親を指定できません']);
        }

        if ($parentId === null) {
            return HierarchyCheck::ok();
        }

        try {
            $parent = TaxonomyTerm::query()->find($parentId);
        } catch (QueryException $e) {
            report($e);

            return HierarchyCheck::corrupt(TaxonomyDataCorruptionException::unreadable()->getMessage());
        }

        if ($parent === null) {
            return HierarchyCheck::invalid(['指定された親の項目が見つかりません']);
        }

        // D-2: 同一 taxonomy 内のみ
        if ($parent->taxonomy_id !== $taxonomy->id) {
            return HierarchyCheck::invalid(['親には同じ分類の項目だけを指定できます']);
        }

        // AR-11: archived term を **新しく**親に選べない（既存の親子関係は維持される）
        if ($parent->isArchived() && ($term === null || $term->parent_id !== $parentId)) {
            return HierarchyCheck::invalid(['アーカイブ済みの項目は新しい親として選べません']);
        }

        try {
            if ($term !== null) {
                // D-3: 自己参照
                if ($parent->id === $term->id) {
                    return HierarchyCheck::invalid(['自分自身を親にはできません']);
                }

                // D-4: 循環（自分の子孫を親にする）
                if (in_array($parent->id, self::descendantIds($term->id), true)) {
                    return HierarchyCheck::invalid(['自分の下にある項目を親にはできません']);
                }
            }

            // D-5: 移動対象だけでなく **部分木全体**が上限に収まるか
            $parentDepth = self::depthOf($parent);
            $height = $term === null ? 1 : self::subtreeHeight($term->id);
        } catch (TaxonomyDataCorruptionException $e) {
            // 既存データ破損。利用者入力の問題ではないので安全側で拒否する
            return HierarchyCheck::corrupt($e->getMessage());
        }

        $deepest = $parentDepth + $height;

        if ($deepest > self::MAX_TERM_DEPTH) {
            return HierarchyCheck::invalid([sprintf(
                '階層が深くなりすぎます（最大 %d 階層まで）。この移動では %d 階層になります',
                self::MAX_TERM_DEPTH,
                $deepest,
            )]);
        }

        return HierarchyCheck::ok();
    }

    /**
     * archived な親を持つ active な子（HR-4 / HR-6 の表示判断に使う）。
     *
     * **`parent_id` を null に潰さない**ため、表示側が「孤児 root」として
     * 誤って描画しないよう、呼び出し側へ事実だけを返す。
     *
     * @return Collection<int, TaxonomyTerm>
     */
    public static function activeChildrenOfArchivedParents(string $taxonomyId): Collection
    {
        $archivedIds = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->whereNotNull('archived_at')
            ->pluck('id');

        if ($archivedIds->isEmpty()) {
            /** @var Collection<int, TaxonomyTerm> $empty */
            $empty = new Collection;

            return $empty;
        }

        return TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->whereNull('archived_at')
            ->whereIn('parent_id', $archivedIds)
            ->orderBy('sort_order')
            ->get();
    }
}
