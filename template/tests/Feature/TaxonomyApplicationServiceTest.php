<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\ListTaxonomies;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\Reference\ReferenceProbe;
use AgentCms\Core\Taxonomy\Reference\ReferenceProvider;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 2B の受け入れ — Application Service 7 操作。
 *
 * 対象は SPEC §AF 計画 **#7 / #8**。
 * OperationCatalog / API / MCP / Admin への公開は STAGE 3 以降であり、
 * ここでは「まだ公開していない」ことも固定する。
 */
final class TaxonomyApplicationServiceTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private OperationContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = OperationContext::admin($this->operatorId(), 'admin');
        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // taxonomy read
    // ─────────────────────────────────────────────────────────────

    /** 1 */
    public function test_taxonomy_listは_d_b_writeを発生させない(): void
    {
        /** @var list<string> $statements */
        $statements = [];
        DB::listen(static function ($q) use (&$statements): void {
            $statements[] = strtolower(trim($q->sql));
        });

        $result = app(ListTaxonomies::class)->execute($this->admin);

        $this->assertTrue($result->isSuccess());

        foreach ($statements as $sql) {
            foreach (['insert ', 'update ', 'delete ', 'create table', 'alter table'] as $write) {
                $this->assertStringNotContainsString($write, $sql, "書き込み SQL を実行した: {$sql}");
            }
        }
    }

    public function test_taxonomy_listは件数を返す(): void
    {
        $taxonomy = $this->taxonomy();
        $this->makeTerm($taxonomy, 'a');
        $archived = $this->makeTerm($taxonomy, 'b');
        $archived->forceFill(['archived_at' => now()])->save();

        $result = app(ListTaxonomies::class)->execute($this->admin);
        $row = $result->data['taxonomies'][0];

        $this->assertSame('news_category', $row['key']);
        $this->assertSame('カテゴリ', $row['label']);
        $this->assertTrue($row['hierarchical']);
        $this->assertSame('code', $row['managedBy']);
        $this->assertSame(2, $row['termCount']);
        $this->assertSame(1, $row['activeTermCount']);
        $this->assertSame(1, $row['archivedTermCount']);
        $this->assertArrayHasKey('id', $row);
    }

    /** 2 */
    public function test_taxonomy_getは未同期を自動作成しない(): void
    {
        Taxonomy::query()->delete();

        $result = app(GetTaxonomy::class)->execute('news_category', $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('taxonomy-sync', (string) $result->message);
        $this->assertSame(0, Taxonomy::query()->count());
    }

    public function test_taxonomy_getは未登録keyをnot_foundにする(): void
    {
        $result = app(GetTaxonomy::class)->execute('unknown_key', $this->admin);

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }

    /** 3 */
    public function test_managed_by競合を黙って修復しない(): void
    {
        Taxonomy::query()->where('key', 'news_category')
            ->update(['managed_by' => TaxonomyManagedBy::Cms->value]);

        $result = app(GetTaxonomy::class)->execute('news_category', $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(
            TaxonomyManagedBy::Cms,
            Taxonomy::query()->where('key', 'news_category')->firstOrFail()->managed_by,
        );
    }

    /** 4: taxonomy 自体の mutation service を作っていない */
    public function test_taxonomyのmutation_serviceが存在しない(): void
    {
        foreach ([
            'AgentCms\Core\Application\Taxonomy\CreateTaxonomy',
            'AgentCms\Core\Application\Taxonomy\UpdateTaxonomy',
            'AgentCms\Core\Application\Taxonomy\DeleteTaxonomy',
        ] as $class) {
            $this->assertFalse(class_exists($class), "{$class} を先行実装している");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // term.list
    // ─────────────────────────────────────────────────────────────

    /** 5 / 6 */
    public function test_term_listの既定はactiveのみでinclude_archivedで両方返る(): void
    {
        $taxonomy = $this->taxonomy();
        $this->makeTerm($taxonomy, 'active');
        $archived = $this->makeTerm($taxonomy, 'archived');
        $archived->forceFill(['archived_at' => now()])->save();

        $default = app(ListTerms::class)->execute('news_category', $this->admin);
        $this->assertCount(1, $default->data['terms']);
        $this->assertFalse($default->meta['treeComplete']);

        $all = app(ListTerms::class)->execute('news_category', $this->admin, includeArchived: true);
        $this->assertCount(2, $all->data['terms']);
        $this->assertTrue($all->meta['treeComplete']);

        $archivedRow = collect($all->data['terms'])->firstWhere('archived', true);
        $this->assertNotNull($archivedRow['archivedAt']);
        $this->assertSame('archived', $archivedRow['name']); // AR-6: name を消さない
    }

    /** 7: HR-6 */
    public function test_archived親配下のactive子のparent_idを維持する(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $parent->id);
        $parent->forceFill(['archived_at' => now()])->save();

        $result = app(ListTerms::class)->execute('news_category', $this->admin);

        $this->assertCount(1, $result->data['terms']);
        $row = $result->data['terms'][0];
        $this->assertSame('child', $row['name']);
        $this->assertSame($parent->id, $row['parentId'], 'parentId を null へ潰した');
    }

    /** 8 */
    public function test_term_listはsort_order順で安定している(): void
    {
        $taxonomy = $this->taxonomy();
        // 同じ sort_order を持たせて tie-breaker を確かめる
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 0);
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $c = $this->makeTerm($taxonomy, 'c', sortOrder: 1);

        $ids = collect(app(ListTerms::class)->execute('news_category', $this->admin)->data['terms'])
            ->pluck('id')->all();

        $expected = collect([$a->id, $b->id])->sort()->values()->all();
        $this->assertSame([$expected[0], $expected[1], $c->id], $ids);
    }

    // ─────────────────────────────────────────────────────────────
    // term.create
    // ─────────────────────────────────────────────────────────────

    /** 9 */
    public function test_term_createの正常作成(): void
    {
        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => 'Press Release']);

        $this->assertTrue($result->isSuccess());
        $term = $result->data['term'];
        $this->assertSame('Press Release', $term['name']);
        $this->assertSame('press-release', $term['slug']);
        $this->assertFalse($term['archived']);
        $this->assertSame(0, $term['sortOrder']);
        $this->assertTrue(TaxonomyId::isValid($term['id']));
    }

    /** 10 */
    public function test_日本語名からfallback_slugを作る(): void
    {
        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => 'お知らせ']);

        $this->assertTrue($result->isSuccess());
        $this->assertStringStartsWith('term-', $result->data['term']['slug']);
        $this->assertTrue($result->data['term']['generatedSlug']);
    }

    /** 11 */
    public function test_明示slugをcanonicalizeする(): void
    {
        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'Press', 'slug' => '  PRESS  ',
        ]);

        $this->assertSame('press', $result->data['term']['slug']);
    }

    /** 12 */
    public function test_不正slugを拒否する(): void
    {
        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'Press', 'slug' => 'プレス',
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('slug', $result->errors);
    }

    /** 13 */
    public function test_同一taxonomy内のslug重複を拒否する(): void
    {
        $this->create(['taxonomyKey' => 'news_category', 'name' => 'Press', 'slug' => 'press']);
        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => '別', 'slug' => 'PRESS']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 14 */
    public function test_別taxonomyの同slugは許可する(): void
    {
        $other = $this->makeTaxonomy('other_tax');
        $this->create(['taxonomyKey' => 'news_category', 'name' => 'Press', 'slug' => 'press']);

        $result = $this->create(['taxonomyKey' => 'other_tax', 'name' => 'Press', 'slug' => 'press']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($other->id, $result->data['term']['taxonomyId']);
    }

    /** 15 */
    public function test_archived親を新規作成で拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $parent->forceFill(['archived_at' => now()])->save();

        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'child', 'parentId' => $parent->id,
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('アーカイブ済み', $result->errors['parentId'][0]);
    }

    /** 16 */
    public function test_非階層taxonomyでparentを拒否する(): void
    {
        $flat = $this->makeTaxonomy('flat_tax', hierarchical: false);
        $parent = $this->makeTerm($flat, 'p');

        $result = $this->create([
            'taxonomyKey' => 'flat_tax', 'name' => 'child', 'parentId' => $parent->id,
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 17 */
    public function test_depth4を拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $b = $this->makeTerm($taxonomy, 'b', parentId: $a->id);
        $c = $this->makeTerm($taxonomy, 'c', parentId: $b->id);

        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'd', 'parentId' => $c->id,
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('最大 3 階層', $result->errors['parentId'][0]);
    }

    /** 18 */
    public function test_policy拒否でforbiddenになる(): void
    {
        // 未ログインの Admin / API は write 不可（DefaultOperationPolicy）
        $anonymous = OperationContext::admin(null, '');

        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => 'x'], $anonymous);

        $this->assertSame(OperationStatus::Forbidden, $result->status);
        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /** 19 */
    public function test_createが_auditを記録する(): void
    {
        $this->create(['taxonomyKey' => 'news_category', 'name' => 'Press']);

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'term.create',
            'resource_type' => 'taxonomy_term',
        ]);
    }

    public function test_空名を拒否する(): void
    {
        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => '   ']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_同じ親の末尾へsort_orderが振られる(): void
    {
        $a = $this->create(['taxonomyKey' => 'news_category', 'name' => 'a']);
        $b = $this->create(['taxonomyKey' => 'news_category', 'name' => 'b']);

        $this->assertSame(0, $a->data['term']['sortOrder']);
        $this->assertSame(1, $b->data['term']['sortOrder']);
    }

    // ─────────────────────────────────────────────────────────────
    // term.update
    // ─────────────────────────────────────────────────────────────

    /** 20 */
    public function test_name_slug_descriptionを更新する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'old');

        $result = app(UpdateTerm::class)->execute($term->id, [
            'name' => '新しい名前', 'slug' => 'NEW-SLUG', 'description' => '説明',
        ], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('新しい名前', $result->data['term']['name']);
        $this->assertSame('new-slug', $result->data['term']['slug']);
        $this->assertSame('説明', $result->data['term']['description']);
    }

    /** 21 */
    public function test_parent変更で子孫を維持する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $b = $this->makeTerm($taxonomy, 'b', parentId: $a->id);
        $c = $this->makeTerm($taxonomy, 'c', parentId: $b->id);
        $d = $this->makeTerm($taxonomy, 'd');

        $result = app(UpdateTerm::class)->execute($b->id, ['parentId' => $d->id], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($d->id, $b->fresh()?->parent_id);
        // 子孫は再配置しない（D-6）
        $this->assertSame($b->id, $c->fresh()?->parent_id);
    }

    /** 22 */
    public function test_子孫込みのdepth超過を拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $b = $this->makeTerm($taxonomy, 'b', parentId: $a->id);
        $this->makeTerm($taxonomy, 'c', parentId: $b->id);
        $d = $this->makeTerm($taxonomy, 'd');
        $e = $this->makeTerm($taxonomy, 'e', parentId: $d->id);

        $result = app(UpdateTerm::class)->execute($b->id, ['parentId' => $e->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('4 階層', $result->errors['parentId'][0]);
        $this->assertSame($a->id, $b->fresh()?->parent_id);
    }

    /** 23 / 24 / 25 / 26 */
    public function test_archiveはassignmentも_page_documentも子も変えない(): void
    {
        $taxonomy = $this->taxonomy();
        $term = $this->makeTerm($taxonomy, 'press');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $term->id);

        $assignment = TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);
        $page = $this->makePageWithFilter('一覧', [
            'taxonomy' => 'news_category', 'terms' => [$term->id],
        ]);
        $documentBefore = PageVersion::query()->where('page_id', $page->id)->first()?->document;

        $result = app(UpdateTerm::class)->execute($term->id, ['archived' => true], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['term']['archived']);
        // AR-4: assignment は変わらない
        $this->assertNotNull($assignment->fresh());
        $this->assertSame(1, TaxonomyTermAssignment::query()->count());
        // AR-5: PageDocument は書き換えない
        $this->assertEquals($documentBefore, PageVersion::query()->where('page_id', $page->id)->first()?->document);
        // AR-6: name / slug は残る
        $this->assertSame('press', $term->fresh()?->name);
        // AR-10: 子は自動 archive しない
        $this->assertNull($child->fresh()?->archived_at);
        $this->assertSame(1, $result->meta['activeChildCount']);
        $this->assertTrue($result->meta['childrenRemainActive']);
    }

    /** 27 */
    public function test_unarchiveはarchived_atをnullへ戻す(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'press');
        $term->forceFill(['archived_at' => now()])->save();

        $result = app(UpdateTerm::class)->execute($term->id, ['archived' => false], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['term']['archived']);
        $this->assertNull($term->fresh()?->archived_at);
    }

    /** 28 */
    public function test_archiveとunarchiveを_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'press');

        app(UpdateTerm::class)->execute($term->id, ['archived' => true], $this->admin);
        app(UpdateTerm::class)->execute($term->id, ['archived' => false], $this->admin);

        $actions = DB::table('audit_logs')
            ->where('operation', 'term.update')
            ->pluck('metadata')
            ->map(static fn ($m) => json_decode((string) $m, true)['action'] ?? null)
            ->all();

        $this->assertContains('archive', $actions);
        $this->assertContains('unarchive', $actions);
    }

    /** 29 */
    public function test_archived親を新しい親として拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $term = $this->makeTerm($taxonomy, 'x');
        $parent->forceFill(['archived_at' => now()])->save();

        $result = app(UpdateTerm::class)->execute($term->id, ['parentId' => $parent->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 30 */
    public function test_既存のarchived親を維持した通常更新は許可する(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $parent->id);
        $parent->forceFill(['archived_at' => now()])->save();

        $result = app(UpdateTerm::class)->execute($child->id, ['name' => '新名'], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($parent->id, $child->fresh()?->parent_id);
    }

    /** 31 */
    public function test_identityやtaxonomy_idやsort_orderの変更を拒否する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        foreach (['id', 'taxonomyId', 'managedBy', 'sortOrder', 'archivedAt'] as $key) {
            $result = app(UpdateTerm::class)->execute($term->id, [$key => 'v'], $this->admin);

            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$key} を受理した");
            $this->assertArrayHasKey($key, $result->errors);
        }
    }

    /** 32 */
    public function test_空_patc_hを拒否する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $result = app(UpdateTerm::class)->execute($term->id, [], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 33 */
    public function test_階層破損は安全側で拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $b = $this->makeTerm($taxonomy, 'b', parentId: $a->id);
        DB::table('taxonomy_terms')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $result = app(UpdateTerm::class)->execute($a->id, ['name' => '新名'], $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame('a', $a->fresh()?->name, '破損状態で更新してしまった');
    }

    public function test_同値のarchive更新はidempotent(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $first = app(UpdateTerm::class)->execute($term->id, ['archived' => false], $this->admin);

        $this->assertTrue($first->isSuccess());
        $this->assertNull($first->meta['action']);
        $this->assertNull($term->fresh()?->archived_at);
    }

    // ─────────────────────────────────────────────────────────────
    // term.reorder
    // ─────────────────────────────────────────────────────────────

    /** 34 / 35 / 43 */
    public function test_reorderは兄弟全件でarchivedも含めて並べ替える(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);
        $c = $this->makeTerm($taxonomy, 'c', sortOrder: 2);
        $c->forceFill(['archived_at' => now()])->save();

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, [$c->id, $a->id, $b->id], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $c->fresh()?->sort_order);
        $this->assertSame(1, $a->fresh()?->sort_order);
        $this->assertSame(2, $b->fresh()?->sort_order);
        // parentId / archived を変えない
        $this->assertNull($a->fresh()?->parent_id);
        $this->assertNotNull($c->fresh()?->archived_at);
    }

    /** 36 */
    public function test_reorderは_i_d重複を拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, [$a->id, $a->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 37 */
    public function test_reorderは_i_d欠落を拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        $result = app(ReorderTerms::class)->execute('news_category', null, [$a->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('すべて含めて', $result->errors['orderedTermIds'][0]);
    }

    /** 38 */
    public function test_reorderは余分な_i_dを拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, [$a->id, TaxonomyId::generate()], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 39 */
    public function test_reorderは別taxonomyの_i_dを拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $other = $this->makeTaxonomy('other_tax');
        $foreign = $this->makeTerm($other, 'foreign');

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, [$a->id, $foreign->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 40 */
    public function test_reorderは別parentの_i_dを拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $root = $this->makeTerm($taxonomy, 'root');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $root->id);

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, [$root->id, $child->id], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** 41: 検証失敗時に一部だけ更新された状態を残さない */
    public function test_reorder失敗時にsort_orderを変更しない(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        app(ReorderTerms::class)->execute('news_category', null, [$b->id], $this->admin);

        $this->assertSame(0, $a->fresh()?->sort_order);
        $this->assertSame(1, $b->fresh()?->sort_order);
    }

    /** 42 */
    public function test_reorderはidempotent(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        app(ReorderTerms::class)->execute('news_category', null, [$b->id, $a->id], $this->admin);
        app(ReorderTerms::class)->execute('news_category', null, [$b->id, $a->id], $this->admin);

        $this->assertSame(0, $b->fresh()?->sort_order);
        $this->assertSame(1, $a->fresh()?->sort_order);
    }

    /**
     * 44: 並べ替えの Audit は **要約 1 件だけ**（SPEC §AC）。
     *
     * before / after の全 ID を残していたが、兄弟が増えるほど 1 回の操作で
     * ログが膨らみ、読みたい「何件動いたか」が埋もれる。
     */
    public function test_reorderの_auditは要約1件だけ(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);
        $c = $this->makeTerm($taxonomy, 'c', sortOrder: 2);

        // a と b だけ入れ替える（c は動かない）
        app(ReorderTerms::class)
            ->execute('news_category', null, [$b->id, $a->id, $c->id], $this->admin);

        $rows = DB::table('audit_logs')->where('operation', 'term.reorder')->get();

        $this->assertCount(1, $rows, 'term ごとに記録している');

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $rows[0]->metadata, true);

        /*
         * キー集合そのものを固定する。**ID の配列を増やさせない**。
         *
         * **並びは見ない** — MySQL の JSON 列はキー順を正規化する。
         * ここが決めているのは「何を残すか」であって、並びは保存側の都合である。
         */
        $this->assertEqualsCanonicalizing(
            ['taxonomy', 'parentId', 'moved', 'total'],
            array_keys($metadata),
        );
        $this->assertSame('news_category', $metadata['taxonomy']);
        $this->assertNull($metadata['parentId']);
        $this->assertSame(2, $metadata['moved'], '位置が変わったのは a と b の 2 件');
        $this->assertSame(3, $metadata['total']);
    }

    /** 44b: 同じ並びを送り直したら moved は 0 */
    public function test_reorderで位置が変わらなければmovedは0(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        app(ReorderTerms::class)->execute('news_category', null, [$a->id, $b->id], $this->admin);

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode(
            (string) DB::table('audit_logs')->where('operation', 'term.reorder')->value('metadata'),
            true,
        );

        $this->assertSame(0, $metadata['moved']);
        $this->assertSame(2, $metadata['total']);
    }

    // ─────────────────────────────────────────────────────────────
    // term.delete
    // ─────────────────────────────────────────────────────────────

    /** 45 / 50 */
    public function test_assignment参照で_conflic_tになりconfirmationを発行しない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'used');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNull($result->confirmation, '参照ありなのに confirmation を発行した');
        $this->assertArrayHasKey('references', (array) $result->data);
        $this->assertStringContainsString('アーカイブ', (string) $result->message);
        $this->assertNotNull($term->fresh());
    }

    /** 46 */
    public function test_子term参照で_conflic_tになる(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $this->makeTerm($taxonomy, 'child', parentId: $parent->id);

        $result = app(DeleteTerm::class)->execute($parent->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
    }

    /** 47 */
    public function test_page_document参照で_conflic_tになる(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'used');
        $this->makePageWithFilter('一覧', ['taxonomy' => 'news_category', 'terms' => [$term->id]]);

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
    }

    /** 48 */
    public function test_過去の_page_version参照で_conflic_tになる(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'used');
        $page = Page::query()->create([
            'slug' => 'history', 'title' => '履歴', 'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);
        // 古い版だけが参照している（draft_version_id には紐づけない）
        PageVersion::query()->create([
            'page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'created_interface' => 'admin',
            'document' => $this->documentWithFilter(['taxonomy' => 'news_category', 'terms' => [$term->id]]),
        ]);

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
    }

    /** 49 */
    public function test_undeterminedで_conflic_tになる(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        // page_versions を落として調査不能にする
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('page_versions');
        Schema::enableForeignKeyConstraints();

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNull($result->confirmation);
        $this->assertStringContainsString('安全のため', (string) $result->message);
        $this->assertNotNull($term->fresh());
    }

    /** 51 / 52 */
    public function test_参照ゼロならconfirmation_requiredでtokenなしでは削除しない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'unused');

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotNull($result->confirmation);
        $this->assertNotNull($term->fresh(), 'confirmation 前に削除した');
    }

    /** 53 / 58 */
    public function test_正しいtokenで削除し_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'unused');

        $first = app(DeleteTerm::class)->execute($term->id, $this->admin);
        $token = $first->confirmation?->token;

        $result = app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($token));

        $this->assertTrue($result->isSuccess());
        $this->assertNull($term->fresh());
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'term.delete', 'resource_id' => $term->id,
        ]);
    }

    /** 54 */
    public function test_別termのtokenを再利用できない(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a');
        $b = $this->makeTerm($taxonomy, 'b');

        $token = app(DeleteTerm::class)->execute($a->id, $this->admin)->confirmation?->token;

        $result = app(DeleteTerm::class)
            ->execute($b->id, $this->admin->withConfirmationToken($token));

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotNull($b->fresh());
    }

    /** 55 */
    public function test_別actorのtokenを再利用できない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $token = app(DeleteTerm::class)->execute($term->id, $this->admin)->confirmation?->token;

        // 別の実在する利用者（STAGE #18A: actor は実在して有効である必要がある）
        $otherUser = User::factory()->create();
        $other = OperationContext::admin((int) $otherUser->getKey(), 'other')->withConfirmationToken($token);
        $result = app(DeleteTerm::class)->execute($term->id, $other);

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotNull($term->fresh());
    }

    /** 56: 確認後の再検査で新しい参照が見つかったら削除しない */
    public function test_確認後に新しい参照ができたら削除しない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $token = app(DeleteTerm::class)->execute($term->id, $this->admin)->confirmation?->token;

        // 確認と実行の間に参照が生まれる
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $result = app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($token));

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNotNull($term->fresh(), '再検査を経ずに削除した');
    }

    /** 57 */
    public function test_archived_termでも_restric_t条件は同じ(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);
        $term->forceFill(['archived_at' => now()])->save();

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNotNull($term->fresh());
    }

    /** 59: DB の FK RESTRICT は最後の防御として残っている */
    public function test_d_b_f_kの_restric_tが維持されている(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $this->expectException(QueryException::class);
        DB::table('taxonomy_terms')->where('id', $term->id)->delete();
    }

    // ─────────────────────────────────────────────────────────────
    // term.delete — 拒否された削除の Audit（SPEC §AC）
    // ─────────────────────────────────────────────────────────────

    /**
     * 参照があって拒否された削除も記録する。
     *
     * 成功した削除だけを残すと、**消そうとして止められた試行が監査から消える**。
     * 誰が何を消そうとしたかは、成功した削除と同じかそれ以上に知りたい情報である。
     */
    public function test_参照ありで拒否された削除を_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'used');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);

        $row = $this->auditRow('term.delete', 'conflict');

        $this->assertSame(OperationStatus::Conflict->value, $row->result);
        $this->assertSame($term->id, $row->resource_id);
        $this->assertSame('taxonomy_term', $row->resource_type);
        $this->assertSame('admin', $row->interface);
        $this->assertSame('destructive', $row->classification);

        /*
         * 並びは保存側の都合（MySQL の JSON はキー順を正規化する）。中身で比べる。
         *
         * **緩めたのは並びだけで、型ではない。** `assertEquals` は `5` と `"5"` を
         * 通すが、型は直前の `assertSame` の並びが押さえている。
         */
        $this->assertEquals([
            'taxonomy' => 'news_category',
            'taxonomyId' => $this->taxonomy()->id,
            'name' => 'used',
            'slug' => 'used',
            'archived' => false,
            'rejected' => 'references',
            'references' => 1,
            'complete' => true,
        ], $this->metadataOf($row));

        $this->assertNotNull($term->fresh());
    }

    /**
     * **削除直前の再検査**で止まった場合も記録する。
     *
     * 単に確認後に assignment を足しても、実行時の事前調査（手順 3）が先に見つけるので
     * 再検査（手順 6）までは届かない。ここで見たいのは
     * 「事前調査を通ったあと、削除の直前に参照が現れた」場合なので、
     * 参照が現れる回を Inspector 側で作る。
     *
     * 呼ばれ方: 1 回目 = 確認の発行、2 回目 = 実行時の事前調査、3 回目 = 削除直前の再検査。
     */
    public function test_削除直前の再検査で拒否された削除を_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $provider = new class implements ReferenceProvider
        {
            public int $calls = 0;

            public function kind(): string
            {
                return 'assignment';
            }

            public function forTerm(TaxonomyTerm $term): ReferenceProbe
            {
                $this->calls++;

                // 3 回目 = 削除直前の再検査。そこではじめて参照が現れる
                return $this->calls >= 3
                    ? ReferenceProbe::found(1, ['news #1'])
                    : ReferenceProbe::none();
            }

            public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
            {
                return ReferenceProbe::none();
            }
        };

        $this->app->instance(ReferenceInspector::class, new ReferenceInspector([$provider]));

        $token = app(DeleteTerm::class)->execute($term->id, $this->admin)->confirmation?->token;
        $result = app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($token));

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(3, $provider->calls, '再検査まで届いていない');
        $this->assertNotNull($term->fresh(), '再検査を経ずに削除した');

        // 1 回の削除試行につき 1 件。confirmation_required の分は数えない
        $row = $this->auditRow('term.delete', 'conflict');
        $metadata = $this->metadataOf($row);

        $this->assertSame(OperationStatus::Conflict->value, $row->result);
        $this->assertSame('references', $metadata['rejected']);
        $this->assertSame(1, $metadata['references']);
        $this->assertTrue($metadata['complete']);
        // 参照先の代表例（コンテンツ側の情報）は監査へ持ち込まない
        $this->assertStringNotContainsString('news #1', (string) $row->metadata);
    }

    /**
     * 調査できなかった（fail closed）削除試行も記録する。
     *
     * 「参照ゼロ」と混同されないよう `complete` を対で残す。
     */
    public function test_調査不能で拒否された削除を_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        // page_versions を落として調査不能にする
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('page_versions');
        Schema::enableForeignKeyConstraints();

        $result = app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertSame(OperationStatus::Conflict, $result->status);

        $metadata = $this->metadataOf($this->auditRow('term.delete', 'conflict'));

        $this->assertSame('undetermined', $metadata['rejected']);
        $this->assertFalse($metadata['complete'], '未確定を確定として記録した');
        $this->assertSame(0, $metadata['references']);
    }

    /**
     * DB の FK が最後に止めた場合も落とさない。
     *
     * Application の判定をすり抜けて RESTRICT に当たった＝**一番残しておきたい試行**。
     * 参照調査を空にした Inspector を差し込んで、その状況を作る。
     */
    public function test_d_b_f_kで止まった削除を_auditへ記録する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        // provider ゼロ = 「参照は無い」と答える Inspector
        $this->app->instance(ReferenceInspector::class, new ReferenceInspector([]));

        $token = app(DeleteTerm::class)->execute($term->id, $this->admin)->confirmation?->token;
        $result = app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($token));

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNotNull($term->fresh(), 'FK が守っていない');

        $metadata = $this->metadataOf($this->auditRow('term.delete', 'conflict'));

        $this->assertSame('db_restrict', $metadata['rejected']);
        // 何が参照しているかは分からない。分からないことを 0 と書かない
        $this->assertNull($metadata['references']);
        $this->assertNull($metadata['complete']);
    }

    /** 拒否 Audit に参照先の中身（題名・slug）を持ち込まない */
    public function test_拒否_auditに参照先の内容を含めない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'used');
        $this->makePageWithFilter(
            '社外秘の企画ページ',
            ['taxonomy' => 'news_category', 'terms' => [$term->id]],
        );

        app(DeleteTerm::class)->execute($term->id, $this->admin);

        $row = $this->auditRow('term.delete', 'conflict');

        $this->assertStringNotContainsString('社外秘の企画ページ', (string) $row->metadata);
        // 参照の内訳そのものを持ち込まない（件数と確定有無だけ）
        $this->assertArrayNotHasKey('references_detail', $this->metadataOf($row));
        $this->assertIsInt($this->metadataOf($row)['references']);
    }

    /** 拒否 Audit も呼び出し元の interface のまま記録される（AD-2） */
    public function test_拒否_auditのinterfaceが呼び出し元のまま(): void
    {
        $taxonomy = $this->taxonomy();

        foreach ([
            'admin' => OperationContext::admin($this->operatorId(), 'admin'),
            'api' => OperationContext::api($this->operatorId(), 'api'),
            'cli' => OperationContext::cli('cli'),
        ] as $expected => $context) {
            $term = $this->makeTerm($taxonomy, 'p'.uniqid());
            TaxonomyTermAssignment::query()->create([
                'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
            ]);

            app(DeleteTerm::class)->execute($term->id, $context);

            $this->assertDatabaseHas('audit_logs', [
                'operation' => 'term.delete',
                'resource_id' => $term->id,
                'interface' => $expected,
                'result' => OperationStatus::Conflict->value,
            ]);
        }
    }

    /**
     * 認可で止めた破壊操作は **forbidden として記録する**（STAGE #18A / SPEC §1C.5）。
     *
     * ここは Phase 9.3B では「記録しない」契約だった。ロール権限の導入にあわせて
     * 「誰が何を拒否されたか」を残す方針へ変えている
     * （権限のない利用者が削除を試みた事実は、事故調査で最も必要になる記録のひとつ）。
     * **成功の記録と混ざらない**よう、result で区別できることも同時に固定する。
     */
    public function test_forbiddenを削除の拒否として_auditする(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        // 未ログインの Admin は write 不可
        $result = app(DeleteTerm::class)->execute($term->id, OperationContext::admin(null, ''));

        $this->assertSame(OperationStatus::Forbidden, $result->status);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'term.delete',
            'result' => 'forbidden',
        ]);
        // 実際に削除された記録は残らない
        $this->assertDatabaseMissing('audit_logs', [
            'operation' => 'term.delete',
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('taxonomy_terms', ['id' => $term->id]);
    }

    /**
     * 存在しない項目への削除試行も記録する。ただし **拒否とは混ぜない**。
     *
     * 誰が何を消そうとしたかは、対象が実在しなくても調査で要る（Correction 2-5）。
     * 一方で `rejected` は「参照があって止めた」という別の意味なので、
     * not_found の行に付けてはいけない。
     */
    public function test_not_foundも記録されるが削除の拒否とは混ざらない(): void
    {
        $missing = TaxonomyId::generate();
        $result = app(DeleteTerm::class)->execute($missing, $this->admin);

        $this->assertSame(OperationStatus::NotFound, $result->status);

        $rows = DB::table('audit_logs')->where('operation', 'term.delete')->get();

        $this->assertCount(1, $rows, '記録が無い、または二重記録されている');
        $this->assertSame(OperationStatus::NotFound->value, $rows[0]->result);
        $this->assertSame($missing, $rows[0]->resource_id);
        $this->assertArrayNotHasKey('rejected', (array) json_decode((string) $rows[0]->metadata, true));
        $this->assertDatabaseMissing('audit_logs', ['operation' => 'term.delete', 'result' => 'success']);
    }

    /** 成功した削除の記録は拒否と混ざらない */
    public function test_成功した削除の_auditにrejectedを含めない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'unused');

        $token = app(DeleteTerm::class)->execute($term->id, $this->admin)->confirmation?->token;
        $result = app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($token));

        $this->assertTrue($result->isSuccess());

        $row = $this->auditRow('term.delete');

        $this->assertSame(OperationStatus::Success->value, $row->result);
        $this->assertArrayNotHasKey('rejected', $this->metadataOf($row));
    }

    // ─────────────────────────────────────────────────────────────
    // 共通
    // ─────────────────────────────────────────────────────────────

    /** 60: interface が違っても business rule は同じ */
    public function test_異なる_operation_contextでも同じbusiness_ruleになる(): void
    {
        $taxonomy = $this->taxonomy();

        foreach ([
            OperationContext::admin($this->operatorId(), 'admin'),
            OperationContext::api($this->operatorId(), 'api'),
            OperationContext::cli('cli'),
        ] as $context) {
            $term = $this->makeTerm($taxonomy, 'p'.uniqid());
            TaxonomyTermAssignment::query()->create([
                'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
            ]);

            $result = app(DeleteTerm::class)->execute($term->id, $context);

            $this->assertSame(
                OperationStatus::Conflict,
                $result->status,
                $context->interface->value.' で結果が違う',
            );
        }
    }

    /** 61: 分類と operation id が contract どおり */
    public function test_operation_idとclassificationがcontractどおり(): void
    {
        $expected = [
            ListTaxonomies::class => ['taxonomy.list', OperationClassification::Read],
            GetTaxonomy::class => ['taxonomy.get', OperationClassification::Read],
            ListTerms::class => ['term.list', OperationClassification::Read],
            CreateTerm::class => ['term.create', OperationClassification::Write],
            UpdateTerm::class => ['term.update', OperationClassification::Write],
            ReorderTerms::class => ['term.reorder', OperationClassification::Write],
            DeleteTerm::class => ['term.delete', OperationClassification::Destructive],
        ];

        foreach ($expected as $class => [$id, $classification]) {
            $service = app($class);
            $this->assertSame($id, $service->operationId());
            $this->assertSame($classification, $service->classification());
        }
    }

    /**
     * validation 失敗は **入力エラーとして** 記録し、成功とは混ざらない。
     *
     * STAGE #18A Correction 7 で契約が変わった箇所。以前は「成功だけ記録」
     * だったが、誰が何を試みて止められたかも履歴に要る。
     * 大事なのは **成功として数えないこと**なので、そこを固定する。
     */
    public function test_validation失敗はvalidation_errorとして_auditされる(): void
    {
        $this->create(['taxonomyKey' => 'news_category', 'name' => '']);

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'term.create', 'result' => 'validation_error',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'operation' => 'term.create', 'result' => 'success',
        ]);
    }

    /**
     * confirmation_required も **確認待ちとして** 1 行残る。
     *
     * 確認トークン付きで送り直した次の試行は別の結末なので、
     * そちらは success として別の 1 行になる（結末ごとに ちょうど 1 行）。
     */
    public function test_confirmation_requiredは確認待ちとして_auditされる(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        app(DeleteTerm::class)->execute($term->id, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'term.delete', 'result' => 'confirmation_required',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'operation' => 'term.delete', 'result' => 'success',
        ]);
        $this->assertDatabaseHas('taxonomy_terms', ['id' => $term->id]);
    }

    /** 62 */
    public function test_runtime_requestでtaxonomy_syncが走らない(): void
    {
        Taxonomy::query()->delete();

        $this->get('/')->assertSuccessful();

        $this->assertSame(0, Taxonomy::query()->count());
    }

    /**
     * 63 / 64 の後継: STAGE 2B の 7 操作が #14 / #15 で公開面へ接続された。
     *
     * **Application Service の公開契約（operation ID / classification）は変えていない。**
     * 変わったのは OperationCatalog への登録だけであることを固定する。
     */
    public function test_7操作がoperation_catalogへ登録されている(): void
    {

        $expected = [
            'taxonomy.list' => OperationClassification::Read,
            'taxonomy.get' => OperationClassification::Read,
            'term.list' => OperationClassification::Read,
            'term.create' => OperationClassification::Write,
            'term.update' => OperationClassification::Write,
            'term.reorder' => OperationClassification::Write,
            'term.delete' => OperationClassification::Destructive,
        ];

        foreach ($expected as $id => $classification) {
            $this->assertTrue(OperationCatalog::allows($id), "{$id} が公開されていない");
            // Application Service 側の宣言と catalog の分類が一致する
            $this->assertSame($classification, OperationCatalog::classificationOf($id), $id);
        }

        // taxonomy 自体の CRUD は公開しない（Phase 9.4）
        foreach (['taxonomy.create', 'taxonomy.update', 'taxonomy.delete'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * その操作・その結末の Audit 行。
     *
     * STAGE #18A Correction 7 で、監査は **結末ごとにちょうど 1 行**になった
     * （確認待ちで返した試行と、確認トークン付きで成功した次の試行は
     * 別の結末なので 2 行になる）。ここでは結末を指定して 1 行であることを
     * 確かめる — 同じ結末を policy と service の両方から書いていないか、
     * という元々の意図はそのまま守られる。
     */
    private function auditRow(string $operation, string $result = 'success'): object
    {
        $rows = DB::table('audit_logs')
            ->where('operation', $operation)
            ->where('result', $result)
            ->get();

        $this->assertCount(1, $rows, "{$operation}（{$result}）の Audit が 1 件ではない");

        return $rows[0];
    }

    /** @return array<string, mixed> */
    private function metadataOf(object $row): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $row->metadata, true);

        return $metadata;
    }

    /** @param array<string, mixed> $input */
    private function create(array $input, ?OperationContext $context = null)
    {
        return app(CreateTerm::class)->execute($input, $context ?? $this->admin);
    }

    private function taxonomy(): Taxonomy
    {
        return Taxonomy::query()->where('key', 'news_category')->firstOrFail();
    }

    private function makeTaxonomy(string $key, bool $hierarchical = true): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => 'demo',
            'hierarchical' => $hierarchical, 'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();

        return $taxonomy;
    }

    private function makeTerm(
        Taxonomy $taxonomy,
        string $slug,
        ?string $parentId = null,
        int $sortOrder = 0,
    ): TaxonomyTerm {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(), 'taxonomy_id' => $taxonomy->id,
            'name' => $slug, 'slug' => $slug, 'parent_id' => $parentId,
            'sort_order' => $sortOrder,
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
                    'id' => TaxonomyId::generate(), 'key' => 'l', 'type' => 'collection',
                    'props' => [],
                    'binding' => ['source' => 'collection', 'type' => 'news', 'filters' => [$filter]],
                    'children' => [],
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $filter */
    private function makePageWithFilter(string $title, array $filter): Page
    {
        $page = Page::query()->create([
            'slug' => 'p-'.uniqid(), 'title' => $title,
            'status' => PageStatus::Draft->value, 'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $version = PageVersion::query()->create([
            'page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'document' => $this->documentWithFilter($filter), 'created_interface' => 'admin',
        ]);

        $page->forceFill(['draft_version_id' => $version->id])->save();

        return $page;
    }
}
