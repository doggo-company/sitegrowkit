<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Throwable;

/**
 * R-3 / R-4: PageDocument からの参照（Phase 9.3B / STAGE 2A・SPEC §I.3）。
 *
 * **二段階で判定する。**
 *
 *  ① 候補抽出（安価・DB 側）
 *     `page_versions.document LIKE '%<needle>%'` で候補行だけを絞る。
 *     JSON 関数に依存しないため MySQL 5.7 / 8.4 / SQLite すべてで動く。
 *     **この結果は候補であって参照ではない。**
 *
 *  ② 構造確認（確定・PHP 側）
 *     候補の document を decode し、木を降りて
 *     `binding.filters[].taxonomy` と `binding.filters[].terms[]` だけを見る。
 *     **本文・HTML・heading・任意 props に同じ文字列があるだけでは参照扱いにしない。**
 *
 * **term 参照は AND 条件**（CORRECTION A）:
 *   `filters[].taxonomy` が対象 term の taxonomy key と完全一致 **かつ**
 *   `filters[].terms[]` に term ID が文字列として完全一致。
 *
 * **走査は途中で止めない**（CORRECTION #2 B）:
 *   一致を見つけても document 全体を検証しきる。後半に壊れた構造があれば
 *   参照を数えたうえで「確定できなかった」も併せて返す。
 *
 * draft / published（R-3）と過去の版（R-4）を区別せず `page_versions` を**全件**対象にする。
 */
final class PageDocumentReferenceProvider implements ReferenceProvider
{
    /** 一度にメモリへ載せる候補行数 */
    private const CHUNK_SIZE = 200;

    private const SAMPLE_LIMIT = 5;

    /**
     * @param  int  $timeBudgetMs  構造確認に使える時間。超えたら **undetermined**（fail closed）。
     *                             「件数が多いから」を恒久的な削除不能の理由にしないため、
     *                             上限は件数ではなく時間で持つ（§I.3）。
     */
    public function __construct(private readonly int $timeBudgetMs = 5000) {}

    public function kind(): string
    {
        return 'page_document';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        // A: taxonomy を確定できないと AND 条件を評価できない。
        // 「参照ゼロ」にせず fail closed にする
        try {
            $taxonomy = $term->taxonomy()->first();
        } catch (Throwable $e) {
            report($e);

            return ReferenceProbe::undetermined(
                'ページからの参照を調べられませんでした（分類を読み取れません）。',
            );
        }

        if (! $taxonomy instanceof Taxonomy) {
            return ReferenceProbe::undetermined(
                'この項目が属する分類を特定できないため、ページからの参照を確認できませんでした。',
            );
        }

        $key = $taxonomy->key;

        // 型検証は walker が済ませているので、ここは純粋な一致判定だけ
        return $this->scan(
            needle: $term->id,
            matches: static fn (array $filter): bool => ($filter['taxonomy'] ?? null) === $key
                && in_array($term->id, $filter['terms'] ?? [], true),
        );
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        // §O: filter の `taxonomy` は key を併記する。document に載るのは key
        return $this->scan(
            needle: $taxonomy->key,
            matches: static fn (array $filter): bool => ($filter['taxonomy'] ?? null) === $taxonomy->key,
        );
    }

    /**
     * @param  callable(array<string, mixed>): bool  $matches
     */
    private function scan(string $needle, callable $matches): ReferenceProbe
    {
        // B: needle は ULID（TaxonomyId::PATTERN）か taxonomy key
        // （TaxonomyDefinition::KEY_PATTERN = /^[a-z][a-z0-9_]*$/）に限られるため、
        // LIKE のメタ文字は `_` しか現れない。`_` は **候補を広げるだけ**で
        // false negative を生まないので **エスケープしない**（構造確認が最終判定）。
        //
        // `%` や `\` はエスケープ方式が driver ごとに違い（SQLite は既定の
        // ESCAPE を持たない）、誤ると false negative = 削除の誤許可になる。
        // 想定外の文字が来たら候補抽出をあきらめ fail closed にする。
        if (preg_match('/[%\\\\]/', $needle) === 1) {
            return ReferenceProbe::undetermined(
                'ページからの参照を確認できませんでした（識別子に想定外の文字が含まれています）。',
            );
        }

        $deadline = $this->now() + $this->timeBudgetMs;

        $matchedPageIds = [];
        $count = 0;
        $timedOut = false;
        $malformed = 0;

        try {
            // ① 候補抽出。値は binding で渡す（文字列連結をしない）
            PageVersion::query()
                ->where('document', 'like', '%'.$needle.'%')
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($versions) use (
                    $matches,
                    $deadline,
                    &$matchedPageIds,
                    &$count,
                    &$timedOut,
                    &$malformed,
                ): bool {
                    foreach ($versions as $version) {
                        if ($this->now() > $deadline) {
                            $timedOut = true;

                            return false; // chunk 走査を打ち切る
                        }

                        // ② 構造確認。**一致しても最後まで検証する**
                        $inspection = $this->inspect($version->document, $matches);

                        if ($inspection->matched) {
                            $count++;
                            $matchedPageIds[$version->page_id] = true;
                        }

                        if ($inspection->malformed !== null) {
                            // C: 壊れた document を「参照なし」にしない
                            $malformed++;
                        }
                    }

                    return true;
                });
        } catch (Throwable $e) {
            // DB エラーも「参照あり」ではなく「確定できなかった」（fail closed）
            report($e);

            return ReferenceProbe::undetermined(
                'ページからの参照を調べられませんでした（データベースの読み取りに失敗）。',
            );
        }

        if ($timedOut) {
            return ReferenceProbe::undetermined(
                'ページからの参照を時間内に確認できませんでした。時間をおいて再試行するか、アーカイブしてください。',
            );
        }

        // 候補のうち 1 件でも判定不能なら report 全体を未完了にする。
        // 既に見つかった参照は保持したうえで undetermined を併記する
        if ($malformed > 0) {
            return ReferenceProbe::partial(
                $count,
                $this->pageTitles(array_keys($matchedPageIds)),
                sprintf(
                    'ページ %d 件の内容を読み取れなかったため、参照の有無を確定できませんでした。',
                    $malformed,
                ),
            );
        }

        if ($count === 0) {
            return ReferenceProbe::none();
        }

        return ReferenceProbe::found($count, $this->pageTitles(array_keys($matchedPageIds)));
    }

    /**
     * document 全体を **1 回の走査**で検証しつつ、参照一致を収集する。
     *
     * 一致で打ち切らないため、同じ document の後半にある壊れた構造も検出できる。
     *
     * @param  callable(array<string, mixed>): bool  $matches
     */
    private function inspect(mixed $document, callable $matches): DocumentInspection
    {
        $result = new DocumentInspection;

        // `document` は array cast。JSON decode に失敗すると null になる
        if (! is_array($document)) {
            $result->markMalformed('document を読み取れません');

            return $result;
        }

        if (! array_key_exists('root', $document)) {
            $result->markMalformed('document に root がありません');

            return $result;
        }

        $this->walk($document['root'], $matches, $result);

        return $result;
    }

    /**
     * ノードを再帰的に検証する。**途中で return しない**（兄弟・子孫も最後まで見る）。
     *
     * @param  callable(array<string, mixed>): bool  $matches
     */
    private function walk(mixed $node, callable $matches, DocumentInspection $result): void
    {
        if (! is_array($node)) {
            $result->markMalformed('ブロックの形式が不正です');

            return;
        }

        $this->walkFilters($node, $matches, $result);

        if (! array_key_exists('children', $node) || $node['children'] === null) {
            return;
        }

        $children = $node['children'];

        if (! is_array($children)) {
            $result->markMalformed('children の形式が不正です');

            return;
        }

        foreach ($children as $child) {
            $this->walk($child, $matches, $result);
        }
    }

    /**
     * 1 ノードの `binding.filters[]` を検証しつつ一致を見る。
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): bool  $matches
     */
    private function walkFilters(array $node, callable $matches, DocumentInspection $result): void
    {
        if (! array_key_exists('binding', $node) || $node['binding'] === null) {
            return;
        }

        $binding = $node['binding'];

        if (! is_array($binding)) {
            $result->markMalformed('binding の形式が不正です');

            return;
        }

        if (! array_key_exists('filters', $binding) || $binding['filters'] === null) {
            return;
        }

        $filters = $binding['filters'];

        if (! is_array($filters)) {
            $result->markMalformed('binding.filters の形式が不正です');

            return;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                $result->markMalformed('binding.filters の要素が不正です');

                continue;
            }

            $problem = $this->taxonomyFilterProblem($filter);

            if ($problem !== null) {
                $result->markMalformed($problem);

                continue;
            }

            if ($matches($filter)) {
                $result->matched = true;
            }
        }
    }

    /**
     * taxonomy filter の**構造**を検証する（CORRECTION #2 A）。
     *
     * 3 状態を区別する。
     *
     * 1. `taxonomy` キーが無い … `{field, op, value}` 等の通常 filter。**無視してよい**（null を返す）
     * 2. `taxonomy` が文字列 … 正常な taxonomy filter。対象 key と違っても complete のまま
     * 3. `taxonomy` キーはあるが**文字列でない** … malformed。
     *    「別 taxonomy だから無関係」ではなく「判定できない」
     *
     * `terms` も同様に検証する。taxonomy filter を名乗る以上、
     * `terms` の欠落・非配列・非文字列要素は **黙って無視しない**。
     *
     * @param  array<string, mixed>  $filter
     * @return string|null malformed の理由。正常またはtaxonomy filter でなければ null
     */
    private function taxonomyFilterProblem(array $filter): ?string
    {
        if (! array_key_exists('taxonomy', $filter)) {
            // 状態 1: taxonomy filter ではない
            return null;
        }

        if (! is_string($filter['taxonomy'])) {
            // 状態 3
            return '絞り込み条件の分類名が文字列ではありません';
        }

        if (! array_key_exists('terms', $filter)) {
            return '絞り込み条件に terms がありません';
        }

        if (! is_array($filter['terms'])) {
            return '絞り込み条件の terms が配列ではありません';
        }

        foreach ($filter['terms'] as $term) {
            if (! is_string($term)) {
                return '絞り込み条件の terms に文字列でない要素があります';
            }
        }

        // 状態 2
        return null;
    }

    /**
     * @param  list<int>  $pageIds
     * @return list<string>
     */
    private function pageTitles(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        /** @var list<string> $titles */
        $titles = Page::query()
            ->whereIn('id', array_slice($pageIds, 0, self::SAMPLE_LIMIT))
            ->orderBy('id')
            ->pluck('title')
            ->all();

        return $titles;
    }

    private function now(): float
    {
        return microtime(true) * 1000;
    }
}
