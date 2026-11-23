<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * PageDocument の taxonomy filter を解くための read-only な索引（Phase 9.3B / #22・SPEC §O）。
 *
 * ## なぜ 1 クラスにまとめるか
 *
 * 同じ filter を **保存時検証**（`DocumentValidator`）と **描画**（`BindingResolver`）が読む。
 * 「どの形なら正しいか」「その term は本当にその分類のものか」を 2 か所に書くと、
 * かならずどちらかがずれて「保存できるのに描画されない」が生まれる。
 * 判断の中身をここ 1 つに置き、両方が同じ結論を使う。
 *
 * ## 責務
 *
 * **読むだけ。** DB へ書かず、行も作らない。状態も持たない（キャッシュしない）ので、
 * 長寿命プロセスで前の呼び出しの結果が次へ漏れることがない。
 * 呼び出し側が「1 document につき 1 回」のように寿命を決める。
 */
final class TaxonomyFilterIndex
{
    /**
     * key と term ID をまとめて引く。**多くても 2 クエリ**、対象が無ければ 0 クエリ。
     *
     * archived な term も引く。archive は「新しく付けられない」であって、
     * 既にある filter を壊す操作ではない（AR-5）。
     *
     * @param  list<string>  $keys
     * @param  list<string>  $termIds
     * @return array{taxonomies: array<string, string>, terms: array<string, string>}
     *                                                                                key → taxonomy ID / term ID → 所属 taxonomy ID
     */
    public function build(array $keys, array $termIds): array
    {
        if ($keys === []) {
            return ['taxonomies' => [], 'terms' => []];
        }

        $taxonomies = [];

        foreach (Taxonomy::query()->whereIn('key', $keys)->get() as $taxonomy) {
            $taxonomies[$taxonomy->key] = $taxonomy->id;
        }

        $terms = [];

        if ($termIds !== []) {
            foreach (TaxonomyTerm::query()->whereIn('id', $termIds)->get() as $term) {
                $terms[$term->id] = $term->taxonomy_id;
            }
        }

        return ['taxonomies' => $taxonomies, 'terms' => $terms];
    }

    /**
     * 描画に渡す AND グループへ畳む。
     *
     * 返すのは「term ID の並び」の並び。**外側が AND、内側が OR**。
     * `null` は「この binding は何にも一致しない」という意味で、
     * 呼び出し側は 0 件にする（絞り込み無しとして全件を出さない — fail closed）。
     *
     * 形の判断は `DocumentValidator::validateTaxonomyFilter()` と同じ:
     * `taxonomy` は空でない文字列、`terms` は空でない文字列の並び。
     * **key が合っているだけで所属を信じない**ので、ここでも ID で持ち主を確かめる。
     *
     * **1 つでも不正な term ID があれば group ごと捨てる**（部分的に通さない）。
     * 検証は「1 つでも不正なら error」なので、描画だけ正しい ID を拾って表示すると、
     * 保存できない document が公開ページには出ている、という食い違いになる。
     *
     * @param  mixed  $filters  binding.filters（形は信用しない）
     * @return list<list<string>>|null
     */
    public function groups(mixed $filters): ?array
    {
        if ($filters === null) {
            return []; // filters が無い binding。分類での絞り込みも無い
        }

        if (! is_array($filters) || ! array_is_list($filters)) {
            return null; // 読み取れない形。全件を出さない
        }

        $wanted = [];

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                return null; // 要素の形が読めない。絞り込み無しとして扱わない
            }

            if (! array_key_exists('taxonomy', $filter)) {
                // 通常の field filter。**ここでも「読めるか」だけは見る**。
                // field が無い / 空 / 文字列でない filter は検証側でも error なので、
                // 描画だけ「絞り込み無し」として全件出す、という食い違いを作らない
                $field = $filter['field'] ?? null;

                if (! is_string($field) || $field === '') {
                    return null;
                }

                continue;
            }

            $terms = $this->termsOf($filter);

            if ($terms === null) {
                return null;
            }

            $wanted[] = [$filter['taxonomy'], $terms];
        }

        if ($wanted === []) {
            return [];
        }

        $index = $this->build(
            array_values(array_unique(array_column($wanted, 0))),
            array_values(array_unique(array_merge(...array_column($wanted, 1)))),
        );

        $groups = [];

        foreach ($wanted as [$key, $terms]) {
            $taxonomyId = $index['taxonomies'][$key] ?? null;

            if ($taxonomyId === null) {
                return null; // 存在しない分類で絞れば、当たるものは無い
            }

            foreach ($terms as $term) {
                // **1 つでも不正なら group ごと捨てる。**
                // 正しい ID が混ざっているからと通すと、保存時は error なのに
                // 描画は出る、という食い違いが生まれる（published / import 済み document で起きる）
                if (($index['terms'][$term] ?? null) !== $taxonomyId) {
                    return null;
                }
            }

            $groups[] = $terms;
        }

        return $groups;
    }

    /**
     * 1 filter の `terms` を取り出す。形が違えば null（＝一致しようがない）。
     *
     * @param  array<string, mixed>  $filter
     * @return list<string>|null
     */
    private function termsOf(array $filter): ?array
    {
        $key = $filter['taxonomy'];
        $terms = $filter['terms'] ?? null;

        if (! is_string($key) || $key === '' || ! is_array($terms) || ! array_is_list($terms) || $terms === []) {
            return null;
        }

        $ids = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                return null;
            }

            $ids[] = $term;
        }

        return array_values(array_unique($ids));
    }
}
