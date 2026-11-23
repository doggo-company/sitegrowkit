<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyResolver;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * key / ID から taxonomy・term を解決する composition 部品
 * （Phase 9.3B / STAGE 2B・SPEC §J.3）。
 *
 * **継承のための基底クラスではない**（owner 決定 G16: 1 操作 = 1 クラス）。
 * 各 Application Service が同じ解決手順を書かないためだけに存在する。
 *
 * **read-only**。`TaxonomyResolver` と同じく DB を書かない。
 * 未同期・`managed_by` 不一致を**黙って直さない**（PV-3 / PV-4）。
 */
final class TaxonomyLookup
{
    public function __construct(
        private readonly TaxonomyResolver $resolver,
        private readonly TaxonomyDefinitionRegistry $definitions,
    ) {}

    /**
     * 外部 contract の識別子は **key**（ULID は露出させない — §J.3）。
     *
     * @return Taxonomy|Result 解決できなければ notFound / conflict
     */
    public function taxonomy(string $key): Taxonomy|Result
    {
        if ($key === '') {
            return Result::validationError(['taxonomyKey' => ['分類のキーを指定してください']]);
        }

        $taxonomy = $this->resolver->find($key);

        if ($taxonomy === null) {
            // 定義はあるのに DB に無い = 未同期。**runtime で自動作成しない**
            if ($this->definitions->has($key)) {
                return Result::conflict(
                    "分類 [{$key}] がデータベースへ同期されていません。"
                    .'`php artisan agentcms:taxonomy-sync` を実行してください（自動では作成しません）。'
                );
            }

            return Result::notFound("分類 [{$key}] が見つかりません");
        }

        $conflict = $this->validate($taxonomy);

        return $conflict ?? $taxonomy;
    }

    /**
     * 既に読み込んである Taxonomy の整合を確かめる。
     *
     * transaction 内で **lock 済みの行**に対して再検証するために切り出してある
     * （STAGE 2B CORRECTION E）。`managed_by` 不一致を黙って直さない。
     *
     * @return Result|null 問題が無ければ null
     */
    public function validate(Taxonomy $taxonomy): ?Result
    {
        if (! $this->definitions->has($taxonomy->key)) {
            return null;
        }

        $expected = $this->definitions->get($taxonomy->key)->managedBy;

        if ($taxonomy->managed_by !== $expected) {
            return Result::conflict(
                "分類 [{$taxonomy->key}] の管理区分が定義（{$expected->value}）と"
                ."データベース（{$taxonomy->managed_by->value}）で一致しません。自動では修正しません。"
            );
        }

        return null;
    }

    /**
     * term mutation の共通 mutex（STAGE 2B CORRECTION D）。
     *
     * **transaction 内で最初に呼ぶ。** 対象 taxonomy の行を lock し、
     * 同一 taxonomy の階層・archive・並び順変更を直列化する。
     * 4 つの mutation すべてでこの順序に揃えることで deadlock を作りにくくする。
     *
     * @return Taxonomy|Result 消えていれば conflict
     */
    public function lockTaxonomy(string $taxonomyId): Taxonomy|Result
    {
        $locked = Taxonomy::query()->whereKey($taxonomyId)->lockForUpdate()->first();

        if ($locked === null) {
            return Result::conflict('分類が見つかりません。同時に削除された可能性があります。');
        }

        return $this->validate($locked) ?? $locked;
    }

    public function term(string $termId): TaxonomyTerm|Result
    {
        if ($termId === '') {
            return Result::validationError(['termId' => ['項目の ID を指定してください']]);
        }

        $term = TaxonomyTerm::query()->find($termId);

        if ($term === null) {
            return Result::notFound("項目 [{$termId}] が見つかりません");
        }

        return $term;
    }
}
