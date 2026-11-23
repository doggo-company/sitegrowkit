<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\Reference\ContentSchemaReferenceProvider;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Support\OperationCatalog;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\News\Models\News;
use Modules\Works\Models\Work;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3C — **最初の taxonomy consumer**（SPEC §S.2 / NC-1〜NC-6 / §AF #13）。
 *
 * `news` に `news_category` を接続したときの実データ挙動を固定する。
 *
 * **このファイルは First Consumer の責務だけを扱う。**
 * payload contract / PATCH semantics / 排他制御は `ContentTaxonomyTest` と
 * `ContentTaxonomyConcurrencyTest` の責務であり、ここでは重複させない。
 *
 * `works.category` の移行（Phase 9.3C）はこの Stage の範囲外であり、
 * **非回帰であることだけ**を確かめる。
 */
final class NewsCategoryConsumerTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private OperationContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = OperationContext::admin($this->operatorId(), 'admin');
        // 明示ステップとしての同期（runtime の暗黙 write ではない — PV-1〜PV-6）
        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // A. Schema / Registry
    // ─────────────────────────────────────────────────────────────

    /** A-1 / A-3 */
    public function test_a1_schema_registryがnews_news_categoryを解決できる(): void
    {
        $schema = app(SchemaRegistry::class)->get('news');

        $this->assertTrue($schema->hasTaxonomy('news_category'));
        $this->assertSame(['news_category'], $schema->taxonomyKeys());

        $declaration = $schema->taxonomy('news_category');
        $this->assertTrue($declaration->multiple, 'multiple=true でない');
        $this->assertFalse($declaration->required, 'required=false でない');

        // 分類は field ではない（fields へ足していない）
        $this->assertNotContains('news_category', $schema->fieldKeys());
    }

    /** A-2 */
    public function test_a2_definitionはcode管理で階層ありのまま(): void
    {
        $definition = app(TaxonomyDefinitionRegistry::class)->get('news_category');

        $this->assertSame(TaxonomyManagedBy::Code, $definition->managedBy);
        $this->assertTrue($definition->hierarchical);
        $this->assertSame('カテゴリ', $definition->label);

        // DB 側も定義どおり
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $this->assertSame(TaxonomyManagedBy::Code, $taxonomy->managed_by);
        $this->assertTrue((bool) $taxonomy->hierarchical);
    }

    /** A-4 */
    public function test_a4_taxonomy_checkがpassする(): void
    {
        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('コンテンツタイプからの参照 1 件')
            ->expectsOutputToContain('参照もすべて解決できました')
            ->assertExitCode(0);
    }

    /** A-5: verify が使う schema 参照整合（未登録 key / 未同期 / 競合の検出経路） */
    public function test_a5_schema参照整合が解決できる(): void
    {
        // ContentSchema → definition が解決できる
        $this->assertTrue(app(TaxonomyDefinitionRegistry::class)->has('news_category'));

        // T-2 provider が「news が使っている」と答える = taxonomy 削除は RESTRICT
        $provider = new ContentSchemaReferenceProvider(app(SchemaRegistry::class));
        $probe = $provider->forTaxonomy(Taxonomy::query()->where('key', 'news_category')->firstOrFail());

        $this->assertSame('content_schema', $provider->kind());
        $this->assertSame(1, $probe->count);
        $this->assertFalse($probe->isUndetermined());
        $this->assertTrue($probe->hasFindings());

        // registry（agent 向け index）の consumers も更新されている。
        // **プロジェクト root 内のファイルだけを見る。** kit の canonical `.agentcms/` は
        // 利用者の生成プロジェクトには存在しないため参照しない
        // （canonical ↔ template の同期は CLI の sync-template-context --check の責務）
        /** @var array<string, mixed> $registry */
        $registry = json_decode(
            (string) file_get_contents(base_path('.agentcms/registry/taxonomies.json')),
            true, 32, JSON_THROW_ON_ERROR,
        );

        $this->assertSame(['news'], $registry['taxonomies'][0]['consumers']);
        // registry は index であり定義を複製しない
        $this->assertArrayNotHasKey('terms', $registry['taxonomies'][0]);

        // 索引と宣言の乖離は taxonomy-check が検出する（手書き値の放置を防ぐ）
        $this->assertSame(
            [],
            app(ConsumerRegistry::class)->problems(['news_category'], ['news_category' => ['news']]),
        );
    }

    /** A-6: 通常の Web request で暗黙の DB write が起きない（PV-6 / SY-3） */
    public function test_a6_runtime_requestで暗黙の_d_b_writeが起きない(): void
    {
        Taxonomy::query()->delete();

        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));

            foreach (['insert ', 'update ', 'delete ', 'create table', 'alter table'] as $write) {
                if (str_starts_with($sql, $write)) {
                    $writes[] = $sql;
                }
            }
        });

        $this->get('/')->assertSuccessful();
        $this->get('/news')->assertSuccessful();

        $this->assertSame([], $writes, '起動 / 表示で書き込みが発生した: '.implode(' / ', $writes));
        $this->assertSame(0, Taxonomy::query()->count(), 'runtime で taxonomy が作られた');
    }

    // ─────────────────────────────────────────────────────────────
    // B. 既存 News 互換（NC-1 / NC-2 / NC-3）
    // ─────────────────────────────────────────────────────────────

    /** B-1 / B-4 */
    public function test_b1_分類未設定の既存newsを取得できる(): void
    {
        $this->seed(DemoContentSeeder::class);
        $news = News::query()->firstOrFail();

        $result = app(GetContent::class)->execute('news', (int) $news->id, $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($news->title, $result->data['item']['title']);
        $this->assertSame([], $result->data['item']['taxonomies']['news_category']['terms']);
        $this->assertSame('カテゴリ', $result->data['item']['taxonomies']['news_category']['label']);
        $this->assertTrue($result->data['item']['taxonomies']['news_category']['multiple']);
        $this->assertFalse($result->data['item']['taxonomies']['news_category']['required']);
    }

    /** B-2 / B-5 */
    public function test_b2_分類未設定の既存newsを編集できる(): void
    {
        $this->seed(DemoContentSeeder::class);
        $news = News::query()->firstOrFail();

        $result = app(UpdateContent::class)
            ->execute('news', (int) $news->id, ['title' => '見出しだけ更新'], $this->admin, partial: true);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame('見出しだけ更新', (string) News::query()->findOrFail($news->id)->title);
        $this->assertSame(0, TaxonomyTermAssignment::query()->count(), '通常更新で assignment が生まれた');
        $this->assertArrayNotHasKey('taxonomies', $result->meta, '触っていないのに meta を出した');
    }

    /** B-3: 公開状態のまま扱える */
    public function test_b3_分類未設定の既存newsを公開状態のまま扱える(): void
    {
        $this->seed(DemoContentSeeder::class);

        $published = News::query()->where('status', 'published')->firstOrFail();

        $this->get('/news')->assertSuccessful()->assertSee($published->title, false);

        if (Route::has('news.show')) {
            $this->get('/news/'.$published->slug)->assertSuccessful();
        }

        app(UpdateContent::class)
            ->execute('news', (int) $published->id, ['title' => '公開のまま更新'], $this->admin, partial: true);

        $fresh = News::query()->findOrFail($published->id);
        $this->assertSame('published', (string) $fresh->status);
        $this->get('/news/'.$fresh->slug)->assertSuccessful();
    }

    /** B-6: News テーブルに分類カラムが増えていない */
    public function test_b6_newsテーブルに分類カラムが無い(): void
    {
        $columns = Schema::getColumnListing('news');

        foreach (['category', 'categories', 'news_category', 'taxonomy_id', 'term_id', 'taxonomies'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "news へ {$forbidden} カラムが増えている");
        }

        /*
         * 既存カラム構成は Phase 9.2B のまま。
         *
         * `data` だけは Phase 9.5-B で **Field Group の値の受け皿として**足した
         * （owner 決定 G2-(a)。nullable・既存行は NULL・分類とは無関係）。
         * 分類が news のカラムを増やしていないことが、このテストで見たいことである。
         *
         * ゴミ箱の 3 列は Phase 9.8 / STAGE 21B（OG1 = (b)）が**全投稿タイプへ**足した。
         * これも分類とは無関係で、`news` だけに起きたことではない。
         *
         * `wp_status` は Phase 9.14 / STAGE 27C（OG13）が**全投稿タイプへ**足した、
         * 取り込み元の公開状態の控えである（SPEC §C.8.3）。これも分類とは無関係で、
         * field としては宣言されていないので編集フォームにも出ない。
         *
         * `sort_order` は P1 ② が**全投稿タイプへ**足した、手で決めた掲載順である。
         * 既定 0 で、並べ替えを 1 度もしていなければ並びは変わらない。これも分類とは無関係で、
         * field としては宣言されていない（`RESERVED_COLUMN_KEYS`）ので編集フォームにも出ない。
         *
         * 一覧を緩めず、増えた分をそのまま書き足して固定し続ける。
         */
        $this->assertSame(
            ['id', 'title', 'slug', 'body', 'status', 'published_at', 'seo_title', 'seo_description',
                'created_at', 'updated_at', 'data', 'deleted_at', 'trashed_by', 'lock_version',
                // 標準記事の紹介欄。分類を保存する列ではない。
                'wp_status', 'sort_order', 'main_image_id', 'excerpt'],
            $columns,
        );
    }

    /** B-7: 既存 News への自動 backfill が 0 件 */
    public function test_b7_既存newsへのbackfillが0件(): void
    {
        $this->seed(DemoContentSeeder::class);

        $this->assertGreaterThan(0, News::query()->count(), '前提となる既存 News が無い');

        // 同期を何度実行しても assignment も term も作られない
        app(TaxonomySynchronizer::class)->sync();
        app(TaxonomySynchronizer::class)->sync();

        $this->assertSame(0, TaxonomyTerm::query()->count(), 'sync が term を作った');
        $this->assertSame(0, TaxonomyTermAssignment::query()->count(), 'backfill が発生した');
    }

    // ─────────────────────────────────────────────────────────────
    // C. Assignment（NC-4）
    // ─────────────────────────────────────────────────────────────

    /** C-1〜C-5 */
    public function test_c1_作成時に複数termをassignmentできる(): void
    {
        [$press, $topics] = $this->makeTerms('プレスリリース', 'お知らせ');

        $created = app(CreateContent::class)->execute('news', [
            'title' => '複数分類の記事', 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->admin, TaxonomyPayload::of(['news_category' => [$press->id, $topics->id]]));

        $this->assertTrue($created->isSuccess(), (string) $created->message);

        $id = (int) $created->data['item']['id'];
        $this->assertSame(2, TaxonomyTermAssignment::query()->count());
        $this->assertSame(
            [$press->id, $topics->id],
            array_column($created->data['item']['taxonomies']['news_category']['terms'], 'id'),
        );

        // GetContent
        $get = app(GetContent::class)->execute('news', $id, $this->admin);
        $this->assertSame(
            [$press->id, $topics->id],
            array_column($get->data['item']['taxonomies']['news_category']['terms'], 'id'),
        );

        // ListContent
        $list = app(ListContent::class)->execute('news', $this->admin);
        $row = collect($list->data['items'])->firstWhere('id', $id);
        $this->assertSame(
            [$press->id, $topics->id],
            array_column($row['taxonomies']['news_category']['terms'], 'id'),
        );
    }

    /** C-6 / C-7 / C-8 / C-9 */
    public function test_c6_置換_全解除_省略のふるまい(): void
    {
        [$press, $topics] = $this->makeTerms('プレスリリース', 'お知らせ');
        $id = $this->makeNews('分類つき記事', [$press->id, $topics->id]);

        // 1 件へ置換
        $replaced = app(UpdateContent::class)->execute(
            'news', $id, [], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => [$topics->id]]),
        );

        $this->assertTrue($replaced->isSuccess(), (string) $replaced->message);
        $this->assertSame([$topics->id], $this->assigned($id));
        $this->assertSame(1, $replaced->meta['taxonomies']['removed']);

        // taxonomies キー省略 → 維持（本体だけ更新）
        $kept = app(UpdateContent::class)
            ->execute('news', $id, ['title' => '本体だけ更新'], $this->admin, partial: true);

        $this->assertTrue($kept->isSuccess());
        $this->assertSame([$topics->id], $this->assigned($id), '省略で assignment が変わった');
        $this->assertSame('本体だけ更新', (string) News::query()->findOrFail($id)->title);

        // 空配列 → 全解除
        $cleared = app(UpdateContent::class)->execute(
            'news', $id, [], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => []]),
        );

        $this->assertTrue($cleared->isSuccess());
        $this->assertSame([], $this->assigned($id));

        // 本体の通常フィールドは壊れていない
        $news = News::query()->findOrFail($id);
        $this->assertSame('本体だけ更新', (string) $news->title);
        $this->assertSame('draft', (string) $news->status);
        $this->assertNotSame('', (string) $news->slug);
    }

    /** C-10: Audit は要約だけ */
    public function test_c10_auditにはadded_kept_removedの要約だけが残る(): void
    {
        [$press] = $this->makeTerms('プレスリリース');
        $id = $this->makeNews('監査テスト', []);
        DB::table('audit_logs')->delete();

        app(UpdateContent::class)->execute(
            'news', $id, [], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => [$press->id]]),
        );

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.update')->orderByDesc('id')->value('metadata');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($metadata, true);

        $this->assertSame(['added' => 1, 'kept' => 0, 'removed' => 0], [
            'added' => $decoded['added'], 'kept' => $decoded['kept'], 'removed' => $decoded['removed'],
        ]);
        $this->assertStringNotContainsString('プレスリリース', $metadata, 'term 名を記録した');
        $this->assertStringNotContainsString($press->id, $metadata, 'term ID を記録した');
        $this->assertStringNotContainsString('press', $metadata, 'slug を記録した');
    }

    // ─────────────────────────────────────────────────────────────
    // D. DB 側 filter の実証（NC-4 / §N）
    //
    // **公開 filter 契約はまだ作らない。** ここで確かめるのは
    // 「assignment テーブルで DB 側から絞り込める形になっているか」だけ。
    // ─────────────────────────────────────────────────────────────

    public function test_d_termでの_d_b側filterが成立する(): void
    {
        [$termA, $termB] = $this->makeTerms('カテゴリA', 'カテゴリB');

        $newsA = $this->makeNews('記事A', [$termA->id]);
        $newsB = $this->makeNews('記事B', [$termB->id]);
        $unassigned = $this->makeNews('未分類の記事', []);

        // 別 content type に同じ term を付けても混ざらないことを確かめるための行
        $work = Work::query()->create([
            'title' => '実績', 'slug' => 'work-'.uniqid(), 'category' => 'web',
            'body' => '<p>本文</p>', 'status' => 'draft',
        ]);
        TaxonomyTermAssignment::query()->create([
            'term_id' => $termA->id, 'content_type' => 'works', 'content_id' => $work->id,
        ]);

        $sqls = [];
        DB::listen(static function ($query) use (&$sqls): void {
            $sqls[] = strtolower(str_replace('`', '"', $query->sql));
        });

        $idsA = $this->filterByTerm($termA->id);
        $idsB = $this->filterByTerm($termB->id);

        $this->assertSame([$newsA], $idsA, 'term A の絞り込み結果が違う');
        $this->assertSame([$newsB], $idsB, 'term B の絞り込み結果が違う');
        $this->assertNotContains($unassigned, $idsA);
        $this->assertNotContains($unassigned, $idsB);

        // 1 回の SELECT で DB 側が絞り込んでいる（PHP で全件取得していない）
        $this->assertCount(2, $sqls, '想定外のクエリが増えている: '.implode(' / ', $sqls));

        foreach ($sqls as $sql) {
            $this->assertStringContainsString('taxonomy_term_assignments', $sql);
            $this->assertStringContainsString('content_type', $sql, 'content_type 条件が無い');
            $this->assertStringContainsString('term_id', $sql);
            // 一覧を丸ごと引いてから絞る形になっていない
            $this->assertStringNotContainsString('select * from "news" where 1', $sql);
        }
    }

    /** MySQL では assignment 側のアクセスが index を使えることまで確かめる */
    public function test_d_filterがassignmentのindexを使える(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // SQLite の EXPLAIN は形式が異なるため、index の存在だけを事実として固定する
            $this->assertTrue(Schema::hasTable('taxonomy_term_assignments'));

            return;
        }

        [$term] = $this->makeTerms('カテゴリA');
        $this->makeNews('記事A', [$term->id]);

        $plan = DB::select(
            'explain select id from news where id in (
                select content_id from taxonomy_term_assignments
                where content_type = ? and term_id = ?
             )',
            ['news', $term->id],
        );

        $assignmentRow = collect($plan)
            ->first(static fn (object $row): bool => ($row->table ?? '') === 'taxonomy_term_assignments');

        $this->assertNotNull($assignmentRow, 'EXPLAIN に assignment テーブルが出ない');
        $this->assertNotNull($assignmentRow->key, 'assignment のアクセスが index を使っていない');
        $this->assertStringNotContainsString('ALL', (string) $assignmentRow->type, '全表走査になっている');
    }

    /**
     * このStage では公開 filter 契約を増やしていない。
     *
     * **MCP tool の入力 schema は CLI 側テスト（`packages/cli/tests/mcp.test.ts`）の責務。**
     * 利用者の生成プロジェクトに `packages/cli/` は存在しないので、ここからは読まない。
     */
    public function test_d_公開filter契約を増やしていない(): void
    {
        // content.list の引数は従来どおり（type / context / search / page / perPage）
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(ListContent::class, 'execute'))->getParameters(),
        );

        $this->assertSame(['type', 'context', 'search', 'page', 'perPage'], $parameters);

        // #14 / #15 で増えたのは分類の 7 操作だけ。**絞り込み用の操作は増えていない**

        foreach ([
            'content.filter', 'content.list.byTerm', 'term.contents', 'taxonomy.filter',
        ] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // E. Works 非回帰（NC-6）
    // ─────────────────────────────────────────────────────────────

    public function test_e_worksは非回帰(): void
    {
        $works = app(SchemaRegistry::class)->get('works');

        // E-1: 宣言なし
        $this->assertSame([], $works->taxonomies);
        $this->assertSame([], $works->taxonomyKeys());

        // E-2: category は select のまま
        $category = $works->field('category');
        $this->assertSame('select', $category->type);
        $this->assertTrue($category->required);

        // E-4: 同梱 JSON の choices を **削らない・並べ替えない**。
        //
        // STAGE #24 以降、project override が選択肢を **足す**ことがある
        // （開発機では base から外した表記をそのまま有効に保つために使っている）。
        // そこで「完全一致」ではなく「先頭が同梱 JSON のままであること」を固定する。
        // 削除・並べ替え・大小の付け替えはここで落ちる。
        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents(base_path('content/schemas/works.json')), true);
        $rawCategory = collect($raw['fields'])->firstWhere('key', 'category');

        $this->assertSame(
            $rawCategory['choices'],
            array_slice($category->choices, 0, count($rawCategory['choices'])),
            '同梱 schema の選択肢が削られたか並べ替えられている',
        );
        $this->assertContains('web', $category->choices);

        // 同梱 JSON 自身に重複も casefold 重複も無い（F-T1）
        $this->assertSame(
            count($rawCategory['choices']),
            count(array_unique($rawCategory['choices'])),
        );
        $this->assertSame(
            count($rawCategory['choices']),
            count(array_unique(array_map('mb_strtolower', $rawCategory['choices']))),
            '同梱 schema に大文字小文字だけが違う選択肢が残っている',
        );

        // E-3: DB カラムは varchar(40) NOT NULL
        $column = collect(Schema::getColumns('works'))->firstWhere('name', 'category');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable'], 'category が nullable になっている');
        $this->assertStringContainsString('varchar', strtolower((string) $column['type_name']));

        // 長さ 40 は driver によって type / type_name のどちらかに出る
        $definition = strtolower((string) $column['type'].' '.(string) $column['type_name']);

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString('varchar(40)', $definition, 'category の長さが 40 でない');
        } else {
            // SQLite は長さを保持しないので、migration 定義側で固定する
            $this->assertStringContainsString(
                "string('category', 40)",
                (string) file_get_contents(
                    base_path('modules/Works/database/migrations/2026_08_08_100002_create_works_table.php'),
                ),
            );
        }
    }

    /** E-5: works の保存・表示・validation を変えていない */
    public function test_e_worksの保存と表示を変えていない(): void
    {
        $created = app(CreateContent::class)->execute('works', [
            'title' => '実績テスト', 'category' => 'web', 'body' => '<p>本文</p>', 'status' => 'published',
            'published_at' => now()->subDay()->toDateTimeString(),
        ], $this->admin);

        $this->assertTrue($created->isSuccess(), (string) $created->message);

        $work = Work::query()->findOrFail((int) $created->data['item']['id']);
        $this->assertSame('web', (string) $work->category, 'category が文字列で保存されていない');

        // 分類は宣言していないので read response も空
        $this->assertSame([], $created->data['item']['taxonomies']);

        // choices 外は従来どおり validation error
        $invalid = app(CreateContent::class)->execute('works', [
            'title' => '不正カテゴリ', 'category' => 'nope', 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->admin);

        $this->assertFalse($invalid->isSuccess());
        $this->assertArrayHasKey('category', $invalid->errors);

        if (Route::has('works.show')) {
            $this->get('/works/'.$work->slug)->assertSuccessful();
        }
    }

    /** E-6: works 用の term / assignment を作っていない */
    public function test_e_works用のterm_assignmentを作っていない(): void
    {
        $this->seed(DemoContentSeeder::class);
        app(TaxonomySynchronizer::class)->sync();

        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertSame(
            0,
            TaxonomyTermAssignment::query()->where('content_type', 'works')->count(),
        );
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * term は **Application Service 経由**で作る（Seeder を触らない — §5）。
     *
     * @return list<TaxonomyTerm>
     */
    private function makeTerms(string ...$names): array
    {
        $terms = [];

        foreach ($names as $name) {
            $result = app(CreateTerm::class)
                ->execute(['taxonomyKey' => 'news_category', 'name' => $name], $this->admin);

            $this->assertTrue($result->isSuccess(), (string) $result->message);
            $terms[] = TaxonomyTerm::query()->findOrFail($result->data['term']['id']);
        }

        return $terms;
    }

    /** @param  list<string>  $termIds */
    private function makeNews(string $title, array $termIds): int
    {
        $result = app(CreateContent::class)->execute('news', [
            'title' => $title, 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->admin, $termIds === [] ? null : TaxonomyPayload::of(['news_category' => $termIds]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    /** @return list<string> */
    private function assigned(int $contentId): array
    {
        return TaxonomyTermAssignment::query()
            ->where('content_type', 'news')
            ->where('content_id', $contentId)
            ->orderBy('sort_order')
            ->pluck('term_id')
            ->all();
    }

    /**
     * **DB 側**で term 絞り込みを行う（PHP で全件取得してから絞らない）。
     *
     * `content_type` を必ず条件に含め、別 Content Type の assignment と混同しない。
     * `taxonomy_term_assignments (content_type, content_id)` と `(term_id)` の
     * index を使える形にしてある。
     *
     * @return list<int>
     */
    private function filterByTerm(string $termId): array
    {
        return News::query()
            ->whereIn('id', function ($query) use ($termId): void {
                $query->select('content_id')
                    ->from('taxonomy_term_assignments')
                    ->where('content_type', 'news')
                    ->where('term_id', $termId);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
