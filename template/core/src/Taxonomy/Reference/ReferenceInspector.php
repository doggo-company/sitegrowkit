<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Throwable;

/**
 * 「この分類は何から参照されているか」を答える唯一の窓口
 * （Phase 9.3B / STAGE 2・SPEC §I.3）。
 *
 * **削除可否の唯一の判定者。**
 * 将来の `DeleteTerm` / `DeleteTaxonomy` は自前で数えず、必ずここへ問う。
 *
 * 参照の種類（R-1〜R-6）は provider として登録する。
 * menu / listing / 再利用ブロック等が増えても **provider を足すだけ**で
 * RESTRICT に反映され、判定側は書き換えない。
 *
 * **read-only。DB を書き換えない。**
 */
final class ReferenceInspector
{
    /** @var list<ReferenceProvider> */
    private array $providers;

    /** @param list<ReferenceProvider>|null $providers 省略時は既定構成（`defaultProviders()`） */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? self::defaultProviders(
            app(SchemaRegistry::class),
            (int) config('agentcms.taxonomy_reference_budget_ms', 5000),
        );
    }

    /**
     * 既定 provider 構成（R-1 / R-2 / R-3・R-4 / T-2）。
     *
     * **DI 登録と直接 new の両方がこの 1 箇所を使う。**
     * 2 箇所に別々のリストを置くと構成がずれ、
     * 「DI 経由では検出するが直接 new では見逃す」といった不整合を生む。
     *
     * @return list<ReferenceProvider>
     */
    public static function defaultProviders(SchemaRegistry $schemas, int $timeBudgetMs): array
    {
        return [
            new AssignmentReferenceProvider,
            new ChildTermReferenceProvider,
            new PageDocumentReferenceProvider($timeBudgetMs),
            new ContentSchemaReferenceProvider($schemas),
        ];
    }

    /**
     * provider を足す（R-5 / R-6 の拡張点）。
     * 判定ロジックは変えずに参照の種類だけを増やせる。
     */
    public function register(ReferenceProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<string> 登録済み provider の種別 */
    public function kinds(): array
    {
        return array_map(
            static fn (ReferenceProvider $p): string => $p->kind(),
            $this->providers,
        );
    }

    /**
     * term への参照（R-1〜R-6）。
     *
     * **archived かどうかは結果に影響しない**（AR-8）。
     * archive は削除の代替であって、参照を消す操作ではない。
     */
    public function referencesToTerm(TaxonomyTerm $term): ReferenceReport
    {
        return $this->collect(
            fn (ReferenceProvider $p): ReferenceProbe => $p->forTerm($term),
        );
    }

    /**
     * taxonomy への参照（T-1 / T-2 / T-3）。
     *
     * **T-2（ContentSchema の `taxonomies[]` からの参照）は STAGE 3A で登録済み**
     * （`ContentSchemaReferenceProvider`）。base だけでなく override から
     * 追加・置換された宣言も effective SchemaRegistry 経由で数える。
     */
    public function referencesToTaxonomy(Taxonomy $taxonomy): ReferenceReport
    {
        return $this->collect(
            fn (ReferenceProvider $p): ReferenceProbe => $p->forTaxonomy($taxonomy),
        );
    }

    /**
     * provider を **1 つずつ隔離して**実行する（STAGE 2A CORRECTION D）。
     *
     * ある provider が落ちても:
     * - 例外を握り潰して削除可能にしない（undetermined として扱う）
     * - 他の provider の調査は続行する
     * - 利用者へは秘密情報・SQL・接続情報・stack trace を出さず、詳細はログへ送る
     *
     * @param  callable(ReferenceProvider): ReferenceProbe  $probe
     */
    private function collect(callable $probe): ReferenceReport
    {
        $references = [];
        $undetermined = [];

        foreach ($this->providers as $provider) {
            try {
                $result = $probe($provider);
            } catch (Throwable $e) {
                // 例外本文には SQL や接続情報が含まれ得るため画面へ出さない
                report($e);

                $undetermined[] = sprintf(
                    '%sからの参照を調べられませんでした。',
                    self::labelFor($provider->kind()),
                );

                continue;
            }

            // partial（参照あり + 未確定）は両方を残す
            if ($result->isUndetermined()) {
                $undetermined[] = (string) $result->undeterminedReason;
            }

            if ($result->hasFindings()) {
                $references[] = new Reference(
                    kind: $provider->kind(),
                    label: self::labelFor($provider->kind()),
                    count: $result->count,
                    samples: $result->samples,
                );
            }
        }

        return new ReferenceReport($references, $undetermined);
    }

    private static function labelFor(string $kind): string
    {
        return match ($kind) {
            'assignment' => 'コンテンツ',
            'child_term' => '子の項目',
            'page_document' => 'ページ',
            'content_schema' => 'コンテンツタイプ定義',
            default => $kind,
        };
    }
}
