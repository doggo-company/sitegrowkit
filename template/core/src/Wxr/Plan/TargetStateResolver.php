<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Revisions\SchemaManifest;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyResolver;

/**
 * 取り込み先のいまの状態を DB から読む（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である。読むついでに設定行を作る、
 * 既定値を保存する、schema manifest を materialize する、といったことを
 * 1 つでもすると、**plan を見ただけでサイトの状態が変わる**。
 *
 * ## 判定を自分で書かない
 *
 * 「この型はいま何をしてよいか」は `ContentTypeLifecyclePolicy` が答える。
 * ここで `status === 'archived'` を書くと、判定が 2 か所になり、
 * **片方だけ直したときに import だけ archived な型へ書ける**ようになる。
 *
 * ## 定義は effective schema から取る
 *
 * 指紋の元は `SchemaRegistry::declared()` の素の定義ではなく、
 * `ContentTypeResolver::schema()`（Field Group overlay 込み）である。
 * 実際に書き込むときに通るのがこちらなので、**素の定義で指紋を取ると
 * overlay が変わったことに気づけない**。
 */
final class TargetStateResolver
{
    public function __construct(
        private readonly ContentTypeResolver $types,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly TaxonomyResolver $taxonomies,
        private readonly TaxonomyDefinitionRegistry $definitions,
    ) {}

    /**
     * 取り込み先の状態を**一度の読み取りで見て、問題を全部返す**（投げない）。
     *
     * ## 1 件目で止めない
     *
     * 見つけた順に投げると「最初に見つかった 1 件」しか利用者へ出せず、
     * 直しては実行しを繰り返させることになる（§A.3）。
     *
     * ## 原因を推測して 1 つの理由にまとめない
     *
     * 「定義が無い」と「定義はあるが同期していない」は、次にやることが違う。
     * 前者はファイルを作るところから、後者は同期を走らせるだけである。
     * 混ぜると、既に在るファイルをもう一度作ろうとすることになる。
     *
     * ## 判定と状態を同じ読み取りから作る
     *
     * 判定してから 状態 を組み直すと、その間に変わったものを見逃す。
     * ここでは 1 度読んだ値から両方を作るので、**判定に使った値と
     * hash に載る値がずれない**。
     *
     * ## 関係するものだけを載せる
     *
     * 型が宣言している分類を全部、あるいはその分類を宣言している型を全部載せると、
     * **今回の取り込みと関係のない設定を変えただけで再 plan を求める**ことになる。
     *
     * `$taxonomyKeys` は `$typeTaxonomies` の和では**足りない**。
     * `<channel>` 直下の term 定義には、どの item も使っていないものがあり得る。
     * §C.3 は「既存の分類へ項目を足す」なので、**使われていないから黙って消す、
     * という決定はどこにも無い**。和から導くと、その分類が plan から消える。
     *
     * @param  array<string, list<string>>  $typeTaxonomies  取り込み先の型 => その型で実際に使う分類 key
     * @param  list<string>  $taxonomyKeys  今回関係する分類すべて（未使用の定義も含む）
     * @param  list<string>  $commentsRelevantFor  WXR にコメントがあった型だけ
     */
    public function inspect(
        array $typeTaxonomies,
        array $taxonomyKeys,
        array $commentsRelevantFor,
        PlanFindings $findings,
    ): ?TargetState {
        $types = [];
        $taxonomies = [];

        foreach ($typeTaxonomies as $id => $relevant) {
            $state = $this->inspectType(
                (string) $id,
                array_values(array_unique($relevant)),
                in_array((string) $id, $commentsRelevantFor, true),
                $findings,
            );

            if ($state !== null) {
                $types[] = $state;
            }
        }

        foreach (array_unique($taxonomyKeys) as $key) {
            $state = $this->inspectTaxonomy(
                $key,
                $this->targetsUsing($key, $typeTaxonomies),
                $findings,
            );

            if ($state !== null) {
                $taxonomies[] = $state;
            }
        }

        /*
         * 使えないと分かった時点で状態は返さない。**同じ一度の読み取りから**
         * 判定も状態も作るので、判定に使った値と hash に載る値がずれない。
         */
        return $findings->blocksPlan() ? null : new TargetState($types, $taxonomies);
    }

    /**
     * @param  list<string>  $relevant
     */
    private function inspectType(
        string $id,
        array $relevant,
        bool $commentsRelevant,
        PlanFindings $findings,
    ): ?TargetTypeState {
        if (! $this->types->knows($id)) {
            $findings->add(PlanIssue::UnknownType, $id, 'type');

            return null;
        }

        /*
         * **1 度だけ読む。**
         *
         * 判定のときと状態を組むときで別々に呼ぶと、その間に変わったものが
         * **判定と hash で食い違う**。「止めなかった理由」と「hash に載った値」が
         * 違う plan は、照合しても何も保証しない。
         */
        $settings = $this->lifecycle->settings($id);
        $schema = $this->types->schema($id);

        $isEditorial = $this->lifecycle->isEditorial($id);
        $canCreate = $this->lifecycle->canCreate($id);
        $isPublic = $this->lifecycle->isPublic($id);
        $hasArchive = $settings->flag('has_archive', true);
        $publiclyQueryable = $settings->flag('publicly_queryable', true);
        $commentsEnabled = $commentsRelevant ? $this->lifecycle->commentsEnabled($id) : null;

        if (! $isEditorial) {
            $findings->add(PlanIssue::TypeNotEditorial, $id, 'type');
        }

        if (! $canCreate) {
            $findings->add(PlanIssue::TypeCannotCreate, $id, 'type');
        }

        if (! $isPublic) {
            /*
             * §C.2「入れる型が editorial でない、または公開できない状態なら止める」。
             * 移行の方針そのものなので、ここで緩めない。
             */
            $findings->add(PlanIssue::TypeNotPublic, $id, 'type');
        } elseif (! $hasArchive && (! $publiclyQueryable || ! $schema->hasField('slug'))) {
            /*
             * **「公開する」と言いながらどこにも出ない**は設定の矛盾である。
             * 十年分を入れてから「見えない」と気づくことになる。
             *
             * 詳細ページは `publicly_queryable` だけでは出ない。
             * `PublicContentController::show()` は **slug の欄が無い型を 404 にする**
             * （URL の作りようがない）。設定だけを見て「出る」と判断すると、
             * plan が本番と違うことを言う。
             */
            $findings->add(PlanIssue::TypeNotReachable, $id, 'type');
        }

        if ($commentsEnabled === false) {
            /*
             * コメントがあるのに受けない型では、**写す先が無い**（§C.4）。
             * 記事だけ入れて他人の書いた文章を落とすことになる。
             */
            $findings->add(PlanIssue::TypeCommentsDisabled, $id, 'type');
        }

        foreach ($relevant as $key) {
            if (! $schema->hasTaxonomy($key)) {
                /*
                 * **黙って落とさない。** 割り当ては書かれているのに入れる先が無い。
                 * 落とすと、分類の付いていない記事が十年分できる。
                 */
                $findings->add(PlanIssue::TaxonomyNotDeclaredForType, $key, $id);
            }
        }

        return new TargetTypeState(
            id: $id,
            schemaFingerprint: SchemaManifest::fingerprint($schema),
            isEditorial: $isEditorial,
            canCreate: $canCreate,
            isPublic: $isPublic,
            publicSlug: $settings->publicSlug,
            hasArchive: $hasArchive,
            publiclyQueryable: $publiclyQueryable,
            commentsEnabled: $commentsEnabled,
            taxonomies: array_values(array_filter(
                $schema->taxonomies,
                static fn ($declaration): bool => in_array($declaration->key, $relevant, true),
            )),
        );
    }

    /**
     * @param  list<string>  $associatedTypes
     */
    private function inspectTaxonomy(
        string $key,
        array $associatedTypes,
        PlanFindings $findings,
    ): ?TargetTaxonomyState {
        $defined = $this->definitions->has($key);
        $row = $this->taxonomies->find($key);

        if ($row === null) {
            // **次にやることが違うので、理由を分ける**
            $findings->add(
                $defined ? PlanIssue::TaxonomyNotSynced : PlanIssue::MissingTaxonomy,
                $key,
                'taxonomy',
            );

            return null;
        }

        if ($row->isArchived()) {
            $findings->add(PlanIssue::TaxonomyArchived, $key, 'taxonomy');
        }

        if (! $defined) {
            // DB にはあるが定義が無い（code-managed の SSoT から外れている）
            $findings->add(PlanIssue::MissingTaxonomy, $key, 'taxonomy');

            return null;
        }

        $definition = $this->definitions->get($key);

        if ($definition->managedBy !== $row->managed_by) {
            $findings->add(PlanIssue::TaxonomyManagedByMismatch, $key, 'taxonomy');
        }

        if ($definition->hierarchical !== (bool) $row->getAttribute('hierarchical')) {
            $findings->add(PlanIssue::TaxonomyHierarchyMismatch, $key, 'taxonomy');
        }

        return new TargetTaxonomyState(
            key: $key,
            id: (string) $row->getKey(),
            status: (string) $row->getAttribute('status'),
            hierarchical: (bool) $row->getAttribute('hierarchical'),
            managedBy: $row->managed_by,
            // ここまで来た時点で DB 行は在る（未同期は上で止めている）
            synced: true,
            associatedTypes: $associatedTypes,
        );
    }

    /**
     * この分類を**今回の取り込みで**使う型。
     *
     * サイト全体で宣言している型を並べない。関係のない型の宣言が変わっただけで
     * hash が動くと、**意味の無い再 plan** を求めることになる。
     *
     * @param  array<string, list<string>>  $typeTaxonomies
     * @return list<string>
     */
    private function targetsUsing(string $key, array $typeTaxonomies): array
    {
        $using = [];

        foreach ($typeTaxonomies as $id => $keys) {
            if (in_array($key, $keys, true)) {
                $using[] = (string) $id;
            }
        }

        sort($using);

        return $using;
    }
}
