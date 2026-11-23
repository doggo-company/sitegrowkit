<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\Reference\ReferenceProbe;
use AgentCms\Core\Taxonomy\Reference\ReferenceProvider;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDataCorruptionException;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use AgentCms\Core\Taxonomy\TermHierarchy;
use AgentCms\Core\Taxonomy\TermSlug;
use App\Support\OperationCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 2 の受け入れ — ドメイン規則層。
 *
 * 対象は **slug 正規化（§F）/ 階層不変条件（§D）/ 参照調査（§I.3）**。
 * Application Service・archive 操作・assignment 書き込み経路・API / MCP / Admin は
 * STAGE 3 以降であり、ここでは「まだ公開していない」ことも固定する。
 */
final class TaxonomyDomainRulesTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────
    // §F. slug canonical validation
    // ─────────────────────────────────────────────────────────────

    public function test_slugは小文字化と前後空白除去で正規化される(): void
    {
        $this->assertSame('press', TermSlug::canonicalize('  PRESS  '));
        $this->assertSame('press-release', TermSlug::canonicalize('Press-Release'));
    }

    public function test_slugの形式検査(): void
    {
        $this->assertTrue(TermSlug::isValid('press'));
        $this->assertTrue(TermSlug::isValid('press-release-2026'));

        $this->assertFalse(TermSlug::isValid(''));
        $this->assertFalse(TermSlug::isValid('Press'));          // 大文字
        $this->assertFalse(TermSlug::isValid('press_release'));  // アンダースコア
        $this->assertFalse(TermSlug::isValid('-press'));         // 先頭ハイフン
        $this->assertFalse(TermSlug::isValid('press-'));         // 末尾ハイフン
        $this->assertFalse(TermSlug::isValid('press--release')); // 連続ハイフン
        $this->assertFalse(TermSlug::isValid('プレス'));          // 非 ASCII
        $this->assertFalse(TermSlug::isValid(str_repeat('a', 121)));
    }

    /** F-1: 日本語名は Str::slug が空を返すため fallback する */
    public function test_日本語名から空slugを作らない(): void
    {
        $id = TaxonomyId::generate();

        $slug = TermSlug::generate('お知らせ', $id);

        $this->assertNotSame('', $slug);
        $this->assertTrue(TermSlug::isValid($slug), "生成 slug が不正: {$slug}");
        $this->assertTrue(TermSlug::isGenerated($slug));
        $this->assertStringStartsWith('term-', $slug);
    }

    public function test_英字名はそのままslugになる(): void
    {
        $slug = TermSlug::generate('Press Release', TaxonomyId::generate());

        $this->assertSame('press-release', $slug);
        $this->assertFalse(TermSlug::isGenerated($slug));
    }

    /** F-3: 異なる日本語名が同じ slug に自動統合されない */
    public function test_異なる日本語名を自動統合しない(): void
    {
        $a = TermSlug::generate('制作実績', TaxonomyId::generate());
        $b = TermSlug::generate('制作事例', TaxonomyId::generate());

        $this->assertNotSame($a, $b, '別々の名前が同じ slug へ統合された');
    }

    /** F-4: DB collation に依存せず Application 側で重複を検出する */
    public function test_重複slugをcollationに依存せず検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $this->makeTerm($taxonomy, slug: 'press');

        // 大文字で来ても canonical 化してから比較する
        $this->assertTrue(TermSlug::isTaken($taxonomy->id, 'PRESS'));
        $this->assertTrue(TermSlug::isTaken($taxonomy->id, ' press '));
        $this->assertFalse(TermSlug::isTaken($taxonomy->id, 'news'));
    }

    public function test_別taxonomyのslug衝突は許す(): void
    {
        $a = $this->makeTaxonomy('tax_a');
        $b = $this->makeTaxonomy('tax_b');
        $this->makeTerm($a, slug: 'press');

        $this->assertFalse(TermSlug::isTaken($b->id, 'press'));
    }

    public function test_自分自身は重複扱いにしない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->assertFalse(TermSlug::isTaken($taxonomy->id, 'press', $term->id));
    }

    public function test_slug検証はエラーを日本語で返す(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $this->makeTerm($taxonomy, slug: 'press');

        $this->assertSame([], TermSlug::validate('news', $taxonomy->id));
        $this->assertStringContainsString('空のまま', TermSlug::validate('   ', $taxonomy->id)[0]);
        $this->assertStringContainsString('半角英小文字', TermSlug::validate('プレス', $taxonomy->id)[0]);
        $this->assertStringContainsString('既に使われています', TermSlug::validate('press', $taxonomy->id)[0]);
    }

    /** archived term も slug を占有し続ける */
    public function test_archived_termのslugも解放されない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'press');
        $term->forceFill(['archived_at' => now()])->save();

        $this->assertTrue(TermSlug::isTaken($taxonomy->id, 'press'));
    }

    // ─────────────────────────────────────────────────────────────
    // §D. hierarchy 不変条件
    // ─────────────────────────────────────────────────────────────

    public function test_rootはdepth1(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $root = $this->makeTerm($taxonomy, slug: 'root');
        $child = $this->makeTerm($taxonomy, slug: 'child', parentId: $root->id);
        $grand = $this->makeTerm($taxonomy, slug: 'grand', parentId: $child->id);

        $this->assertSame(1, TermHierarchy::depthOf($root));
        $this->assertSame(2, TermHierarchy::depthOf($child));
        $this->assertSame(3, TermHierarchy::depthOf($grand));
        $this->assertSame(3, TermHierarchy::MAX_TERM_DEPTH);
    }

    public function test_部分木の高さを数えられる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $this->makeTerm($taxonomy, slug: 'c', parentId: $b->id);

        $this->assertSame(3, TermHierarchy::subtreeHeight($a->id));
        $this->assertSame(2, TermHierarchy::subtreeHeight($b->id));
    }

    /** D-1 */
    public function test_階層なしtaxonomyでは親を指定できない(): void
    {
        $flat = $this->makeTaxonomy('flat_tax', hierarchical: false);
        $parent = $this->makeTerm($flat, slug: 'parent');

        $check = TermHierarchy::validateParent($flat, null, $parent->id);

        $this->assertTrue($check->isInvalid());
        $this->assertStringContainsString('階層を使わない', $check->errors[0]);
        $this->assertTrue(TermHierarchy::validateParent($flat, null, null)->isOk());
    }

    /** D-2 */
    public function test_別taxonomyのtermを親にできない(): void
    {
        $a = $this->makeTaxonomy('tax_a');
        $b = $this->makeTaxonomy('tax_b');
        $foreign = $this->makeTerm($b, slug: 'foreign');

        $check = TermHierarchy::validateParent($a, null, $foreign->id);

        $this->assertStringContainsString('同じ分類', $check->errors[0]);
    }

    /** D-3 */
    public function test_自己参照を禁止する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'self');

        $check = TermHierarchy::validateParent($taxonomy, $term, $term->id);

        $this->assertStringContainsString('自分自身', $check->errors[0]);
    }

    /** D-4 */
    public function test_自分の子孫を親にできない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);

        $check = TermHierarchy::validateParent($taxonomy, $a, $b->id);

        $this->assertStringContainsString('自分の下にある', $check->errors[0]);
    }

    /**
     * D-5: 移動対象だけでなく **子孫全体**が上限に収まるかを見る。
     * SPEC §D.3 の具体例そのもの。
     */
    public function test_reparentは子孫全体の深さを検証する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        //  A(1) └ B(2) └ C(3)
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $this->makeTerm($taxonomy, slug: 'c', parentId: $b->id);
        //  D(1) └ E(2)
        $d = $this->makeTerm($taxonomy, slug: 'd');
        $e = $this->makeTerm($taxonomy, slug: 'e', parentId: $d->id);

        // B を E(depth 2) の下へ移すと C が depth 4 になる → 拒否
        $check = TermHierarchy::validateParent($taxonomy, $b, $e->id);

        $this->assertTrue($check->isInvalid(), '子孫が上限を超える移動が通ってしまった');
        $this->assertStringContainsString('4 階層', $check->errors[0]);

        // B を D(depth 1) の下へ移すなら C は depth 3 で収まる → 許可
        $this->assertTrue(TermHierarchy::validateParent($taxonomy, $b, $d->id)->isOk());
    }

    public function test_葉なら深さ3まで新規作成できる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);

        // 新規 term（高さ 1）を depth 2 の下へ = depth 3 → OK
        $this->assertTrue(TermHierarchy::validateParent($taxonomy, null, $b->id)->isOk());

        $c = $this->makeTerm($taxonomy, slug: 'c', parentId: $b->id);

        // depth 3 の下は不可
        $check = TermHierarchy::validateParent($taxonomy, null, $c->id);
        $this->assertStringContainsString('最大 3 階層', $check->errors[0]);
    }

    public function test_存在しない親を拒否する(): void
    {
        $taxonomy = $this->makeTaxonomy();

        $check = TermHierarchy::validateParent($taxonomy, null, TaxonomyId::generate());

        $this->assertStringContainsString('見つかりません', $check->errors[0]);
    }

    /** AR-11: archived term を新しく親に選べない */
    public function test_archived_termを新しい親に選べない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $parent->forceFill(['archived_at' => now()])->save();

        $check = TermHierarchy::validateParent($taxonomy, null, $parent->id);

        $this->assertStringContainsString('アーカイブ済み', $check->errors[0]);
    }

    /** AR-11 の裏返し: 既に archived 親の下にいる子は、その親のままなら維持できる */
    public function test_既にarchived親の下にある子はそのまま維持できる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $child = $this->makeTerm($taxonomy, slug: 'child', parentId: $parent->id);
        $parent->forceFill(['archived_at' => now()])->save();

        $this->assertTrue(TermHierarchy::validateParent($taxonomy, $child, $parent->id)->isOk());
    }

    /** HR-4 / HR-6: archived 親配下の active 子を孤児にしない材料を返す */
    public function test_archived親配下のactive子を列挙できる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $child = $this->makeTerm($taxonomy, slug: 'child', parentId: $parent->id);
        $parent->forceFill(['archived_at' => now()])->save();

        $orphans = TermHierarchy::activeChildrenOfArchivedParents($taxonomy->id);

        $this->assertCount(1, $orphans);
        $this->assertSame($child->id, $orphans->first()?->id);
        // parent_id を null に潰さない
        $this->assertSame($parent->id, $child->fresh()?->parent_id);
    }

    // ─────────────────────────────────────────────────────────────
    // §I.3 Reference Inspector（二段階判定）
    // ─────────────────────────────────────────────────────────────

    public function test_参照ゼロなら削除できる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'unused');

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->allowsDelete());
        $this->assertFalse($report->hasReferences());
        $this->assertFalse($report->isUndetermined());
        $this->assertStringContainsString('参照されていません', $report->summary());
    }

    /** R-1 */
    public function test_assignmentがあれば削除できない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'used');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 7,
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->allowsDelete());
        $this->assertSame(1, $report->total());
        $this->assertSame('assignment', $report->references[0]->kind);
        $this->assertSame(['news#7'], $report->references[0]->samples);
        $this->assertStringContainsString('コンテンツ 1 件', $report->summary());
    }

    /** R-2 */
    public function test_子termがあれば削除できない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $this->makeTerm($taxonomy, slug: 'child', parentId: $parent->id);

        $report = $this->inspector()->referencesToTerm($parent);

        $this->assertFalse($report->allowsDelete());
        $this->assertSame('child_term', $report->references[0]->kind);
    }

    /** R-3 / R-4: PageDocument の binding.filters に term ID があれば参照 */
    public function test_pagedocumentのfilterからの参照を検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'press');
        $this->makePageWithFilter('お知らせ一覧', [
            'taxonomy' => $taxonomy->key,
            'terms' => [$term->id],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->allowsDelete());
        $this->assertSame('page_document', $report->references[0]->kind);
        $this->assertSame(['お知らせ一覧'], $report->references[0]->samples);
    }

    /**
     * **二段階判定の核心**: 本文に同じ ULID 文字列があるだけでは参照にしない。
     * ① の LIKE では候補になるが、② の構造確認で除外される。
     */
    public function test_本文中の同一_uli_d文字列を参照と誤判定しない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'press');

        // binding ではなく text の props に ULID を埋める
        $this->makePage('紛らわしいページ', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'body', 'type' => 'text',
                    'props' => ['text' => "この term の ID は {$term->id} です"],
                    'children' => [],
                ]],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue(
            $report->allowsDelete(),
            '本文中の ULID 文字列を参照と誤判定した（二段階判定が効いていない）',
        );
    }

    /** 別 taxonomy の filter に同じ位置の値があっても混同しない */
    public function test_別termのfilterを参照と数えない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $target = $this->makeTerm($taxonomy, slug: 'target');
        $other = $this->makeTerm($taxonomy, slug: 'other');
        $this->makePageWithFilter('一覧', [
            'taxonomy' => $taxonomy->key,
            'terms' => [$other->id],
        ]);

        $this->assertTrue($this->inspector()->referencesToTerm($target)->allowsDelete());
        $this->assertFalse($this->inspector()->referencesToTerm($other)->allowsDelete());
    }

    /** 深い階層の node に埋まった binding も見つける */
    public function test_入れ子のnodeのfilterも検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'deep');

        $this->makePage('入れ子', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'section', 'type' => 'section',
                    'props' => [], 'children' => [[
                        'id' => TaxonomyId::generate(), 'key' => 'list', 'type' => 'collection',
                        'props' => [],
                        'binding' => [
                            'source' => 'collection', 'type' => 'news',
                            'filters' => [['taxonomy' => $taxonomy->key, 'terms' => [$term->id]]],
                        ],
                        'children' => [],
                    ]],
                ]],
            ],
        ]);

        $this->assertFalse($this->inspector()->referencesToTerm($term)->allowsDelete());
    }

    /** 過去の版（R-4）も対象。draft/published でなくても参照として残る */
    public function test_過去の_page_versionからの参照も検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'old');

        $page = Page::query()->create([
            'slug' => 'history', 'title' => '履歴ページ',
            'status' => PageStatus::Draft->value, 'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        // 古い版だけが term を参照している（draft_version_id には紐づけない）
        PageVersion::query()->create([
            'page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'document' => $this->documentWithFilter([
                'taxonomy' => $taxonomy->key, 'terms' => [$term->id],
            ]),
            'created_interface' => 'admin',
        ]);
        PageVersion::query()->create([
            'page_id' => $page->id, 'revision' => 2, 'schema_version' => 1,
            'document' => ['schemaVersion' => 1, 'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [],
            ]],
            'created_interface' => 'admin',
        ]);

        $this->assertFalse(
            $this->inspector()->referencesToTerm($term)->allowsDelete(),
            '過去の版からの参照（R-4）を見落とした',
        );
    }

    /** AR-8: archive しても削除可否は変わらない */
    public function test_archiveしても削除の_restric_tは緩まない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'used');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $before = $this->inspector()->referencesToTerm($term);
        $term->forceFill(['archived_at' => now()])->save();
        $after = $this->inspector()->referencesToTerm($term->fresh());

        $this->assertFalse($before->allowsDelete());
        $this->assertFalse($after->allowsDelete());
        $this->assertSame($before->total(), $after->total());
    }

    /** 確定できなかった場合は「参照あり」と区別しつつ、削除は止める（fail closed） */
    public function test_確定できない場合はfail_closedで理由を区別する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'unknown');

        $inspector = new ReferenceInspector([new UndeterminedProbeProvider]);
        $report = $inspector->referencesToTerm($term);

        $this->assertFalse($report->allowsDelete(), 'undetermined なのに削除を許した');
        $this->assertFalse($report->hasReferences(), '参照ありと混同している');
        $this->assertTrue($report->isUndetermined());
        $this->assertStringContainsString('確認できませんでした', $report->summary());
        $this->assertStringContainsString('アーカイブ', $report->summary());
    }

    /** R-5 / R-6: provider を足すだけで RESTRICT へ反映される */
    public function test_provider登録で参照の種類を増やせる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'menu-used');

        $inspector = $this->inspector();
        $this->assertTrue($inspector->referencesToTerm($term)->allowsDelete());

        $inspector->register(new FakeMenuReferenceProvider);
        $report = $inspector->referencesToTerm($term);

        $this->assertFalse($report->allowsDelete());
        $this->assertContains('menu', $inspector->kinds());
        $this->assertSame('menu', $report->references[0]->kind);
    }

    public function test_taxonomyへの参照はterm経由でも検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $report = $this->inspector()->referencesToTaxonomy($taxonomy);

        $this->assertFalse($report->allowsDelete());
        $kinds = array_map(static fn ($r) => $r->kind, $report->references);
        $this->assertContains('assignment', $kinds);
        $this->assertContains('child_term', $kinds); // T-1: 配下 term あり
    }

    public function test_inspectorは_d_bを変更しない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'ro');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);
        $this->makePageWithFilter('p', ['taxonomy' => $taxonomy->key, 'terms' => [$term->id]]);

        $before = [
            TaxonomyTerm::query()->orderBy('id')->get()->toArray(),
            TaxonomyTermAssignment::query()->orderBy('id')->get()->toArray(),
            PageVersion::query()->orderBy('id')->get()->toArray(),
        ];

        $this->inspector()->referencesToTerm($term);
        $this->inspector()->referencesToTaxonomy($taxonomy);

        $this->assertEquals($before, [
            TaxonomyTerm::query()->orderBy('id')->get()->toArray(),
            TaxonomyTermAssignment::query()->orderBy('id')->get()->toArray(),
            PageVersion::query()->orderBy('id')->get()->toArray(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION A: term 参照は taxonomy key + term ID の AND 条件
    // ─────────────────────────────────────────────────────────────

    public function test_a1_taxonomy_key一致かつterm_id一致なら参照あり(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');
        $this->makePageWithFilter('一覧', ['taxonomy' => 'news_category', 'terms' => [$term->id]]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable());
        $this->assertTrue($report->isComplete());
        $this->assertSame(['page_document'], $report->kinds());
    }

    public function test_a2_taxonomy_key不一致なら同じterm_idでも数えない(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        // 別 taxonomy key の filter に、たまたま同じ term ID が入っている
        $this->makePageWithFilter('別分類', ['taxonomy' => 'works_category', 'terms' => [$term->id]]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue(
            $report->isDeletable(),
            '別 taxonomy の filter を正当な参照として数えた（AND 条件が効いていない）',
        );
        $this->assertTrue($report->isComplete());
    }

    public function test_a3_taxonomy_key一致でも別term_idなら数えない(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $target = $this->makeTerm($taxonomy, slug: 'target');
        $other = $this->makeTerm($taxonomy, slug: 'other');
        $this->makePageWithFilter('一覧', ['taxonomy' => 'news_category', 'terms' => [$other->id]]);

        $this->assertTrue($this->inspector()->referencesToTerm($target)->isDeletable());
        $this->assertFalse($this->inspector()->referencesToTerm($other)->isDeletable());
    }

    /**
     * taxonomy を解決できない term は undetermined。
     *
     * FK RESTRICT があるため DB 上でこの破損は作れない（それ自体が正しい）。
     * ここでは「taxonomy_id が解決できない term を掴まされた」状況を
     * in-memory モデルで再現し、provider の guard が働くことを固定する。
     */
    public function test_a4_taxonomyを解決できないtermはundetermined(): void
    {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => TaxonomyId::generate(), // どの taxonomy も指していない
            'name' => 'orphan',
            'slug' => 'orphan',
            'sort_order' => 0,
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable(), '分類を解決できないのに削除を許した');
        $this->assertFalse($report->isComplete());
        $this->assertStringContainsString('分類を特定できない', implode(' ', $report->undeterminedReasons()));
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION B: LIKE 候補抽出の driver 互換
    // ─────────────────────────────────────────────────────────────

    /**
     * `blog_category` の `_` を escape すると SQLite で候補が取れなくなる（false negative）。
     *
     * key に `news_category` を使わないのは、STAGE 3C で **news の ContentSchema が
     * それを参照するようになった**ため。ここで見たいのは PageDocument provider の
     * 候補抽出であり、原因を切り分けるために schema から参照されない key を使う。
     */
    public function test_b1_アンダースコアを含むkeyを候補抽出できる(): void
    {
        $taxonomy = $this->makeTaxonomy('blog_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');
        $this->makePageWithFilter('一覧', ['taxonomy' => 'blog_category', 'terms' => [$term->id]]);

        // taxonomy 側（needle = key、`_` を含む）
        $this->assertFalse(
            $this->inspector()->referencesToTaxonomy($taxonomy)->isDeletable(),
            'blog_category を候補抽出できていない（false negative）',
        );
        // term 側（needle = ULID）
        $this->assertFalse($this->inspector()->referencesToTerm($term)->isDeletable());
    }

    /** `_` がワイルドカードとして広く当たっても、構造確認が最終判定なので誤参照にならない */
    public function test_b2_似た文字列が候補になっても誤参照にしない(): void
    {
        $taxonomy = $this->makeTaxonomy('blog_category');

        // `blog_category` の `_` が 1 文字ワイルドカードなら `newsXcategory` も候補になる
        $this->makePageWithFilter('紛らわしい', ['taxonomy' => 'newsXcategory', 'terms' => []]);

        $report = $this->inspector()->referencesToTaxonomy($taxonomy);

        $this->assertTrue($report->isDeletable(), '候補の広がりを参照と誤判定した');
        $this->assertTrue($report->isComplete());
    }

    public function test_b3_本文にtaxonomy_keyがあるだけでは参照にならない(): void
    {
        $taxonomy = $this->makeTaxonomy('blog_category');

        $this->makePage('説明ページ', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'body', 'type' => 'text',
                    'props' => ['text' => 'この分類の key は blog_category です'],
                    'children' => [],
                ]],
            ],
        ]);

        $this->assertTrue($this->inspector()->referencesToTaxonomy($taxonomy)->isDeletable());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION C: malformed PageDocument は fail closed
    // ─────────────────────────────────────────────────────────────

    public function test_c1_正常documentの本文に_uli_dだけなら参照なしで調査完了(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePage('本文のみ', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'body', 'type' => 'text',
                    'props' => ['text' => "ID は {$term->id} です"], 'children' => [],
                ]],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->isDeletable());
        $this->assertTrue($report->isComplete());
    }

    public function test_c2_malformed候補はundeterminedで削除不可(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makeRawVersion('壊れたページ', '{"schemaVersion":1,"root":"これは配列ではない",'
            .'"note":"'.$term->id.'"}');

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable(), 'malformed を参照なしとして削除を許した');
        $this->assertFalse($report->isComplete());
        $this->assertStringContainsString('読み取れなかった', implode(' ', $report->undeterminedReasons()));
    }

    public function test_c3_正常参照とmalformedが同居すると参照ありかつ未完了(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('正常', ['taxonomy' => 'news_category', 'terms' => [$term->id]]);
        $this->makeRawVersion('壊れた', '{"schemaVersion":1,"root":{"children":"配列ではない",'
            .'"note":"'.$term->id.'"}}');

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->hasReferences(), '見つかった参照が捨てられている');
        $this->assertSame(1, $report->totalCount());
        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isDeletable());
    }

    public function test_c4_malformedが無い完全なゼロ件は削除可能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'unused');
        $this->makePageWithFilter('無関係', ['taxonomy' => 'news_category', 'terms' => []]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->isDeletable());
        $this->assertTrue($report->isComplete());
        $this->assertSame([], $report->undeterminedReasons());
    }

    /**
     * JSON として壊れた document は「参照なし」にならない。
     *
     * この保証の担保先は **driver によって違う**（どちらも skip ではなく等価な保証）。
     * - MySQL: `document` が JSON 型なので **保存そのものを DB が拒否**する
     * - SQLite: JSON 型が無く保存できてしまうため **Inspector が fail closed** で受け止める
     */
    public function test_c5_jso_n_decode不能な候補もfail_closed(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');
        $brokenJson = '{"root": {"id": "'.$term->id.'",';

        if (DB::connection()->getDriverName() === 'mysql') {
            // DB 側が壊れた JSON の保存を拒否する = そもそも候補になり得ない
            $this->expectException(QueryException::class);
            $this->makeRawVersion('壊れたJSON', $brokenJson);

            return;
        }

        $this->makeRawVersion('壊れたJSON', $brokenJson);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable());
        $this->assertFalse($report->isComplete());
    }

    /** taxonomy key は一致するが terms が配列でない = 判定不能 */
    public function test_c6_taxonomy一致でtermsが不正なら判定不能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('不正filter', [
            'taxonomy' => 'news_category', 'terms' => $term->id, // 配列でなく文字列
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable());
        $this->assertFalse($report->isComplete());
    }

    /**
     * G の設計判断の裏取り: `page_versions` が無くても
     * 「参照ゼロ」ではなく undetermined になる（taxonomy-check の責務を広げない根拠）
     */
    public function test_c7_page_versionsが無い場合もfail_closed(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('page_versions');
        Schema::enableForeignKeyConstraints();

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isDeletable(), 'page_versions 欠落を参照ゼロと誤判定した');
        $this->assertFalse($report->isComplete());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION D: provider 例外の統一 fail closed
    // ─────────────────────────────────────────────────────────────

    public function test_d_provider例外でも_inspectorは落ちず削除不可になる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'x');

        $inspector = new ReferenceInspector([
            new ThrowingReferenceProvider,      // 例外を投げる
            new FakeMenuReferenceProvider,      // 参照ありを返す
        ]);

        $report = $inspector->referencesToTerm($term);

        // Inspector 全体は例外終了しない
        $this->assertFalse($report->isDeletable());
        $this->assertFalse($report->isComplete());
        // 既知の参照は残る
        $this->assertSame(['menu'], $report->kinds());
        $this->assertSame(2, $report->totalCount());
        // undetermined も残る
        $this->assertCount(1, $report->undeterminedReasons());
        // 秘密情報・SQL・stack trace を出さない
        $reason = $report->undeterminedReasons()[0];
        $this->assertStringNotContainsString('SQLSTATE', $reason);
        $this->assertStringNotContainsString('select ', $reason);
        $this->assertStringNotContainsString('#0 ', $reason);
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION E: ReferenceReport の公開契約
    // ─────────────────────────────────────────────────────────────

    public function test_e_reportの公開契約(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        // 日本語 summary を解析せずに判断できる
        $this->assertFalse($report->isDeletable());
        $this->assertTrue($report->isComplete());
        $this->assertSame(1, $report->totalCount());
        $this->assertSame(['assignment'], $report->kinds());
        $this->assertSame([], $report->undeterminedReasons());
        $this->assertCount(1, $report->references());

        // 互換 API との対応
        $this->assertSame($report->isDeletable(), $report->allowsDelete());
        $this->assertSame($report->totalCount(), $report->total());
        $this->assertSame($report->isComplete(), ! $report->isUndetermined());

        $array = $report->toArray();
        foreach (['complete', 'deletable', 'total_count', 'kinds', 'references',
            'undetermined', 'summary', 'allows_delete', 'total'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    public function test_e_summaryは3状態を区別する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy, slug: 'x');

        // 1. 参照ゼロかつ調査完了
        $this->assertStringContainsString(
            '参照されていません',
            $this->inspector()->referencesToTerm($term)->summary(),
        );

        // 2. 参照あり
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);
        $this->assertStringContainsString(
            '使われています',
            $this->inspector()->referencesToTerm($term)->summary(),
        );

        // 3. 調査未完了
        $incomplete = (new ReferenceInspector([new UndeterminedProbeProvider]))
            ->referencesToTerm($term)->summary();
        $this->assertStringContainsString('確認できませんでした', $incomplete);
        $this->assertStringContainsString('アーカイブ', $incomplete);
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION F: 階層の既存破損データ検出
    // ─────────────────────────────────────────────────────────────

    /**
     * 親が存在しない term は corrupt。
     *
     * FK RESTRICT により DB 上でこの破損は作れない（それ自体が正しい）。
     * 直接 SQL 等で壊された行を掴んだ場合に **途中の depth を返して黙って続行しない**
     * ことを、in-memory モデルで固定する。
     */
    public function test_f1_親が存在しないtermはcorruptになる(): void
    {
        $taxonomy = $this->makeTaxonomy();

        $orphan = new TaxonomyTerm;
        $orphan->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => 'orphan',
            'slug' => 'orphan',
            'parent_id' => TaxonomyId::generate(), // 存在しない親
            'sort_order' => 0,
        ]);

        try {
            TermHierarchy::depthOf($orphan);
            $this->fail('親欠落を正常な階層として扱った');
        } catch (TaxonomyDataCorruptionException $e) {
            $this->assertStringContainsString('見つかりません', $e->getMessage());
        }
    }

    public function test_f2_ancestor_cycleを検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);

        // a の親を b にして循環させる（FK を迂回）
        $this->breakParent($a->id, $b->id);

        $this->expectException(TaxonomyDataCorruptionException::class);
        TermHierarchy::depthOf($a->fresh());
    }

    public function test_f3_descendant_cycleを検出する(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $this->breakParent($a->id, $b->id);

        $this->expectException(TaxonomyDataCorruptionException::class);
        TermHierarchy::descendantIds($a->id);
    }

    public function test_f4_taxonomyをまたぐ既存親子関係を検出する(): void
    {
        $a = $this->makeTaxonomy('tax_a');
        $b = $this->makeTaxonomy('tax_b');
        $parent = $this->makeTerm($a, slug: 'parent');
        $child = $this->makeTerm($b, slug: 'child');

        // 別 taxonomy の term を親にした破損状態
        $this->breakParent($child->id, $parent->id);

        $this->expectException(TaxonomyDataCorruptionException::class);
        TermHierarchy::depthOf($child->fresh());
    }

    public function test_f5_正常な階層はdepth1から3まで数えられる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $c = $this->makeTerm($taxonomy, slug: 'c', parentId: $b->id);

        $this->assertSame(1, TermHierarchy::depthOf($a));
        $this->assertSame(2, TermHierarchy::depthOf($b));
        $this->assertSame(3, TermHierarchy::depthOf($c));
    }

    public function test_f6_破損データがある場合validate_parentはcorruptを返す(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $target = $this->makeTerm($taxonomy, slug: 'target');

        // a ↔ b を循環させる
        $this->breakParent($a->id, $b->id);

        $check = TermHierarchy::validateParent($taxonomy, $target, $b->fresh()->id);

        $this->assertTrue($check->isCorrupt());
        $this->assertFalse($check->isOk());
        // 利用者入力エラーとは区別される
        $this->assertFalse($check->isInvalid());
        $this->assertNotSame([], $check->messages());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION #2 A: taxonomy filter の型不正は malformed
    // ─────────────────────────────────────────────────────────────

    /** A1: taxonomy が配列 = 「別 taxonomy」ではなく malformed */
    public function test_c2a1_taxonomyが配列なら判定不能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('配列', [
            'taxonomy' => ['news_category'],
            'terms' => [$term->id],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isComplete(), '型不正を「別 taxonomy」として無視した');
        $this->assertFalse($report->isDeletable());
    }

    /** A2: taxonomy が数値 */
    public function test_c2a2_taxonomyが数値なら判定不能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('数値', ['taxonomy' => 123, 'terms' => [$term->id]]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isDeletable());
    }

    /** A3: 正常な別 key は complete のまま参照ゼロ */
    public function test_c2a3_正常な別keyは調査完了のまま参照ゼロ(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('別key', [
            'taxonomy' => 'works_category',
            'terms' => [$term->id],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->isComplete(), '正常な別 taxonomy を malformed にした');
        $this->assertTrue($report->isDeletable());
    }

    /** A4: terms 配列に非文字列要素 */
    public function test_c2a4_terms配列の非文字列要素は判定不能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('混在', [
            'taxonomy' => 'news_category',
            'terms' => [$term->id, 42],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isDeletable());
    }

    /** A5: taxonomy キーなしの通常 filter は malformed にしない */
    public function test_c2a5_通常filterはmalformed扱いしない(): void
    {
        $taxonomy = $this->makeTaxonomy('blog_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePageWithFilter('通常filter', [
            'field' => 'status', 'op' => '=', 'value' => 'published',
        ]);

        // taxonomy 側: 子 term が居るので削除は不可だが、**調査は完了**していること
        $taxonomyReport = $this->inspector()->referencesToTaxonomy($taxonomy);
        $this->assertTrue($taxonomyReport->isComplete(), '通常 filter を malformed 扱いした');
        $this->assertSame(['child_term'], $taxonomyReport->kinds());

        // term 側: 参照ゼロで完了 = 削除可能
        $termReport = $this->inspector()->referencesToTerm($term);
        $this->assertTrue($termReport->isComplete());
        $this->assertTrue($termReport->isDeletable());
    }

    /**
     * taxonomy filter を名乗るのに terms が無い。
     *
     * needle（= taxonomy key）が document に含まれるので候補になり、
     * 構造確認で malformed と判定される。
     */
    public function test_c2a6_terms欠落は判定不能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');

        $this->makePageWithFilter('terms無し', ['taxonomy' => 'news_category']);

        $report = $this->inspector()->referencesToTaxonomy($taxonomy);

        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isDeletable());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION #2 B: document 全体を検証してから complete を確定
    // ─────────────────────────────────────────────────────────────

    /** B1: 同一 PageVersion の前半に正常参照、後半に不正 children */
    public function test_c2b1_同一documentの後半の不正childrenも検出する(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePage('前半正常後半不正', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [
                    // 前半: 正常な参照
                    [
                        'id' => TaxonomyId::generate(), 'key' => 'l', 'type' => 'collection',
                        'props' => [],
                        'binding' => ['source' => 'collection', 'type' => 'news',
                            'filters' => [['taxonomy' => 'news_category', 'terms' => [$term->id]]]],
                        'children' => [],
                    ],
                    // 後半: children が配列でない
                    [
                        'id' => TaxonomyId::generate(), 'key' => 's', 'type' => 'section',
                        'props' => [], 'children' => 'これは配列ではない',
                    ],
                ],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->hasReferences(), '前半の正常参照を落とした');
        $this->assertSame(1, $report->totalCount());
        $this->assertFalse($report->isComplete(), '一致で走査を打ち切って後半を見ていない');
        $this->assertFalse($report->isDeletable());
    }

    /** B2: 同一 filters 配列の前半に正常参照、後半に不正 filter */
    public function test_c2b2_同一filters配列の後半の不正も検出する(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePage('filters前半正常', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'l', 'type' => 'collection',
                    'props' => [],
                    'binding' => ['source' => 'collection', 'type' => 'news', 'filters' => [
                        ['taxonomy' => 'news_category', 'terms' => [$term->id]], // 正常一致
                        ['taxonomy' => 999, 'terms' => []],                       // 型不正
                    ]],
                    'children' => [],
                ]],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->hasReferences());
        $this->assertSame(1, $report->totalCount());
        $this->assertFalse($report->isComplete());
    }

    /** B3: 前半も後半も正常 */
    public function test_c2b3_全体が正常なら調査完了(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePage('全体正常', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [
                    [
                        'id' => TaxonomyId::generate(), 'key' => 'l', 'type' => 'collection',
                        'props' => [],
                        'binding' => ['source' => 'collection', 'type' => 'news',
                            'filters' => [['taxonomy' => 'news_category', 'terms' => [$term->id]]]],
                        'children' => [],
                    ],
                    [
                        'id' => TaxonomyId::generate(), 'key' => 't', 'type' => 'text',
                        'props' => ['text' => 'あとがき'], 'children' => [],
                    ],
                ],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertTrue($report->hasReferences());
        $this->assertTrue($report->isComplete());
        $this->assertFalse($report->isDeletable());
    }

    /** B4: 正常な非参照 document */
    public function test_c2b4_正常な非参照documentは削除可能(): void
    {
        $taxonomy = $this->makeTaxonomy('news_category');
        $term = $this->makeTerm($taxonomy, slug: 'press');

        $this->makePage('非参照', [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 't', 'type' => 'text',
                    'props' => ['text' => "参考: {$term->id}"], 'children' => [],
                ]],
            ],
        ]);

        $report = $this->inspector()->referencesToTerm($term);

        $this->assertFalse($report->hasReferences());
        $this->assertTrue($report->isComplete());
        $this->assertTrue($report->isDeletable());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION #2 C: root 移動時も階層破損を検出する
    // ─────────────────────────────────────────────────────────────

    /** C1: 正常な既存 term を root へ移動 */
    public function test_c2c1_正常なtermのroot移動はok(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);

        $this->assertTrue(TermHierarchy::validateParent($taxonomy, $b, null)->isOk());
    }

    /** C2: descendant cycle を持つ term を root へ移動 */
    public function test_c2c2_descendant_cycleを持つtermのroot移動はcorrupt(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);
        $this->breakParent($a->id, $b->id); // a ↔ b

        $check = TermHierarchy::validateParent($taxonomy, $a->fresh(), null);

        $this->assertTrue($check->isCorrupt(), 'root 移動で部分木の破損を見逃した');
        $this->assertFalse($check->isOk());
    }

    /** C3: taxonomy をまたぐ子を持つ term を root へ移動 */
    public function test_c2c3_taxonomyまたぎの子を持つtermのroot移動はcorrupt(): void
    {
        $a = $this->makeTaxonomy('tax_a');
        $b = $this->makeTaxonomy('tax_b');
        $parent = $this->makeTerm($a, slug: 'parent');
        $foreignChild = $this->makeTerm($b, slug: 'child');
        $this->breakParent($foreignChild->id, $parent->id);

        $check = TermHierarchy::validateParent($a, $parent, null);

        $this->assertTrue($check->isCorrupt());
    }

    /** C4: DB に存在しない term model */
    public function test_c2c4_d_bに無いtermはcorrupt(): void
    {
        $taxonomy = $this->makeTaxonomy();

        $ghost = new TaxonomyTerm;
        $ghost->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => 'ghost', 'slug' => 'ghost', 'sort_order' => 0,
        ]);

        $check = TermHierarchy::validateParent($taxonomy, $ghost, null);

        $this->assertTrue($check->isCorrupt());
        $this->assertStringContainsString('存在しません', (string) $check->corruption);
    }

    /** C5: 新規作成（term = null・parent なし） */
    public function test_c2c5_新規作成でparentなしはok(): void
    {
        $taxonomy = $this->makeTaxonomy();

        $this->assertTrue(TermHierarchy::validateParent($taxonomy, null, null)->isOk());
        $this->assertTrue(TermHierarchy::validateSubtreeIntegrity(null)->isOk());
    }

    /** C6: 非階層 taxonomy でも既存破損を黙って ok にしない */
    public function test_c2c6_非階層taxonomyでも破損を見逃さない(): void
    {
        $flat = $this->makeTaxonomy('flat_tax', hierarchical: false);
        $a = $this->makeTerm($flat, slug: 'a');
        $b = $this->makeTerm($flat, slug: 'b');
        // 非階層なのに親子ができており、さらに循環している
        $this->breakParent($a->id, $b->id);
        $this->breakParent($b->id, $a->id);

        $check = TermHierarchy::validateParent($flat, $a->fresh(), null);

        $this->assertTrue($check->isCorrupt(), '非階層 taxonomy で破損を素通しした');
    }

    /** 公開契約: validateSubtreeIntegrity 単体でも同じ判定ができる */
    public function test_c2c7_validate_subtree_integrityは単体でも呼べる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $a = $this->makeTerm($taxonomy, slug: 'a');
        $b = $this->makeTerm($taxonomy, slug: 'b', parentId: $a->id);

        $this->assertTrue(TermHierarchy::validateSubtreeIntegrity($a)->isOk());

        $this->breakParent($a->id, $b->id);

        $this->assertTrue(TermHierarchy::validateSubtreeIntegrity($a->fresh())->isCorrupt());
    }

    // ─────────────────────────────────────────────────────────────
    // STAGE 3 以降の surface を先行公開していないこと
    // ─────────────────────────────────────────────────────────────

    public function test_公開面は分類7操作だけ(): void
    {

        // STAGE 2B の Application Service が #14 / #15 で公開面へ接続された
        foreach ([
            'AgentCms\Core\Application\Taxonomy\CreateTerm',
            'AgentCms\Core\Application\Taxonomy\UpdateTerm',
            'AgentCms\Core\Application\Taxonomy\DeleteTerm',
            'AgentCms\Core\Application\Taxonomy\ReorderTerms',
        ] as $operation) {
            $this->assertTrue(class_exists($operation), "{$operation} が見つからない");
        }

        // 分類そのものの CRUD は公開しない（Phase 9.4 へ延期）
        foreach (['taxonomy.create', 'taxonomy.update', 'taxonomy.delete'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function inspector(): ReferenceInspector
    {
        return app(ReferenceInspector::class);
    }

    private function makeTaxonomy(string $key = 'demo_tax', bool $hierarchical = true): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(),
            'key' => $key,
            'label' => 'デモ',
            'hierarchical' => $hierarchical,
            'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();

        return $taxonomy;
    }

    private function makeTerm(
        Taxonomy $taxonomy,
        string $slug = 'demo',
        ?string $parentId = null,
    ): TaxonomyTerm {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => $slug,
            'slug' => $slug,
            'parent_id' => $parentId,
            'sort_order' => 0,
        ])->save();

        return $term;
    }

    /** @param array<string, mixed> $filter */
    private function documentWithFilter(array $filter): array
    {
        return [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => 'list', 'type' => 'collection',
                    'props' => [],
                    'binding' => [
                        'source' => 'collection', 'type' => 'news', 'filters' => [$filter],
                    ],
                    'children' => [],
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $filter */
    private function makePageWithFilter(string $title, array $filter): Page
    {
        return $this->makePage($title, $this->documentWithFilter($filter));
    }

    /** 既存データを壊す（FK を迂回して破損状態を作る。テスト DB のみ） */
    private function breakParent(string $termId, ?string $parentId): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            DB::table('taxonomy_terms')->where('id', $termId)->update(['parent_id' => $parentId]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** document を生の JSON 文字列として保存する（malformed を作るため） */
    private function makeRawVersion(string $title, string $rawJson): Page
    {
        $page = Page::query()->create([
            'slug' => 'raw-'.Str::random(8),
            'title' => $title,
            'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        DB::table('page_versions')->insert([
            'page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'document' => $rawJson, 'created_interface' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $page;
    }

    /** @param array<string, mixed> $document */
    private function makePage(string $title, array $document): Page
    {
        $page = Page::query()->create([
            'slug' => Str::slug($title) ?: 'p-'.uniqid(),
            'title' => $title,
            'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $version = PageVersion::query()->create([
            'page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'document' => $document, 'created_interface' => 'admin',
        ]);

        $page->forceFill(['draft_version_id' => $version->id])->save();

        return $page;
    }
}

/** テスト用: 常に「確定できなかった」を返す provider */
final class UndeterminedProbeProvider implements ReferenceProvider
{
    public function kind(): string
    {
        return 'flaky';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        return ReferenceProbe::undetermined('時間内に確認できませんでした。');
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        return ReferenceProbe::undetermined('時間内に確認できませんでした。');
    }
}

/** テスト用: 将来の menu 参照を模した provider（R-6 の拡張点） */
final class FakeMenuReferenceProvider implements ReferenceProvider
{
    public function kind(): string
    {
        return 'menu';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        return ReferenceProbe::found(2, ['グローバルナビ']);
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        return ReferenceProbe::found(2, ['グローバルナビ']);
    }
}

/** テスト用: 常に例外を投げる provider（CORRECTION D） */
final class ThrowingReferenceProvider implements ReferenceProvider
{
    public function kind(): string
    {
        return 'exploding';
    }

    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        throw new \RuntimeException('SQLSTATE[42S02]: select * from secret where password = ?');
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        throw new \RuntimeException('SQLSTATE[42S02]: select * from secret where password = ?');
    }
}
