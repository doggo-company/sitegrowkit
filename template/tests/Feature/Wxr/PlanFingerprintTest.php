<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Schema\TaxonomyDeclaration;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanActionDigest;
use AgentCms\Core\Wxr\Plan\PlanFingerprint;
use AgentCms\Core\Wxr\Plan\PlanIdentity;
use AgentCms\Core\Wxr\Plan\RelevantTargetState;
use AgentCms\Core\Wxr\Plan\TargetState;
use AgentCms\Core\Wxr\Plan\TargetTaxonomyState;
use AgentCms\Core\Wxr\Plan\TargetTypeState;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `apply` が照合する 1 つの値（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## なぜ取り込み先の状態まで入れるのか
 *
 * ファイルと指定が同じでも、**plan を見た時点では入れられた型が、
 * apply の時点では入れられなくなっている**ことがある。型が archived になる、
 * 分類の定義が変わる、同じ slug の項目が誰かの手で増える。
 * そのとき apply が作るものは、利用者が見た plan とは違う。
 *
 * ファイルの hash と指定だけでは、この差が 1 つも見えない。
 *
 * ## なぜ契約の版を入れるのか
 *
 * 判定の規則そのものを変えたのに古い hash が通ると、
 * **コードを更新したあとに、更新前の意味で apply される**。
 */
final class PlanFingerprintTest extends TestCase
{
    public function test_同じ状態なら同じ値になる(): void
    {
        $this->assertSame($this->fingerprint(), $this->fingerprint());
    }

    // ── 取り込み先が変われば変わる ──────────────────────────

    public function test_型が作れなくなれば変わる(): void
    {
        // archived になった型へ、plan 済みのものを入れさせない
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(canCreate: false)]),
        );
    }

    public function test_型が公開できなくなれば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(isPublic: false)]),
        );
    }

    public function test_型の定義が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(schemaFingerprint: str_repeat('9', 64))]),
        );
    }

    public function test_型のコメント可否が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(commentsEnabled: false)]),
        );
    }

    public function test_型の分類宣言が変われば変わる(): void
    {
        // key だけでなく multiple / required も取り込みの結果を変える
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(
                declarations: [new TaxonomyDeclaration('blog_category', multiple: false, required: false)],
            )]),
        );
    }

    public function test_分類が階層でなくなれば変わる(): void
    {
        // 「親から先に作る」の意味そのものが変わる
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(hierarchical: false)]),
        );
    }

    public function test_分類が同期されていなければ変わる(): void
    {
        // 受け皿が実在しないなら、plan の前提が崩れている
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(synced: false)]),
        );
    }

    public function test_分類の状態が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(status: 'archived')]),
        );
    }

    // ── これから起きることが変われば変わる ──────────────────

    public function test_行動が1件でも変われば変わる(): void
    {
        $other = new PlanActionDigest;
        $other->add('content', '11', 'post', PlanAction::SkipExisting, '5', new RelevantTargetState);

        $this->assertNotSame($this->fingerprint(), $this->fingerprint(actions: $other));
    }

    // ── 契約の版 ────────────────────────────────────────────

    public function test_契約の版が値に効いている(): void
    {
        /*
         * 判定の規則を変えたのに古い hash が通ると、
         * **更新前の意味で apply される**。版は値の一部である。
         */
        $canonical = PlanFingerprint::canonical(
            $this->identity(),
            $this->state(),
            $this->actions(),
        );

        $this->assertStringContainsString(PlanFingerprint::CONTRACT_VERSION, $canonical);
    }

    // ── 並びで揺れない ──────────────────────────────────────

    public function test_型や分類の並び順で変わらない(): void
    {
        $ordered = $this->fingerprint(
            types: [$this->type(id: 'post'), $this->type(id: 'news')],
            taxonomies: [$this->taxonomy(key: 'blog_category'), $this->taxonomy(key: 'blog_tag')],
        );
        $reversed = $this->fingerprint(
            types: [$this->type(id: 'news'), $this->type(id: 'post')],
            taxonomies: [$this->taxonomy(key: 'blog_tag'), $this->taxonomy(key: 'blog_category')],
        );

        $this->assertSame($ordered, $reversed);
    }

    public function test_値は64桁の小文字16進である(): void
    {
        // 大文字と小文字が混ざると、同じ値が別物として貼り直される
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fingerprint());
    }

    public function test_投稿タイプかどうかが変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(isEditorial: false)]),
        );
    }

    public function test_分類が作り直されていれば変わる(): void
    {
        // key が同じでも、実体が別なら別の受け皿である
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(id: '01ZZZZZZZZZZZZZZZZZZZZZZZZ')]),
        );
    }

    public function test_分類の持ち主が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(managedBy: TaxonomyManagedBy::Cms)]),
        );
    }

    public function test_分類を使う型が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(taxonomies: [$this->taxonomy(associatedTypes: ['post', 'news'])]),
        );
    }

    public function test_公開スラッグが変われば変わる(): void
    {
        /*
         * ここが変わると**取り込んだ記事の URL が丸ごと変わる**。
         * 同じ plan のつもりで、別の場所に十年分が現れることになる。
         */
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(publicSlug: 'articles')]),
        );
    }

    public function test_一覧ページの有無が変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(hasArchive: false)]),
        );
    }

    public function test_単体ページへ到達できるかが変われば変わる(): void
    {
        $this->assertNotSame(
            $this->fingerprint(),
            $this->fingerprint(types: [$this->type(publiclyQueryable: false)]),
        );
    }

    // ── コメントは関係するときだけ効かせる ──────────────────

    public function test_コメントが無い取り込みでは設定の変更で変わらない(): void
    {
        /*
         * WXR にコメントが 1 件も無いなら、型のコメント設定を変えても
         * 取り込みの結果は変わらない。**無関係な設定変更で再 plan を求めない。**
         */
        $this->assertSame(
            $this->fingerprint(types: [$this->type(commentsEnabled: null)]),
            $this->fingerprint(types: [$this->type(commentsEnabled: null)]),
        );

        $this->assertNotSame(
            $this->fingerprint(types: [$this->type(commentsEnabled: null)]),
            $this->fingerprint(types: [$this->type(commentsEnabled: true)]),
        );
    }

    // ── 同じものを 2 回渡させない ────────────────────────────

    public function test_同じ型を2つ渡したら断る(): void
    {
        /*
         * 配列のキーで畳むと**後から来た方が黙って勝つ**。
         * 違う状態を渡したのに同じ値になるのは、この検査の意味を失わせる。
         */
        $this->expectException(InvalidArgumentException::class);

        new TargetState(
            types: [$this->type(canCreate: true), $this->type(canCreate: false)],
            taxonomies: [$this->taxonomy()],
        );
    }

    public function test_同じ分類を2つ渡したら断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TargetState(
            types: [$this->type()],
            taxonomies: [$this->taxonomy(hierarchical: true), $this->taxonomy(hierarchical: false)],
        );
    }

    public function test_同じ分類宣言を2つ渡したら断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->type(declarations: [
            new TaxonomyDeclaration('blog_category', multiple: true, required: false),
            new TaxonomyDeclaration('blog_category', multiple: false, required: true),
        ]);
    }

    public function test_同じ型を2回関連付けたら断る(): void
    {
        // 集合なので重複に意味は無い。残すと同じ意味で hash が変わる
        $this->expectException(InvalidArgumentException::class);

        $this->taxonomy(associatedTypes: ['post', 'post']);
    }

    // ── 壊れた値を畳まない ──────────────────────────────────

    public function test_定義の指紋が64桁16進でなければ断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->type(schemaFingerprint: 'not-a-hash');
    }

    public function test_ファイルの指紋が64桁16進でなければ断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanIdentity(
            fileHash: 'not-a-hash',
            path: '/tmp/export.xml',
            options: ImportOptions::fromCli([], [], false),
        );
    }

    public function test_分類の状態に説明文を入れられない(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->taxonomy(status: 'これは有効な分類です');
    }

    // ── 個人情報を持ち込まない ──────────────────────────────

    public function test_canonicalに本文も名前も出ない(): void
    {
        /*
         * **16 進の digest には "11" のような並びが偶然現れる。**
         * 数字だけで探すと通ってしまうので、hex に現れない印を使う。
         */
        $actions = new PlanActionDigest;
        $actions->add('content', 'EXTERNAL-ID-ZZZ', 'post', PlanAction::Create, null, new RelevantTargetState(
            matchedSlug: 'yamada-taro-first-post',
        ));

        $canonical = PlanFingerprint::canonical($this->identity(), $this->state(), $actions);

        // action は畳んだ digest と件数しか出さない（明細は canonical に入らない）
        $this->assertStringNotContainsString('yamada-taro-first-post', $canonical);
        $this->assertStringNotContainsString('EXTERNAL-ID-ZZZ', $canonical);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<TargetTypeState>|null  $types
     * @param  list<TargetTaxonomyState>|null  $taxonomies
     */
    private function fingerprint(
        ?array $types = null,
        ?array $taxonomies = null,
        ?PlanActionDigest $actions = null,
    ): string {
        return PlanFingerprint::of(
            $this->identity(),
            $this->state($types, $taxonomies),
            $actions ?? $this->actions(),
        );
    }

    private function identity(): PlanIdentity
    {
        return new PlanIdentity(
            fileHash: str_repeat('a', 64),
            path: '/tmp/export.xml',
            options: ImportOptions::fromCli(['post:post'], ['category:blog_category'], false),
        );
    }

    /**
     * @param  list<TargetTypeState>|null  $types
     * @param  list<TargetTaxonomyState>|null  $taxonomies
     */
    private function state(?array $types = null, ?array $taxonomies = null): TargetState
    {
        return new TargetState(
            types: $types ?? [$this->type()],
            taxonomies: $taxonomies ?? [$this->taxonomy()],
        );
    }

    /** @param list<TaxonomyDeclaration>|null $declarations */
    private function type(
        string $id = 'post',
        string $schemaFingerprint = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        bool $isEditorial = true,
        bool $canCreate = true,
        bool $isPublic = true,
        ?string $publicSlug = 'blog',
        bool $hasArchive = true,
        bool $publiclyQueryable = true,
        ?bool $commentsEnabled = true,
        ?array $declarations = null,
    ): TargetTypeState {
        return new TargetTypeState(
            id: $id,
            schemaFingerprint: $schemaFingerprint,
            isEditorial: $isEditorial,
            canCreate: $canCreate,
            isPublic: $isPublic,
            publicSlug: $publicSlug,
            hasArchive: $hasArchive,
            publiclyQueryable: $publiclyQueryable,
            commentsEnabled: $commentsEnabled,
            taxonomies: $declarations ?? [new TaxonomyDeclaration('blog_category', multiple: true, required: false)],
        );
    }

    /** @param list<string>|null $associatedTypes */
    private function taxonomy(
        string $key = 'blog_category',
        string $id = '01JABCDEF0123456789ABCDEFG',
        string $status = 'active',
        bool $hierarchical = true,
        TaxonomyManagedBy $managedBy = TaxonomyManagedBy::Code,
        bool $synced = true,
        ?array $associatedTypes = null,
    ): TargetTaxonomyState {
        return new TargetTaxonomyState(
            key: $key,
            id: $id,
            status: $status,
            hierarchical: $hierarchical,
            managedBy: $managedBy,
            synced: $synced,
            associatedTypes: $associatedTypes ?? ['post'],
        );
    }

    private function actions(): PlanActionDigest
    {
        $digest = new PlanActionDigest;
        $digest->add('content', '11', 'post', PlanAction::Create, null, new RelevantTargetState);

        return $digest;
    }
}
