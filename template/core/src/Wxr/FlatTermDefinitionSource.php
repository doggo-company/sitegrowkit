<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use Generator;

/**
 * `<channel>` 直下にその分類の定義が **1 件も無い**とき、記事側の
 * `<category domain nicename>` から**平らな定義**を組み立てる
 * （WP 一周 2026-09-04・実物の WXR で踏んだ／SPEC §C.3 の追補）。
 *
 * ## 何が起きていたか
 *
 * 実物の書き出し（沼津の案件・87 記事・4 分類）には `<wp:category>` が
 * **1 件も無かった**。それでも記事側の `<category domain nicename>` には
 * **名前は入っている**（`nicename` 属性・CDATA が表示名、4 種）。
 * 無いのは**親子関係だけ**である。
 *
 * これまでは「定義ブロックが無い」を「名前が無い」と混同し、
 * 「分類の名前が入っていません。書き出し直してください」と止めていた。
 * **サイトが既に閉じている案件・前の制作会社が鍵を持っている案件では、
 * 書き出し直しができない。** 移行がいちばん要るのは、まさにその状況の人である
 * （メンテナ決定 2026-09-04）。
 *
 * ## 平らにしか作らない
 *
 * 親子は記事側の情報からは原理的に取れない（`WxrItem::$terms` のコメントと同じ）。
 * **推測で埋めない。** ここで組み立てる `WxrTermDefinition` の `parentSlug` は
 * 常に `null` である。
 *
 * ## 「一部だけ無い」とは区別する
 *
 * 対象になるのは、**その分類（taxonomy）に定義が 1 件も無いとき**だけである。
 * 定義が一部あって一部の割り当てだけ対応が無いのは、書き出しが壊れている・
 * 途中で切れているなど別の問題である可能性が高く、ここでは救わない
 * （`TermGraph::assign()` が従来どおり `term_definition_missing` を出して止める）。
 *
 * ## plan と apply が同じものを見る
 *
 * `PlanBuilder`・`ImportGraph`・`TermImporter` の 3 か所がこのクラスを通す。
 * 別々に組み直すと、**plan が通した plan と apply が実際に作るものが食い違う**。
 *
 * ## id は本物と絶対に衝突しない形にする
 *
 * WXR の `wp:term_id` は**数字だけ**（`WxrReader::ID_PATTERN` — 先頭が `1`-`9`）。
 * ここで作る id は先頭に文字を置くことで、本物の `wp:term_id` とは
 * どの分類の間でも重ならない形にする（`TermGraph` の重複検査は分類をまたいで見る）。
 *
 * ## メモリは 1 分類ぶんの名前だけ
 *
 * 溜めるのは「まだ定義の無い分類」の `nicename => name` だけである。
 * 定義が揃っている分類は最初のループの時点で除外するので、
 * ここに残る名前は実物なら 4 種のように小さい。
 */
final class FlatTermDefinitionSource
{
    /**
     * 本物の `wp:term_id`（数字だけ）とは絶対に重ならないよう、先頭を文字にする。
     */
    private const ID_PREFIX = 'flat-';

    /**
     * `$definedTaxonomies` に無い分類だけを対象に、記事側の割り当てから
     * 平らな `WxrTermDefinition` を組み立てて返す。
     *
     * **同じ (分類, nicename) は 1 件にまとめる**（最初に出てきた名前を採る）。
     * まとめずに件数ぶん返すと、同じ id が `TermGraph::define()` へ何度も渡り、
     * 「同じ id が 2 回出てきた」（`TermIdDuplicate`）として止まってしまう。
     *
     * 🔴 **`$definedTaxonomies` も割り当ての `domain` も、読み替えた後の受け皿
     * （`--taxonomy` を通した後の名前）で見る。** 片方だけ元の名前で見ると、
     * `--taxonomy=category:news_category` を付けたときに、割り当ての側は
     * `news_category` を指し、定義の側だけ `category` を指す形になり、
     * **定義があるのに「無い」と誤判定して衝突を起こす**
     * （実測 2026-09-04・`RollbackWxrTest` で踏んだ）。
     * 呼び手（`PlanBuilder`・`ImportGraph`・`TermImporter`）が
     * `$definedTaxonomies` を作るときも、この読み替えと同じ規則を使うこと。
     *
     * @param  array<string, true>  $definedTaxonomies  読み替えた後の受け皿のうち、`<channel>` 直下に定義があるもの
     * @return Generator<int, WxrTermDefinition>
     */
    public function forUndefinedTaxonomies(WxrReader $reader, ImportOptions $options, array $definedTaxonomies): Generator
    {
        /** @var array<string, array<string, string>> 分類（読み替え前の名前）=> nicename => 表示名 */
        $flat = [];

        foreach ($reader->items() as $item) {
            if ($item->postType === ItemTarget::PAGE_TYPE) {
                /*
                 * ページの割り当ては入れない（Page は分類を持たない・release-20260909）。
                 * ここで拾うと、ページにしか付いていない分類のために
                 * 定義を作れと止められる。件数は plan が `page_terms_dropped` で出す。
                 */
                continue;
            }

            foreach ($item->terms as $assignment) {
                $sourceTaxonomy = $assignment['domain'];
                $target = $options->taxonomies[$sourceTaxonomy] ?? $sourceTaxonomy;

                if (isset($definedTaxonomies[$target])) {
                    // 定義がある分類は、従来どおり TermGraph::assign() に判定させる
                    continue;
                }

                $nicename = $assignment['nicename'];

                /*
                 * **読み替え前の名前で溜める。** 定義と同じ `->taxonomy` の意味
                 * （読み替え前）で返すことで、呼び手はいつもどおり
                 * `$options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy`
                 * を通せばよい。ここだけ読み替え後の値を持たせると、呼び手が
                 * 二重に読み替えてしまう。
                 */
                $flat[$sourceTaxonomy][$nicename] ??= $assignment['name'];
            }
        }

        foreach ($flat as $sourceTaxonomy => $terms) {
            foreach ($terms as $nicename => $name) {
                yield new WxrTermDefinition(
                    termId: self::syntheticId($sourceTaxonomy, $nicename),
                    taxonomy: $sourceTaxonomy,
                    slug: $nicename,
                    name: $name,
                    parentSlug: null,
                );
            }
        }
    }

    /**
     * 決定的に作る（同じ WXR なら毎回同じ id）。
     *
     * 分類を要約の元へ含めるので、別の分類で同じ nicename が使われていても
     * 別の id になる（`TermGraph` の重複検査は分類をまたいで見るため）。
     */
    private static function syntheticId(string $taxonomy, string $nicename): string
    {
        return self::ID_PREFIX.substr(hash('sha256', $taxonomy."\0".$nicename), 0, 40);
    }
}
