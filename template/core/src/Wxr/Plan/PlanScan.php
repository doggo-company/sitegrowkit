<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\WxrTermDefinition;

/**
 * WXR を読む間に集めるもの（Phase 9.14 / STAGE 27C）。
 *
 * 読み取りは 2 つの走査からなる — `<channel>` 直下の定義と、item である。
 *
 * **中身は溜めない。** 十年分の記事を配列に持てばメモリが持たないので、
 * ここに残るのは「どの種類が実在したか」「どこへ入れるか」という
 * 小さな対応表と、畳んだ行動と、**件数に上限のある**分類項目の関係だけである。
 */
final class PlanScan
{
    public readonly PlanActionDigest $actions;

    /**
     * 分類項目の親子と重複。
     *
     * 定義の走査を終えてから封をするので、item を読む間は
     * **割り当てを 1 件も抱えずに照合できる**。
     * 保つ件数は `TermGraph::MAX_DEFINITIONS` で頭打ちになる。
     */
    public readonly TermGraph $terms;

    /** @var array<string, true> WXR に実在した post_type */
    private array $sourceTypes = [];

    /** @var array<string, true> WXR に実在した分類 */
    private array $sourceTaxonomies = [];

    /** @var array<string, string> 実在した post_type => 採用した取り込み先 */
    private array $adopted = [];

    /** @var array<string, array<string, true>> 取り込み先の型 => その型で使う分類 */
    private array $typeTaxonomies = [];

    /** @var array<string, true> 関係する分類すべて（どの item も使っていない定義を含む） */
    private array $taxonomies = [];

    /** @var array<string, true> コメントがあった取り込み先 */
    private array $comments = [];

    /**
     * **平らな項目を実際に 1 件でも作ったか**（`FlatTermDefinitionSource`）。
     *
     * `--strict-term-definitions` を付けたか・付けていないかだけでなく、
     * **実際に効いたか**を見る。分類に定義が揃っている WXR では
     * このフラグは何も変えないので、hash には載せない（`effective()`）。
     */
    private bool $usedFlatTermDefinition = false;

    /**
     * 台帳を引く順番待ちの鍵。
     *
     * **溜めるのは鍵だけ**で、本文は 1 文字も持たない。
     * ここに置くのは、`PlanScan` が 1 回の plan につき 1 つだからである。
     * 組み立てる側に持たせると、**前の plan の残りが次の plan へ混ざり得る**。
     *
     * @var list<LedgerKey>
     */
    private array $window = [];

    /**
     * 窓と**同じ並び**の「既にある対象」。
     *
     * `null` は「作らないと無い」。`alias` は「同じ取り込みの代表が作る」で、
     * どちらも**この取り込みが作ったわけではない**という点では同じだが、
     * 対象の id が plan の時点で分かるかどうかが違う。
     *
     * @var list<string|null>
     */
    private array $existing = [];

    public function __construct()
    {
        $this->actions = new PlanActionDigest;
        $this->terms = new TermGraph;
    }

    public function useSourceType(string $sourceType): void
    {
        $this->sourceTypes[$sourceType] = true;
    }

    public function useSourceTaxonomy(string $domain): void
    {
        $this->sourceTaxonomies[$domain] = true;
    }

    /**
     * `<channel>` 直下の定義。**どの item も使っていなくても落とさない**（§C.3）。
     *
     * **対応先へ読み替えてから入れる。** 読み替えずに入れると、
     * `--taxonomy=category:blog_category` を付けたときに、
     * 割り当ての側は `blog_category` を指し、定義の側だけ `category` を指す。
     * 存在しない受け皿を探して止まるか、同じ分類を 2 つ数えることになる。
     */
    public function addTermDefinition(WxrTermDefinition $definition, string $target): void
    {
        $this->sourceTaxonomies[$definition->taxonomy] = true;
        $this->taxonomies[$target] = true;

        $this->terms->define($target, $definition);
    }

    /** `FlatTermDefinitionSource` が組み立てた定義を 1 件足した、と印す */
    public function useFlatTermDefinition(): void
    {
        $this->usedFlatTermDefinition = true;
    }

    public function useTarget(string $sourceType, string $target): void
    {
        $this->adopted[$sourceType] = $target;
        $this->typeTaxonomies[$target] ??= [];
    }

    public function useTaxonomy(string $key, string $target): void
    {
        $this->taxonomies[$key] = true;
        $this->typeTaxonomies[$target][$key] = true;
    }

    public function commentsFor(string $target): void
    {
        $this->comments[$target] = true;
    }

    /**
     * 実際に使った対応だけを載せた指定。
     *
     * **書き方ではなく意味で決める。** `--type` を書かずに post→post と
     * 解決したときと、`--type=post:post` と明示したときは同じ取り込みである。
     * 未使用の指定は報告するが、ここには載せない（hash を動かさない）。
     */
    public function effective(ImportOptions $options): ImportOptions
    {
        $types = [];

        foreach ($this->adopted as $source => $target) {
            $types[] = $source.':'.$target;
        }

        $taxonomies = [];

        foreach (array_keys($this->sourceTaxonomies) as $domain) {
            $target = $options->taxonomies[$domain] ?? $domain;
            $taxonomies[] = $domain.':'.$target;
        }

        /*
         * `--media` は**取りに行く候補が実在したときだけ**意味がある。
         * 添付が 1 件も無い WXR で有無を hash に載せると、
         * 何も変わらないのに再 plan を求めることになる。
         *
         * `--strict-term-definitions` も同じ考え方: **実際に平らな項目を
         * 作ったときだけ**意味がある。分類の定義が揃っている WXR では、
         * このフラグの有無で作るものは何も変わらない。
         */
        return ImportOptions::fromCli(
            $types,
            $taxonomies,
            $options->media && $this->hasMediaCandidates(),
            $options->flattenMissingTermDefinitions && $this->usedFlatTermDefinition,
        );
    }

    /** 台帳を引く順番へ 1 件足す */
    public function holdForLedger(LedgerKey $key, ?string $existingTargetId = null): void
    {
        $this->window[] = $key;
        $this->existing[] = $existingTargetId;
    }

    public function windowIsFull(int $size): bool
    {
        return count($this->window) >= $size;
    }

    /**
     * 溜めた鍵を**溜めた順のまま**取り出し、窓を空にする。
     *
     * 取り出しと同時に空にするのは、途中で例外が出たときに
     * **同じ窓を二度積まない**ようにするためである。
     *
     * @return array{0: list<LedgerKey>, 1: list<string|null>}
     */
    public function takeWindow(): array
    {
        $keys = $this->window;
        $existing = $this->existing;

        $this->window = [];
        $this->existing = [];

        return [$keys, $existing];
    }

    /** @return array<string, list<string>> */
    public function typeTaxonomies(): array
    {
        $map = [];

        foreach ($this->typeTaxonomies as $target => $keys) {
            $map[$target] = array_keys($keys);
        }

        return $map;
    }

    /** @return list<string> */
    public function taxonomyKeys(): array
    {
        return array_keys($this->taxonomies);
    }

    /** @return list<string> */
    public function commentsRelevantFor(): array
    {
        return array_keys($this->comments);
    }

    /**
     * 指定されたのに WXR へ 1 件も無かった post_type。
     *
     * @param  array<string, string>  $requested
     * @return list<string>
     */
    public function unusedTypeMappings(array $requested = []): array
    {
        return array_values(array_diff(array_keys($requested), array_keys($this->sourceTypes)));
    }

    /**
     * @param  array<string, string>  $requested
     * @return list<string>
     */
    public function unusedTaxonomyMappings(array $requested = []): array
    {
        return array_values(array_diff(array_keys($requested), array_keys($this->sourceTaxonomies)));
    }

    private function hasMediaCandidates(): bool
    {
        return array_key_exists('attachment', $this->sourceTypes);
    }
}
