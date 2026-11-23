<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\CoreServiceProvider;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionException;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyNotSyncedException;
use AgentCms\Core\Taxonomy\TaxonomyResolver;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Support\OperationCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\News\Models\News;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 1 の受け入れ。
 *
 * 対象は **永続化・定義 registry・非破壊 sync・read-only Resolver** まで。
 * Term の作成/更新/削除、archive 操作、assignment 書き込み経路は STAGE 2 以降であり、
 * ここでは「まだ公開していない」ことも含めて固定する。
 */
final class TaxonomyFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> 参照順（親 → 子）。落とすときは逆順に使う */
    private const TAXONOMY_TABLES = [
        'taxonomies',
        'taxonomy_terms',
        'taxonomy_term_assignments',
    ];

    private string $definitionDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->definitionDir = base_path('content/taxonomies');
    }

    // ─────────────────────────────────────────────────────────────
    // Migration / persistence
    // ─────────────────────────────────────────────────────────────

    public function test_新規3テーブルが作られる(): void
    {
        $this->assertTrue(Schema::hasTable('taxonomies'));
        $this->assertTrue(Schema::hasTable('taxonomy_terms'));
        $this->assertTrue(Schema::hasTable('taxonomy_term_assignments'));
    }

    public function test_列構成が_spe_cどおり(): void
    {
        // description / status / lock_version は定義管理で足した（Phase 9.7 / STAGE 20D）。
        // 導出値は持たない（項目数も関連付け数も数えて出す）
        $this->assertSame(
            [
                'created_at', 'description', 'hierarchical', 'id', 'key', 'label',
                'lock_version', 'managed_by', 'status', 'updated_at',
            ],
            $this->columns('taxonomies'),
        );

        // 分類と投稿タイプの関連付け（多対多）。**content_type に FK は張れない**
        $this->assertSame(
            [
                'content_type', 'created_at', 'id', 'multiple', 'required',
                'sort_order', 'taxonomy_id', 'updated_at',
            ],
            $this->columns('content_type_taxonomies'),
        );

        $this->assertSame(
            [
                'archived_at', 'created_at', 'description', 'id', 'name',
                'parent_id', 'slug', 'sort_order', 'taxonomy_id', 'updated_at',
            ],
            $this->columns('taxonomy_terms'),
        );

        // 導出値（count / depth）を持たない。taxonomy_id を非正規化しない
        $this->assertSame(
            ['content_id', 'content_type', 'created_at', 'id', 'sort_order', 'term_id'],
            $this->columns('taxonomy_term_assignments'),
        );
    }

    public function test_archived_atはnullableで既定はnull(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy);

        $this->assertNull($term->archived_at);
        $this->assertFalse($term->isArchived());
    }

    public function test_uniqueとindexが_spe_cどおり(): void
    {
        $this->assertTrue($this->hasIndex('taxonomies', 'uq_taxonomy_key'));
        $this->assertTrue($this->hasIndex('taxonomy_terms', 'uq_term_taxonomy_slug'));
        $this->assertTrue($this->hasIndex('taxonomy_terms', 'idx_term_tree'));
        $this->assertTrue($this->hasIndex('taxonomy_terms', 'idx_term_archived'));
        $this->assertTrue($this->hasIndex('taxonomy_term_assignments', 'uq_assignment_entry'));
        $this->assertTrue($this->hasIndex('taxonomy_term_assignments', 'idx_assignment_entry'));
        $this->assertTrue($this->hasIndex('taxonomy_term_assignments', 'idx_assignment_term'));
    }

    public function test_slugは同一taxonomy内でunique(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $this->makeTerm($taxonomy, slug: 'press');

        $this->expectException(QueryException::class);
        $this->makeTerm($taxonomy, slug: 'press');
    }

    public function test_f_kがすべて_restric_tで_cascad_eが無い(): void
    {
        // driver 非依存に外部キーの定義を読む（MySQL 実機でも同じ検証を回せるように）
        $foreignKeys = [];

        foreach (self::TAXONOMY_TABLES as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $foreignKeys[] = $fk;
            }
        }

        $this->assertCount(3, $foreignKeys, '外部キーの本数が想定と違う');

        foreach ($foreignKeys as $fk) {
            $this->assertSame('restrict', strtolower((string) ($fk['on_delete'] ?? '')));
            $this->assertSame('restrict', strtolower((string) ($fk['on_update'] ?? '')));
        }
    }

    public function test_使用中termは_d_bレベルで削除できない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy);
        $news = News::query()->create([
            'title' => 'お知らせ', 'slug' => 'oshirase', 'body' => '本文',
            'status' => 'published',
        ]);

        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id,
            'content_type' => 'news',
            'content_id' => (int) $news->id,
        ]);

        // Application Service を通さない直削除でも DB が守る（§G.4）
        $this->expectException(QueryException::class);
        DB::table('taxonomy_terms')->where('id', $term->id)->delete();
    }

    public function test_子を持つtermは_d_bレベルで削除できない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $this->makeTerm($taxonomy, slug: 'child', parentId: $parent->id);

        $this->expectException(QueryException::class);
        DB::table('taxonomy_terms')->where('id', $parent->id)->delete();
    }

    public function test_termを持つtaxonomyは_d_bレベルで削除できない(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $this->makeTerm($taxonomy);

        $this->expectException(QueryException::class);
        DB::table('taxonomies')->where('id', $taxonomy->id)->delete();
    }

    public function test_二重付与はuniqueで防がれる(): void
    {
        $taxonomy = $this->makeTaxonomy();
        $term = $this->makeTerm($taxonomy);

        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $this->expectException(QueryException::class);
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);
    }

    public function test_migrationだけでは行が作られない(): void
    {
        // RefreshDatabase 直後 = migrate 済み・sync 未実行
        $this->assertSame(0, Taxonomy::query()->count());
        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    public function test_既存テーブルに_alte_rが無くworksとnewsの形が変わらない(): void
    {
        // works.category は select のまま（G3-a / D-2a）
        $this->assertTrue(Schema::hasColumn('works', 'category'));
        $this->assertFalse(Schema::hasColumn('works', 'taxonomy_id'));
        $this->assertFalse(Schema::hasColumn('works', 'news_category'));

        // news はカラム追加なし（NC-2）
        $this->assertFalse(Schema::hasColumn('news', 'category'));
        $this->assertFalse(Schema::hasColumn('news', 'taxonomy_id'));

        // Stage 1 の migration ファイルが既存テーブルへ触っていないこと
        $migration = $this->stageOneMigrationSource();

        // 既存テーブルの ALTER を一切行わない
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString('Schema::rename(', $migration);
        $this->assertStringNotContainsString('->change()', $migration);

        // 作るのは新規 3 テーブルだけ
        preg_match_all("/Schema::create\('([a-z_]+)'/", $migration, $matches);
        $this->assertSame(
            ['taxonomies', 'taxonomy_terms', 'taxonomy_term_assignments'],
            $matches[1],
        );

        // migration から sync も term 作成も行わない（SY-1 / PV-1）
        $this->assertStringNotContainsString('TaxonomySynchronizer', $migration);
        $this->assertStringNotContainsString('Artisan::call', $migration);
        $this->assertStringNotContainsString('DB::table(', $migration);
        $this->assertStringNotContainsString('->insert(', $migration);
    }

    public function test_既存データが変化しない(): void
    {
        $work = Work::query()->create([
            'title' => '実績', 'slug' => 'jisseki', 'category' => 'web',
            'body' => '本文', 'status' => 'published',
        ]);

        $taxonomy = $this->makeTaxonomy();
        $this->makeTerm($taxonomy);
        app(TaxonomySynchronizer::class)->sync();

        $this->assertSame('web', $work->fresh()?->category);
    }

    // ─────────────────────────────────────────────────────────────
    // Definition Registry
    // ─────────────────────────────────────────────────────────────

    public function test_news_category_definitionを読める(): void
    {
        $registry = app(TaxonomyDefinitionRegistry::class);

        $this->assertTrue($registry->has('news_category'));

        $definition = $registry->get('news_category');
        $this->assertSame('news_category', $definition->key);
        $this->assertSame('カテゴリ', $definition->label);
        $this->assertTrue($definition->hierarchical);
        $this->assertSame(TaxonomyManagedBy::Code, $definition->managedBy);
    }

    public function test_definitionは_uli_dもterm一覧も持たない(): void
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode(
            (string) file_get_contents($this->definitionDir.'/news_category.json'),
            true,
        );

        $this->assertArrayNotHasKey('id', $raw);
        $this->assertArrayNotHasKey('terms', $raw);
        $this->assertSame(['key', 'label', 'hierarchical', 'managed_by'], array_keys($raw));
    }

    public function test_未知属性を拒否する(): void
    {
        $this->assertDefinitionRejected(
            ['key' => 'demo_tax', 'label' => 'demo', 'hierarchical' => false,
                'managed_by' => 'code', 'color' => 'red'],
            '未知の属性',
        );
    }

    public function test_不正keyを拒否する(): void
    {
        $this->assertDefinitionRejected(
            ['key' => 'Bad-Key', 'label' => 'demo', 'hierarchical' => false, 'managed_by' => 'code'],
            '形式が不正',
            file: 'Bad-Key.json',
        );
    }

    public function test_不正_jso_nを拒否する(): void
    {
        $path = $this->definitionDir.'/broken.json';
        File::put($path, '{ "key": "broken", ');

        try {
            $this->expectException(TaxonomyDefinitionException::class);
            $this->expectExceptionMessageMatches('/JSON として読み取れません/');
            $this->freshRegistry()->all();
        } finally {
            File::delete($path);
        }
    }

    public function test_managed_byにcms以外を書けない(): void
    {
        $this->assertDefinitionRejected(
            ['key' => 'demo_tax', 'label' => 'demo', 'hierarchical' => false, 'managed_by' => 'cms'],
            'managed_by は "code" のみ',
        );
    }

    public function test_ファイル名とkeyの不一致を拒否する(): void
    {
        // 同じ key で別ファイル名を置くと重複検出の前にファイル名不一致で落ちる
        $this->assertDefinitionRejected(
            ['key' => 'demo_tax', 'label' => 'demo', 'hierarchical' => false, 'managed_by' => 'code'],
            'ファイル名を',
            file: 'other_name.json',
        );
    }

    public function test_重複keyを拒否する(): void
    {
        // ファイル名 = key の規則があるため、重複 key は「ファイル名不一致」として先に検出される。
        // registry を直接叩いて重複検出そのものも固定する
        $a = $this->definitionDir.'/dup_one.json';
        $b = $this->definitionDir.'/dup_two.json';
        File::put($a, json_encode(['key' => 'dup_one', 'label' => 'A', 'hierarchical' => false, 'managed_by' => 'code']));
        File::put($b, json_encode(['key' => 'dup_one', 'label' => 'B', 'hierarchical' => false, 'managed_by' => 'code']));

        try {
            $this->expectException(TaxonomyDefinitionException::class);
            $this->freshRegistry()->all();
        } finally {
            File::delete([$a, $b]);
        }
    }

    public function test_定義が1件でも不正なら全体が失敗する(): void
    {
        $path = $this->definitionDir.'/broken2.json';
        File::put($path, '{}');

        try {
            $failed = false;

            try {
                $this->freshRegistry()->all();
            } catch (TaxonomyDefinitionException) {
                $failed = true;
            }

            $this->assertTrue($failed, '不正な定義があるのに読み込みが成功した');
        } finally {
            File::delete($path);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // taxonomy-sync
    // ─────────────────────────────────────────────────────────────

    public function test_初回はnews_categoryをcreatedにする(): void
    {
        $this->artisan('agentcms:taxonomy-sync')
            ->expectsOutputToContain('新規 1')
            ->assertExitCode(0);

        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $this->assertSame('カテゴリ', $taxonomy->label);
        $this->assertTrue($taxonomy->hierarchical);
        $this->assertSame(TaxonomyManagedBy::Code, $taxonomy->managed_by);
        $this->assertTrue(TaxonomyId::isValid($taxonomy->id));
    }

    public function test_2回目はunchangedで_uli_dが変わらない(): void
    {
        $first = app(TaxonomySynchronizer::class)->sync();
        $id = Taxonomy::query()->where('key', 'news_category')->value('id');

        $second = app(TaxonomySynchronizer::class)->sync();

        $this->assertSame(['news_category'], $first->created);
        $this->assertSame([], $second->created);
        $this->assertSame([], $second->updated);
        $this->assertSame(['news_category'], $second->unchanged);
        $this->assertSame($id, Taxonomy::query()->where('key', 'news_category')->value('id'));
        $this->assertSame(1, Taxonomy::query()->count());
    }

    public function test_label変更はupdatedになる(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $id = Taxonomy::query()->where('key', 'news_category')->value('id');

        $this->withDefinition('news_category', [
            'key' => 'news_category', 'label' => '記事カテゴリ',
            'hierarchical' => true, 'managed_by' => 'code',
        ], function (): void {
            $report = $this->freshSynchronizer()->sync();

            $this->assertSame(['news_category'], $report->updated);
        });

        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $this->assertSame('記事カテゴリ', $taxonomy->label);
        // identity は変わらない
        $this->assertSame($id, $taxonomy->id);
    }

    public function test_definition削除で_d_b_taxonomyを削除しない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $this->assertSame(1, Taxonomy::query()->count());

        $path = $this->definitionDir.'/news_category.json';
        $backup = (string) File::get($path);
        File::delete($path);

        try {
            $report = $this->freshSynchronizer()->sync();

            $this->assertSame([], $report->created);
            $this->assertSame([], $report->conflicts);
            // SY-7: 定義が消えても DB は消さない
            $this->assertSame(1, Taxonomy::query()->count());
            $this->assertNotNull(Taxonomy::query()->where('key', 'news_category')->first());
        } finally {
            File::put($path, $backup);
        }
    }

    public function test_key変更は新規keyとして扱い旧taxonomyを残す(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $oldId = Taxonomy::query()->where('key', 'news_category')->value('id');

        $this->withDefinitionFile('renamed_category.json', [
            'key' => 'renamed_category', 'label' => 'カテゴリ',
            'hierarchical' => true, 'managed_by' => 'code',
        ], function () use ($oldId): void {
            $report = $this->freshSynchronizer()->sync();

            $this->assertContains('renamed_category', $report->created);
            // SY-6: rename と推測しない。旧 key はそのまま残る
            $this->assertNotNull(Taxonomy::query()->where('key', 'news_category')->first());
            $this->assertSame($oldId, Taxonomy::query()->where('key', 'news_category')->value('id'));
            $this->assertSame(2, Taxonomy::query()->count());
        });
    }

    public function test_syncはtermとassignmentを変更しない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();

        $term = $this->makeTerm($taxonomy, slug: 'press');
        $assignment = TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $this->withDefinition('news_category', [
            'key' => 'news_category', 'label' => '別ラベル',
            'hierarchical' => true, 'managed_by' => 'code',
        ], function (): void {
            $this->freshSynchronizer()->sync();
        });

        // SY-8 / SY-9
        $this->assertSame(1, TaxonomyTerm::query()->count());
        $this->assertSame('press', $term->fresh()?->slug);
        $this->assertSame(1, TaxonomyTermAssignment::query()->count());
        $this->assertNotNull($assignment->fresh());
    }

    public function test_managed_by_cmsの同一keyは_conflic_tで無変更(): void
    {
        // 管理画面管理の taxonomy が同じ key を持っている状態を作る
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(),
            'key' => 'news_category',
            'label' => '手動で作ったカテゴリ',
            'hierarchical' => false,
            'managed_by' => TaxonomyManagedBy::Cms->value,
        ])->save();

        $report = app(TaxonomySynchronizer::class)->sync();

        $this->assertTrue($report->hasConflicts());
        $this->assertFalse($report->applied);
        $this->assertSame('news_category', $report->conflicts[0]['key']);

        // SY-10 / SY-11: 一切書き換えない
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $this->assertSame('手動で作ったカテゴリ', $taxonomy->label);
        $this->assertFalse($taxonomy->hierarchical);
        $this->assertSame(TaxonomyManagedBy::Cms, $taxonomy->managed_by);
    }

    public function test_hierarchical_true_from_falseでparented_termありは_conflict(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();

        $parent = $this->makeTerm($taxonomy, slug: 'parent');
        $this->makeTerm($taxonomy, slug: 'child', parentId: $parent->id);

        $this->withDefinition('news_category', [
            'key' => 'news_category', 'label' => 'カテゴリ',
            'hierarchical' => false, 'managed_by' => 'code',
        ], function () use ($taxonomy): void {
            $report = $this->freshSynchronizer()->sync();

            $this->assertTrue($report->hasConflicts());
            $this->assertFalse($report->applied);
            $this->assertStringContainsString('親を持つ項目が 1 件', $report->conflicts[0]['reason']);

            // SY-12: 変更しない
            $this->assertTrue($taxonomy->fresh()?->hierarchical);
        });
    }

    public function test_conflict時に他definitionの部分更新も残らない(): void
    {
        // 既存: cms 管理の news_category（CONFLICT 要因）
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(), 'key' => 'news_category',
            'label' => '手動', 'hierarchical' => false,
            'managed_by' => TaxonomyManagedBy::Cms->value,
        ])->save();

        // 同時に、問題の無い別定義を足す
        $this->withDefinitionFile('extra_tax.json', [
            'key' => 'extra_tax', 'label' => '追加', 'hierarchical' => false, 'managed_by' => 'code',
        ], function (): void {
            $report = $this->freshSynchronizer()->sync();

            $this->assertTrue($report->hasConflicts());
            $this->assertSame([], $report->created);

            // SY-13: conflict があるので **正常な方も作られない**
            $this->assertNull(Taxonomy::query()->where('key', 'extra_tax')->first());
            $this->assertSame(1, Taxonomy::query()->count());
        });
    }

    public function test_reportに4分類が出る(): void
    {
        $this->artisan('agentcms:taxonomy-sync --json')
            ->assertExitCode(0);

        $report = app(TaxonomySynchronizer::class)->sync()->toArray();

        $this->assertSame(
            ['applied', 'created', 'updated', 'unchanged', 'conflicts'],
            array_keys($report),
        );
    }

    public function test_定義不正ならexit_codeがnon_zero(): void
    {
        $path = $this->definitionDir.'/broken3.json';
        File::put($path, 'not json at all');

        try {
            $this->artisan('agentcms:taxonomy-sync')->assertExitCode(1);
            // 何も書かれていない
            $this->assertSame(0, Taxonomy::query()->count());
        } finally {
            File::delete($path);
        }
    }

    public function test_conflict時のexit_codeがnon_zero(): void
    {
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(), 'key' => 'news_category',
            'label' => '手動', 'hierarchical' => false,
            'managed_by' => TaxonomyManagedBy::Cms->value,
        ])->save();

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(1);
    }

    public function test_レポートに_d_b接続情報を出さない(): void
    {
        $this->artisan('agentcms:taxonomy-sync --json')
            ->doesntExpectOutputToContain('password')
            ->doesntExpectOutputToContain('DB_')
            ->assertExitCode(0);
    }

    // ─────────────────────────────────────────────────────────────
    // Runtime safety
    // ─────────────────────────────────────────────────────────────

    public function test_通常の_htt_p_requestでsyncが走らない(): void
    {
        $this->assertSame(0, Taxonomy::query()->count());

        $this->get('/')->assertSuccessful();

        // PV-6 / SY-3: request では 1 行も作られない
        $this->assertSame(0, Taxonomy::query()->count());
    }

    public function test_service_provider_bootで_d_b_writeが起きない(): void
    {
        /** @var list<string> $queries */
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // register / boot をもう一度通す。DB へ 1 度も触らないこと（PV-6 / SY-3）
        $provider = new CoreServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame([], $queries, 'CoreServiceProvider の boot で SQL が実行された');
        $this->assertSame(0, Taxonomy::query()->count());
    }

    public function test_core_service_providerがsyncを呼ばない(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/core/src/CoreServiceProvider.php');

        $this->assertStringNotContainsString('TaxonomySynchronizer', $source);
        $this->assertStringNotContainsString('Artisan::call', $source);
        // コマンドは「登録」するだけ（CLI 実行時のみ）
        $this->assertStringContainsString('runningInConsole()', $source);
    }

    public function test_resolverはmissing時に_d_b行を作らない(): void
    {
        $resolver = app(TaxonomyResolver::class);

        $this->assertFalse($resolver->isSynced('news_category'));
        $this->assertNull($resolver->find('news_category'));

        try {
            $resolver->resolve('news_category');
            $this->fail('未同期なのに例外が投げられなかった');
        } catch (TaxonomyNotSyncedException $e) {
            $this->assertStringContainsString('agentcms:taxonomy-sync', $e->getMessage());
        }

        // 明示的失敗のあとも行は作られない（PV-4 / SY-17）
        $this->assertSame(0, Taxonomy::query()->count());
    }

    public function test_resolverはsync後に解決できる(): void
    {
        app(TaxonomySynchronizer::class)->sync();

        $resolver = app(TaxonomyResolver::class);
        $taxonomy = $resolver->resolve('news_category');

        $this->assertSame('news_category', $taxonomy->key);
        $this->assertTrue(TaxonomyId::isValid($resolver->idFor('news_category')));
    }

    public function test_resolverはmanaged_by不一致を黙って直さない(): void
    {
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(), 'key' => 'news_category',
            'label' => '手動', 'hierarchical' => true,
            'managed_by' => TaxonomyManagedBy::Cms->value,
        ])->save();

        $resolver = app(TaxonomyResolver::class);

        try {
            $resolver->resolve('news_category');
            $this->fail('managed_by 不一致なのに例外が投げられなかった');
        } catch (TaxonomyNotSyncedException) {
            // 期待どおり
        }

        // 黙って code へ書き換えない
        $this->assertSame(
            TaxonomyManagedBy::Cms,
            Taxonomy::query()->where('key', 'news_category')->firstOrFail()->managed_by,
        );
    }

    public function test_resolverはfirst_or_create系の_ap_iを持たない(): void
    {
        $methods = get_class_methods(TaxonomyResolver::class);

        foreach (['firstOrCreate', 'updateOrCreate', 'ensure', 'create', 'sync'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // taxonomy-check（DB 障害判定の分離）
    //
    // 「DB へ接続できない」と「接続できるがテーブルが無い / schema が壊れている」を
    // **テスト上でも明確に分ける**。前者だけが警告 + SUCCESS を許される。
    // ─────────────────────────────────────────────────────────────

    /** E. 同期済み = SUCCESS。かつ DB を変更しない */
    public function test_check_同期済みは_succes_sで_d_bを変更しない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $before = Taxonomy::query()->get()->toArray();

        $this->artisan('agentcms:taxonomy-check')->assertExitCode(0);

        $this->assertEquals($before, Taxonomy::query()->get()->toArray());
    }

    /** C. テーブルはあるが definition 未同期 = 非ゼロ */
    public function test_check_未同期は非ゼロで直さない(): void
    {
        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('未同期')
            ->assertExitCode(1);

        // 検出しても直さない
        $this->assertSame(0, Taxonomy::query()->count());
    }

    /** D. CONFLICT = 非ゼロ・DB 変更なし */
    public function test_check_conflictは非ゼロで_d_b変更なし(): void
    {
        (new Taxonomy)->forceFill([
            'id' => TaxonomyId::generate(), 'key' => 'news_category',
            'label' => '手動', 'hierarchical' => true,
            'managed_by' => TaxonomyManagedBy::Cms->value,
        ])->save();

        $before = Taxonomy::query()->get()->toArray();

        $this->artisan('agentcms:taxonomy-check')->assertExitCode(1);

        $this->assertEquals($before, Taxonomy::query()->get()->toArray());
        $this->assertSame(TaxonomyManagedBy::Cms, Taxonomy::query()->firstOrFail()->managed_by);
    }

    /**
     * A. DB へは接続できるが taxonomy テーブルが 1 つも無い。
     * **「接続できない」と表示せず**、migration 未実行と分かる案内を出して非ゼロ終了する。
     */
    public function test_check_接続可能でテーブルが無い場合は非ゼロ(): void
    {
        $this->dropTaxonomyTables(self::TAXONOMY_TABLES);

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('分類用のテーブルがありません')
            ->expectsOutputToContain('migration')
            ->doesntExpectOutputToContain('データベースへ接続できないため')
            ->assertExitCode(1);
    }

    /** B. 3 テーブルのうち一部だけ不足 = 非ゼロ・不足テーブル名を出す */
    public function test_check_一部テーブル不足を検出する(): void
    {
        $this->dropTaxonomyTables(['taxonomy_term_assignments']);

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('taxonomy_term_assignments')
            ->doesntExpectOutputToContain('データベースへ接続できないため')
            ->assertExitCode(1);

        // 残りのテーブルは触っていない
        $this->assertTrue(Schema::hasTable('taxonomies'));
        $this->assertTrue(Schema::hasTable('taxonomy_terms'));
    }

    /**
     * G. 接続は成立するが、そのあとで schema が想定と異なる。
     * **接続不可として SUCCESS にしてはならない。**
     *
     * SQLite は二重引用符付きの未知識別子を文字列リテラルとして解釈するため、
     * 列不足は QueryException にならず「未同期」と誤判定され得る。
     * だからこの検査は read-only な列存在確認で捕まえる。
     *
     * **表を作り直して不足を作らない。**
     * 以前はこの 3 表を落として「列の足りない別物」を建て直していた。
     * `content_type_taxonomies` が `taxonomies.id` を参照しているため、
     * 外部キーを外して親を落としても**参照そのものは残る**。建て直した別物は
     * ascii_bin ではなく utf8mb4 なので、MySQL は 1215 でこれを拒む。
     * **MySQL は正しい** — 参照先として成立しない表だからである
     * （SQLite は落とした時点で参照を忘れるので、この誤りが見えなかった）。
     *
     * 見たいのは「**表はあるが、読む列が無い**」状態なので、**列だけを外す**。
     * `key` は unique index に入っているため、index を先に落とす（SQLite の制約）。
     */
    public function test_check_接続後のschema不整合は非ゼロ(): void
    {
        // 表はそのまま。検査が読む列だけを外す
        Schema::table('taxonomies', function ($table): void {
            $table->dropUnique('uq_taxonomy_key');
        });
        Schema::table('taxonomies', function ($table): void {
            $table->dropColumn('key');
        });

        // 3 表とも在ることが前提（「テーブルがありません」の枝ではない）
        foreach (self::TAXONOMY_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} は在るはず");
        }

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('schema 不整合')
            ->expectsOutputToContain('taxonomies.key')
            ->doesntExpectOutputToContain('データベースへ接続できないため')
            // 「未同期です」と誤って報告しない
            ->doesntExpectOutputToContain('データベースへ未同期です')
            ->assertExitCode(1);
    }

    /**
     * STAGE 2A CORRECTION G: domain policy / Reference Inspector が読む列まで検査する。
     * 個別の列が欠けても schema 不整合として検出できること。
     */
    public function test_check_domain_policyが読む列の不足も検出する(): void
    {
        app(TaxonomySynchronizer::class)->sync();

        // STAGE 2A で追加した必須列を 1 つずつ落として検出されることを確かめる
        foreach ([
            'taxonomy_terms' => ['name', 'slug', 'sort_order', 'archived_at'],
            'taxonomy_term_assignments' => ['sort_order', 'created_at'],
            'taxonomies' => ['created_at', 'updated_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} が存在しない（migration と必須列定義が不一致）",
                );
            }
        }

        // 実際に 1 列落として検出されるか
        // （index に含まれない列を選ぶ。SQLite は index 中の列を drop できない）
        Schema::table('taxonomy_terms', function ($table): void {
            $table->dropColumn('name');
        });

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('schema 不整合')
            ->expectsOutputToContain('taxonomy_terms.name')
            ->assertExitCode(1);
    }

    /**
     * F. DB が本当に到達不能。
     * **この分岐だけ**が既存の host verify 契約どおり警告 + SUCCESS になる。
     */
    public function test_check_db到達不能のときだけ警告で_succes_s(): void
    {
        $original = config('database.default');

        config([
            'database.connections.agentcms_unreachable' => [
                'driver' => 'sqlite',
                // 存在しないディレクトリ = PDO の接続そのものが失敗する
                'database' => '/agentcms-nonexistent-dir-'.uniqid().'/db.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'agentcms_unreachable',
        ]);

        try {
            $this->artisan('agentcms:taxonomy-check')
                ->expectsOutputToContain('データベースへ接続できないため')
                ->assertExitCode(0);
        } finally {
            config(['database.default' => $original]);
            DB::purge('agentcms_unreachable');
        }
    }

    /** H. read-only 保証: 前後で DB 内容が同一・書き込み SQL を 1 本も出さない */
    public function test_check_はread_onlyであること(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $taxonomy = Taxonomy::query()->firstOrFail();
        $this->makeTerm($taxonomy, slug: 'press');

        $before = [
            'taxonomies' => Taxonomy::query()->orderBy('id')->get()->toArray(),
            'terms' => TaxonomyTerm::query()->orderBy('id')->get()->toArray(),
            'assignments' => TaxonomyTermAssignment::query()->orderBy('id')->get()->toArray(),
        ];

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower(trim($query->sql));
        });

        $this->artisan('agentcms:taxonomy-check')->assertExitCode(0);

        foreach ($statements as $sql) {
            foreach (['insert ', 'update ', 'delete ', 'create table', 'alter table', 'drop table'] as $write) {
                $this->assertStringNotContainsString(
                    $write,
                    $sql,
                    "taxonomy-check が書き込み SQL を実行した: {$sql}",
                );
            }
        }

        $this->assertEquals($before, [
            'taxonomies' => Taxonomy::query()->orderBy('id')->get()->toArray(),
            'terms' => TaxonomyTerm::query()->orderBy('id')->get()->toArray(),
            'assignments' => TaxonomyTermAssignment::query()->orderBy('id')->get()->toArray(),
        ]);
    }

    /** 定義ファイル自体が不正なら DB を見るまでもなく非ゼロ */
    public function test_check_定義不正は非ゼロ(): void
    {
        $path = $this->definitionDir.'/broken_check.json';
        File::put($path, 'not json');

        try {
            $this->artisan('agentcms:taxonomy-check')
                ->expectsOutputToContain('定義ファイルに問題があります')
                ->assertExitCode(1);
        } finally {
            File::delete($path);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // STAGE 2 以降の surface を先行公開していないこと
    // ─────────────────────────────────────────────────────────────

    /**
     * STAGE 1 の「まだ公開していない」ガードの後継。
     *
     * #14 / #15 で公開したのは **taxonomy の read と term の操作 7 件だけ**。
     * 検証を消すのではなく、**公開した範囲を厳密に固定する**契約へ置き換えてある
     * （3 者一致の正本は `TaxonomySurfaceParityTest`）。
     */
    public function test_公開したのは分類7操作だけ(): void
    {

        $taxonomy = array_values(array_filter(
            OperationCatalog::ids(),
            static fn (string $id): bool => str_starts_with($id, 'taxonomy.')
                || str_starts_with($id, 'term.'),
        ));
        sort($taxonomy);

        $this->assertSame([
            'taxonomy.get', 'taxonomy.list',
            'term.create', 'term.delete', 'term.list', 'term.reorder', 'term.update',
        ], $taxonomy);

        // taxonomy 自体の CRUD は公開しない（Phase 9.4 へ延期 — §P.2）
        foreach (['taxonomy.create', 'taxonomy.update', 'taxonomy.delete'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    /**
     * STAGE 3C の確定契約（旧「まだ接続していない」ガードの後継）。
     *
     * 検証を消して弱めるのではなく、**接続した範囲を厳密に固定する**契約へ置き換えた。
     */
    public function test_news_content_schemaはnews_categoryだけを宣言する(): void
    {
        $raw = (string) file_get_contents(base_path('content/schemas/news.json'));
        /** @var array<string, mixed> $news */
        $news = json_decode($raw, true);

        $this->assertSame(
            [['key' => 'news_category', 'multiple' => true, 'required' => false]],
            $news['taxonomies'],
        );

        // checked-in JSON へ ULID を書かない（§J.2）
        $this->assertDoesNotMatchRegularExpression('/[0-9A-HJKMNP-TV-Z]{26}/', $raw);

        // fields 側に分類フィールドを足さない
        $keys = array_map(static fn (array $f): string => (string) $f['key'], $news['fields']);
        $this->assertNotContains('news_category', $keys);
        $this->assertNotContains('category', $keys, 'News へ分類カラムを足している');

        // works は select のまま（このカラムは Phase 9.3C の範囲）
        /** @var array<string, mixed> $works */
        $works = json_decode((string) file_get_contents(base_path('content/schemas/works.json')), true);
        $this->assertArrayNotHasKey('taxonomies', $works);
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

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

    /** @return list<string> */
    private function columns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return $columns;
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $definition) {
            if (($definition['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /** テスト DB（SQLite）の CREATE 文をまとめて取り出す */
    private function createStatements(): string
    {
        $rows = DB::select(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name IN (?, ?, ?)",
            ['taxonomies', 'taxonomy_terms', 'taxonomy_term_assignments'],
        );

        return strtolower(implode("\n", array_map(
            static fn (object $row): string => (string) ($row->sql ?? ''),
            $rows,
        )));
    }

    /**
     * taxonomy テーブルだけを落とす（テスト内の DB のみ。RefreshDatabase が毎回作り直す）。
     * DB 接続は成立したまま「テーブルが無い」状態を作るために使う。
     *
     * @param  list<string>  $tables
     */
    private function dropTaxonomyTables(array $tables): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            // 参照の逆順に落とす
            foreach (array_reverse(self::TAXONOMY_TABLES) as $table) {
                if (in_array($table, $tables, true)) {
                    Schema::dropIfExists($table);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function stageOneMigrationSource(): string
    {
        $file = '/database/migrations/2026_08_14_000001_create_taxonomy_tables.php';

        foreach ([base_path('vendor/agentcms/core'), dirname(__DIR__, 2).'/core'] as $base) {
            if (is_file($base.$file)) {
                return (string) file_get_contents($base.$file);
            }
        }

        $this->fail('Stage 1 の migration ファイルが見つかりません');
    }

    private function freshRegistry(): TaxonomyDefinitionRegistry
    {
        return new TaxonomyDefinitionRegistry($this->definitionDir);
    }

    private function freshSynchronizer(): TaxonomySynchronizer
    {
        return new TaxonomySynchronizer($this->freshRegistry(), app('db'));
    }

    /**
     * 定義ファイルを一時的に差し替える（テスト内だけ。終了時に必ず戻す）。
     *
     * @param  array<string, mixed>  $definition
     */
    private function withDefinition(string $key, array $definition, callable $body): void
    {
        $path = $this->definitionDir.'/'.$key.'.json';
        $backup = (string) File::get($path);
        File::put($path, (string) json_encode($definition, JSON_UNESCAPED_UNICODE));

        try {
            $body();
        } finally {
            File::put($path, $backup);
        }
    }

    /**
     * 定義ファイルを一時的に追加する。
     *
     * @param  array<string, mixed>  $definition
     */
    private function withDefinitionFile(string $file, array $definition, callable $body): void
    {
        $path = $this->definitionDir.'/'.$file;
        File::put($path, (string) json_encode($definition, JSON_UNESCAPED_UNICODE));

        try {
            $body();
        } finally {
            File::delete($path);
        }
    }

    /**
     * 不正な定義が registry で拒否されることを確認する。
     *
     * @param  array<string, mixed>  $definition
     */
    private function assertDefinitionRejected(
        array $definition,
        string $expectedMessage,
        ?string $file = null,
    ): void {
        $name = $file ?? ($definition['key'].'.json');
        $path = $this->definitionDir.'/'.$name;
        File::put($path, (string) json_encode($definition, JSON_UNESCAPED_UNICODE));

        try {
            $caught = null;

            try {
                $this->freshRegistry()->all();
            } catch (TaxonomyDefinitionException $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, '不正な定義が受理された');
            $this->assertStringContainsString($expectedMessage, $caught->getMessage());
        } finally {
            File::delete($path);
        }
    }
}
