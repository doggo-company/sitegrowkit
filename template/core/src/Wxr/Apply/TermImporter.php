<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Wxr\FlatTermDefinitionSource;
use AgentCms\Core\Wxr\Ledger\ExistingTermLookup;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Ledger\TermSlot;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 分類項目を入れる（Phase 9.14 / STAGE 27D-4・SPEC §C.3・§E.1）。
 *
 * ## 親から先に作る
 *
 * `CreateTerm` が受け取る親は**こちらの ULID** である。
 * 親を先に作って id を覚えていなければ、子を作れない。
 * 順は `TermGraph::creationOrder()` が返す（`plan` の報告の順とは別の口）。
 *
 * ## 名前はここで読み直す
 *
 * `TermGraph` は名前を返さない — 他人のサイトの分類名には個人名も屋号も入るので、
 * `plan` の診断にも hash にも載せないためである。
 * だが `apply` は**それを書くために名前が要る**。だから定義をもう一度読む。
 * 抱えるのは `wp:term_id => 名前` の対応だけで、`TermGraph::MAX_DEFINITIONS` で頭打ちになる。
 *
 * ## 作っていないものを所有しない
 *
 * 既にあった項目を使い回した場合も、重なった `wp:term_id` の場合も、
 * **この取り込みがその項目を作ったわけではない**ので所有権を持たない。
 * 所有すると、取り消しで**元からあった項目まで消える**。
 *
 * ## alias は代表を全部作ってから
 *
 * 重なった `wp:term_id` は代表と同じ対象を指すが、**代表を作るまで id が無い**。
 *
 * alias の行は代表と**別の transaction**で書く。欠けても、代表の行があるので
 * 対象は取り消せる（**取り消せない対象はできない**）。再実行すれば同じ綴りなので
 * `reuse` に落ちて埋まり、二重にはならない — それを回復のテストで固定してある。
 */
final class TermImporter
{
    public function __construct(
        private readonly LedgerReconciler $ledger,
        private readonly ExistingTermLookup $existing,
        private readonly CreateTerm $create,
        private readonly FlatTermDefinitionSource $flatTerms,
    ) {}

    /**
     * 入れて、**作った件数**を返す。
     *
     * @return array<string, int>
     */
    public function import(
        WxrReader $reader,
        TermGraph $graph,
        ImportOptions $options,
        string $batchId,
        OperationContext $context,
    ): array {
        /*
         * 関係は**外から渡る**（`ImportGraph`）。ここで自分で組み直すと、
         * ファイルの走査が 1 周増えるうえに、**項目を作った代表と
         * 記事が指す代表が食い違う**余地が残る。
         */
        $order = $graph->creationOrder();

        if ($order === [] && $graph->aliases() === []) {
            return [];
        }

        $names = $this->names($reader, $options);
        $counts = [];

        /** @var array<string, string> WXR の term_id => こちらの id */
        $made = [];

        foreach ($order as $term) {
            [$action, $id] = $this->place($term, $names, $made, $batchId, $context);

            $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

            if ($id !== null) {
                $made[$term['termId']] = $id;
            }
        }

        foreach ($graph->aliases() as $termId => $representative) {
            // term_id は数字だけの文字列なので、鍵として持つと PHP が整数へ直す
            $action = $this->placeAlias((string) $termId, $representative, $order, $made, $batchId);

            $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * `wp:term_id => 名前`。**ここでだけ名前を持つ。**
     *
     * **平らな定義の分も合わせる**（WP 一周 2026-09-04）。`$graph` には
     * `ImportGraph` が `FlatTermDefinitionSource` 経由で合成した項目も入り得るので、
     * ここで real の定義しか読まないと、その項目の名前が引けず `createOne()` が
     * 「wp:term_id … の名前を読み直せませんでした」で落ちる。
     *
     * @return array<string, string>
     */
    private function names(WxrReader $reader, ImportOptions $options): array
    {
        $names = [];

        // 🔴 target で持つ（`PlanBuilder::scan()` / `ImportGraph::of()` と同じ規則）
        /** @var array<string, true> 読み替えた後の受け皿のうち、`<channel>` 直下に定義があるもの */
        $definedTaxonomies = [];

        foreach ($reader->termDefinitions() as $definition) {
            $definedTaxonomies[$options->taxonomies[$definition->taxonomy] ?? $definition->taxonomy] = true;
            $names[$definition->termId] = trim($definition->name);
        }

        foreach ($this->flatTerms->forUndefinedTaxonomies($reader, $options, $definedTaxonomies) as $definition) {
            $names[$definition->termId] = trim($definition->name);
        }

        return $names;
    }

    /**
     * 1 件について、作るか・使い回すか・触らないかを決めて実行する。
     *
     * @param  array{termId: string, target: string, storageSlug: string, parentTermId: string|null}  $term
     * @param  array<string, string>  $names
     * @param  array<string, string>  $made
     * @return array{0: PlanAction, 1: string|null}
     */
    private function place(array $term, array $names, array $made, string $batchId, OperationContext $context): array
    {
        $key = new LedgerKey(ImportKind::Term, $term['target'], $term['termId']);

        // 理由は plan が全部出し済みなので捨てる（**同じ理由を二度数えない**）
        $verdict = $this->ledger->reconcile([$key], new PlanFindings)[0];

        $existing = $this->existing->find([new TermSlot($term['target'], $term['storageSlug'])])[0];

        $action = $verdict->action($existing !== null);

        if ($action === PlanAction::SkipExisting) {
            // 既に入っている。**触らない**
            return [$action, $verdict->usableTargetId()];
        }

        if ($action === PlanAction::Reuse) {
            /*
             * 既にある項目を使う。**こちらが作っていないので所有しない** —
             * 取り消しで元からあった項目まで消してはならない（§C.3）。
             */
            $id = (string) ($existing['id'] ?? '');

            $this->record($batchId, $term['termId'], $term['target'], $id, owned: false);

            return [$action, $id];
        }

        if ($action !== PlanAction::Create) {
            // plan が止めているのでここへは来ない
            return [$action, null];
        }

        return [$action, $this->createOne($term, $names, $made, $batchId, $context)];
    }

    /**
     * 1 件を、**台帳の記録まで含めて 1 つの transaction で**作る。
     *
     * @param  array{termId: string, target: string, storageSlug: string, parentTermId: string|null}  $term
     * @param  array<string, string>  $names
     * @param  array<string, string>  $made
     */
    private function createOne(
        array $term,
        array $names,
        array $made,
        string $batchId,
        OperationContext $context,
    ): string {
        return DB::transaction(function () use ($term, $names, $made, $batchId, $context): string {
            if (! isset($names[$term['termId']])) {
                /*
                 * **綴りを名前の代わりにしない**（F-7）。
                 *
                 * 代表は定義から作っているので、名前が引けないのは
                 * **2 周目の読み取りが 1 周目と違うものを返した**場合だけである。
                 * そこで黙って綴りを名前にすると、十年分の分類が
                 * `oshirase` のような URL 用の文字列で並ぶ。名前は後から復元できない。
                 */
                throw new RuntimeException("wp:term_id {$term['termId']} の名前を読み直せませんでした");
            }

            $result = $this->create->execute([
                'taxonomyKey' => $term['target'],
                'name' => $names[$term['termId']],
                'slug' => $term['storageSlug'],
                'parentId' => $term['parentTermId'] === null ? null : ($made[$term['parentTermId']] ?? null),
            ], $context);

            if ($result->status !== OperationStatus::Success) {
                // **黙って飛ばさない。** 飛ばすと、分類の付かない記事が十年分できる
                throw new RuntimeException(
                    "wp:term_id {$term['termId']} を入れられませんでした（{$result->status->value}）",
                );
            }

            $made_ = $result->data['term'] ?? null;
            $id = is_array($made_) ? (string) ($made_['id'] ?? '') : '';

            if ($id === '') {
                throw new RuntimeException("wp:term_id {$term['termId']} の作成結果を確認できませんでした");
            }

            // **こちらが作ったので所有する**（取り消しで消してよい）
            $this->record($batchId, $term['termId'], $term['target'], $id, owned: true);

            return $id;
        });
    }

    /**
     * 重なった `wp:term_id` を、代表と同じ対象へ結び付ける。**所有はしない。**
     *
     * @param  list<array{termId: string, target: string, storageSlug: string, parentTermId: string|null}>  $order
     * @param  array<string, string>  $made
     */
    private function placeAlias(
        string $termId,
        string $representative,
        array $order,
        array $made,
        string $batchId,
    ): PlanAction {
        $target = null;

        foreach ($order as $term) {
            if ($term['termId'] === $representative) {
                $target = $term['target'];

                break;
            }
        }

        if ($target === null) {
            // 代表が見つからないのは `TermGraph` が許さない形である
            throw new RuntimeException("wp:term_id {$termId} の代表が見つかりません");
        }

        $verdict = $this->ledger->reconcile(
            [new LedgerKey(ImportKind::Term, $target, $termId)],
            new PlanFindings,
        )[0];

        if ($verdict->entry->targetId !== null) {
            // 既に記録がある。**触らない**
            return PlanAction::SkipExisting;
        }

        $id = $made[$representative] ?? $this->representativeId($target, $representative);

        $this->record($batchId, $termId, $target, $id, owned: false);

        return PlanAction::Reuse;
    }

    /**
     * 代表がこの実行で作られていない（既に入っていた）ときの、その対象の id。
     */
    private function representativeId(string $target, string $representative): string
    {
        $verdict = $this->ledger->reconcile(
            [new LedgerKey(ImportKind::Term, $target, $representative)],
            new PlanFindings,
        )[0];

        $id = $verdict->usableTargetId();

        if ($id === null) {
            throw new RuntimeException("wp:term_id {$representative} の対象を辿れません");
        }

        return $id;
    }

    private function record(string $batchId, string $termId, string $target, string $targetId, bool $owned): void
    {
        DB::table('import_records')->insert([
            'batch_id' => $batchId,
            'external_id' => $termId,
            'kind' => ImportKind::Term->value,
            'target_type' => $target,
            'target_id' => $targetId,
            'created_by_import' => $owned,
            'created_at' => now(),
        ]);
    }
}
