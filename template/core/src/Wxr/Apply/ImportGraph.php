<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Wxr\FlatTermDefinitionSource;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\WxrReader;

/**
 * `apply` が使う分類項目の関係を、**1 回だけ組み立てる**
 * （Phase 9.14 / STAGE 27D-5a・SPEC §C.3）。
 *
 * ## plan の内部を持ち回らない
 *
 * `plan` が確かめた関係と同じものになる — 元は同じファイルで、差し替わっていないことは
 * plan hash の照合が既に保証している（§D.1）。**同じ入力から同じ手順で組み直す**方が、
 * どちらが正かで迷わずに済む。
 *
 * ## 2 つの importer が同じものを見る
 *
 * 分類項目を作る側（`TermImporter`）と、記事を結び付ける側（`ContentImporter`）は
 * **同じ代表**を見なければならない。別々に組むと、ファイルの走査が 1 周増えるうえに、
 * **項目を作った代表と記事が指す代表が食い違う**余地が残る。
 *
 * 順（分類項目 → 記事）は元々必須なので、外から渡す形は実態に正直である。
 */
final class ImportGraph
{
    public function __construct(
        private readonly FlatTermDefinitionSource $flatTerms,
    ) {}

    /**
     * `plan` と同じ手順で関係を組み直し、**封をして**返す。
     *
     * 読み替えも `plan` と同じ規則で行う（`--taxonomy`）。片方だけ元の名前のままだと、
     * **同じ項目が 2 つの受け皿に分かれる**。
     *
     * **`FlatTermDefinitionSource` も plan と同じ手順で通す**（WP 一周 2026-09-04）。
     * 定義が 1 件も無い分類を plan が平らに救ったのに、ここで real の定義だけを
     * 読み直すと、`TermImporter` が「作れると言われたのに作れない」で落ちる。
     */
    public function of(WxrReader $reader, ImportOptions $options): TermGraph
    {
        $graph = new TermGraph;

        // 🔴 target で持つ（`PlanBuilder::scan()` と同じ規則 — 理由もそちらと同じ）
        /** @var array<string, true> 読み替えた後の受け皿のうち、`<channel>` 直下に定義があるもの */
        $definedTaxonomies = [];

        foreach ($reader->termDefinitions() as $definition) {
            $target = $options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy;

            $definedTaxonomies[$target] = true;

            $graph->define($target, $definition);
        }

        if ($options->flattenMissingTermDefinitions) {
            foreach ($this->flatTerms->forUndefinedTaxonomies($reader, $options, $definedTaxonomies) as $definition) {
                $graph->define(
                    $options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy,
                    $definition,
                );
            }
        }

        /*
         * 理由は `plan` が全部出し済みなので捨てる。**同じ理由を二度数えない。**
         * 止める理由があれば plan が止めているので、ここへは来ない。
         */
        $graph->seal(new PlanFindings);

        return $graph;
    }
}
