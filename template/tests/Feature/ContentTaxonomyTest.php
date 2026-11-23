<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\SchemaRegistry;
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
use Modules\News\Models\News;
use PDOException;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3B の受け入れ — SPEC §AF 計画 **#10 / #11 / #12**。
 *
 * - `ContentTypeResolver::knows()` 基準の `content_type` 判定（CT-1〜CT-9）
 * - generic content payload への `taxonomies` 統合（§K.1 / §K.2 / §K.3）
 * - `DeleteContent` での assignment 先行削除（§G.5）
 *
 * **同梱 schema（news.json / works.json）は変更しない。**
 * テストは一時ディレクトリへ複製した schema に `taxonomies` を足して検証する。
 */
final class ContentTaxonomyTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private string $schemaPath = '';

    private OperationContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = OperationContext::admin($this->operatorId(), 'admin');
        $this->useSchemasWithTaxonomies();

        app(TaxonomySynchronizer::class)->sync();
        $this->makeTaxonomy('news_single');
        $this->makeTaxonomy('news_required');
        $this->makeTaxonomy('other_tax');
    }

    protected function tearDown(): void
    {
        if ($this->schemaPath !== '' && is_dir($this->schemaPath)) {
            foreach (glob($this->schemaPath.'/*/*.json') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->schemaPath.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->schemaPath.'/overrides');
            @rmdir($this->schemaPath);
        }

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // content_type 判定（CT-1〜CT-9）
    // ─────────────────────────────────────────────────────────────

    /** CT-1 / CT-2 / CT-5 */
    public function test_news_works_productだけがassignment対象になる(): void
    {
        $resolver = app(ContentTypeResolver::class);

        foreach (['news', 'works', 'product'] as $type) {
            $this->assertTrue($resolver->knows($type), "{$type} が拒否された");
        }
    }

    /** CT-5: 3 タイプすべてで実際に assignment を書ける（news 専用実装にしない） */
    public function test_news以外のtypeにもassignmentを保存できる(): void
    {
        $term = $this->makeTerm('news_category', 'press');

        foreach (['news', 'works', 'product'] as $type) {
            $id = $this->createEntry($type);

            $result = app(UpdateContent::class)->execute(
                $type, $id, [], $this->admin, partial: true,
                taxonomies: TaxonomyPayload::of(['news_category' => [$term->id]]),
            );

            $this->assertTrue($result->isSuccess(), "{$type} で失敗した: ".(string) $result->message);
            $this->assertDatabaseHas('taxonomy_term_assignments', [
                'content_type' => $type, 'content_id' => $id, 'term_id' => $term->id,
            ]);
        }
    }

    /** CT-6: schema はあるが Model 登録が無い / Model はあるが schema が無い */
    public function test_media_site_contactは拒否される(): void
    {
        $resolver = app(ContentTypeResolver::class);

        foreach (['media', 'site', 'contact'] as $type) {
            $this->assertTrue(app(SchemaRegistry::class)->has($type), "{$type} の schema が無い");
            $this->assertFalse($resolver->knows($type), "{$type} が許可された");
            $this->assertSame(
                OperationStatus::NotFound,
                app(CreateContent::class)->execute($type, [], $this->admin)->status,
            );
        }
    }

    /** CT-3: 片方の registry だけに存在する type を許可しない */
    public function test_片方のregistryだけに存在するtypeを拒否する(): void
    {
        $resolver = app(ContentTypeResolver::class);

        // Model だけ登録されていて schema が無い
        app(ContentTypeRegistry::class)->register('ghost', News::class);
        $this->assertTrue(app(ContentTypeRegistry::class)->has('ghost'));
        $this->assertFalse(app(SchemaRegistry::class)->has('ghost'));
        $this->assertFalse($resolver->knows('ghost'));

        // schema だけあって Model が無い
        $this->assertTrue(app(SchemaRegistry::class)->has('media'));
        $this->assertFalse(app(ContentTypeRegistry::class)->has('media'));
        $this->assertFalse($resolver->knows('media'));
    }

    /** CT-7 */
    public function test_fqcn_テーブル名_未知文字列_大文字小文字_空白を拒否する(): void
    {
        $rejected = [
            News::class,
            'Modules\\News\\Models\\News',
            'taxonomy_terms',
            'taxonomy_term_assignments',
            'products',
            'nope',
            'News',
            'NEWS',
            ' news',
            'news ',
            ' news ',
            "news\n",
            '',
        ];

        foreach ($rejected as $type) {
            $this->assertFalse(
                app(ContentTypeResolver::class)->knows($type),
                "[{$type}] が許可された",
            );
            $this->assertSame(
                OperationStatus::NotFound,
                app(CreateContent::class)->execute($type, [], $this->admin)->status,
                "[{$type}] が作成できてしまった",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // CT-8 の status（STAGE 3B CORRECTION / F-3B-2）
    //
    // taxonomy payload を伴う入力経路だけ validation error にし、
    // 既存の generic content lookup（未知 type = notFound）は変えない
    // ─────────────────────────────────────────────────────────────

    /** @return list<string> knows() が false になる入力（CT-7） */
    private function rejectedTypes(): array
    {
        return [
            'media', 'site', 'contact',
            'ghost',
            News::class,
            'Modules\\News\\Models\\News',
            'taxonomy_terms',
            'taxonomy_term_assignments',
            'products',
            'nope',
            'News', 'NEWS',
            ' news', 'news ', ' news ',
            '',
        ];
    }

    /** CT-8: taxonomy payload ありの create は validation error */
    public function test_ct8_taxonomy_payloadありのcreateはvalidation_error(): void
    {
        // Model だけ登録されていて schema が無い type も同じ扱いにする
        app(ContentTypeRegistry::class)->register('ghost', News::class);

        $payload = TaxonomyPayload::of(['news_category' => ['press']]);

        foreach ($this->rejectedTypes() as $type) {
            $result = app(CreateContent::class)
                ->execute($type, $this->newsAttributes(), $this->admin, $payload);

            $this->assertSame(
                OperationStatus::ValidationError, $result->status, "[{$type}] が validation error でない",
            );
            $this->assertArrayHasKey('type', $result->errors, "[{$type}] の error key が type でない");
        }

        $this->assertSame(0, News::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());

        // 拒否も **1 試行 1 行**で残る（Correction 2-5）。
        // 残ってよいのは validation_error だけで、成功は 1 行も無い
        $this->assertSame(
            count($this->rejectedTypes()),
            DB::table('audit_logs')->where('operation', 'content.create')->count(),
        );
        $this->assertSame(
            ['validation_error'],
            DB::table('audit_logs')->distinct()->pluck('result')->sort()->values()->all(),
        );
    }

    /** CT-8: taxonomy payload ありの update は validation error */
    public function test_ct8_taxonomy_payloadありのupdateはvalidation_error(): void
    {
        app(ContentTypeRegistry::class)->register('ghost', News::class);

        $id = $this->createEntry('news');
        DB::table('audit_logs')->delete();

        $payload = TaxonomyPayload::of(['news_category' => ['press']]);

        foreach ($this->rejectedTypes() as $type) {
            $result = app(UpdateContent::class)
                ->execute($type, $id, [], $this->admin, partial: true, taxonomies: $payload);

            $this->assertSame(
                OperationStatus::ValidationError, $result->status, "[{$type}] が validation error でない",
            );
            $this->assertArrayHasKey('type', $result->errors);
        }

        $this->assertSame(0, TaxonomyTermAssignment::query()->count());

        // 拒否された試行だけが残り、成功は 1 行も無い（Correction 2-5）
        $this->assertSame(
            count($this->rejectedTypes()),
            DB::table('audit_logs')->where('operation', 'content.update')->count(),
        );
        $this->assertSame(
            ['validation_error'],
            DB::table('audit_logs')->distinct()->pluck('result')->sort()->values()->all(),
        );
    }

    /** CT-8: 拒否時に DB へ 1 度も触らない（modelClass() へ入力を渡していない証拠） */
    public function test_ct8_拒否時に_d_bへ触れずmodelclassへ渡さない(): void
    {
        $payload = TaxonomyPayload::of(['news_category' => ['press']]);

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $sql = strtolower($query->sql);

            // 認可（STAGE #18A）は操作者を 1 度だけ引く。ここで見たいのは
            // **コンテンツ側の DB へ触れていないこと**なので、操作者の解決は除く
            if (str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`')) {
                return;
            }

            // 拒否の記録（Correction 2-5）は監査テーブルへの追記であって
            // コンテンツ側ではない。別途 result まで検証する
            if (str_contains($sql, 'audit_logs')) {
                return;
            }

            $statements[] = $sql;
        });

        foreach (['media', News::class, 'taxonomy_terms', 'News', ' news '] as $type) {
            app(CreateContent::class)->execute($type, $this->newsAttributes(), $this->admin, $payload);
            app(UpdateContent::class)->execute($type, 1, [], $this->admin, partial: true, taxonomies: $payload);
        }

        $this->assertSame([], $statements, 'コンテンツ側の DB へ触れた: '.implode(' / ', $statements));

        // 触れたのは監査だけ。しかも結末は全て拒否で、成功は 1 行も無い
        $this->assertSame(
            ['validation_error'],
            DB::table('audit_logs')->distinct()->pluck('result')->sort()->values()->all(),
        );

        // modelFor() は登録が無い type に対して例外を投げる = 到達していたら別の結果になっていた
        $this->expectException(\InvalidArgumentException::class);
        app(ContentTypeRegistry::class)->modelFor('taxonomy_terms');
    }

    /** CT-8: MCP / OperationCatalog 経路 */
    public function test_ct8_mcp経路でもvalidation_error(): void
    {
        $catalog = app(OperationCatalog::class);
        $agent = OperationContext::agent(OperationInterface::Mcp, 'test-agent');

        foreach (['media', 'taxonomy_terms', 'News', ' news '] as $type) {
            $created = $catalog->invoke('content.create', [
                'type' => $type, 'data' => $this->newsAttributes(),
                'taxonomies' => ['news_category' => ['press']],
            ], $agent);

            $updated = $catalog->invoke('content.update', [
                'type' => $type, 'id' => 1, 'data' => [],
                'taxonomies' => ['news_category' => ['press']],
            ], $agent);

            $this->assertSame(OperationStatus::ValidationError, $created->status, $type);
            $this->assertSame(OperationStatus::ValidationError, $updated->status, $type);
        }

        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    /** CT-8: API update 経路 */
    public function test_ct8_api経路でもvalidation_error(): void
    {
        $user = User::factory()->create();

        foreach (['media', 'taxonomy_terms', 'News'] as $type) {
            $this->actingAs($user)
                ->patchJson("/api/v1/content/{$type}/1", [
                    'data' => [], 'taxonomies' => ['news_category' => ['press']],
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    /** CT-8: taxonomy payload が無い既存操作は **notFound のまま** */
    public function test_ct8_taxonomy_payloadなしはnot_foundのまま(): void
    {
        app(ContentTypeRegistry::class)->register('ghost', News::class);

        foreach ($this->rejectedTypes() as $type) {
            $this->assertSame(
                OperationStatus::NotFound,
                app(CreateContent::class)->execute($type, [], $this->admin)->status,
                "create [{$type}]",
            );
            $this->assertSame(
                OperationStatus::NotFound,
                app(UpdateContent::class)->execute($type, 1, [], $this->admin, partial: true)->status,
                "update [{$type}]",
            );
            $this->assertSame(
                OperationStatus::NotFound,
                app(GetContent::class)->execute($type, 1, $this->admin)->status,
                "get [{$type}]",
            );
            $this->assertSame(
                OperationStatus::NotFound,
                app(ListContent::class)->execute($type, $this->admin)->status,
                "list [{$type}]",
            );
            $this->assertSame(
                OperationStatus::NotFound,
                app(DeleteContent::class)->execute($type, 1, $this->admin)->status,
                "delete [{$type}]",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // assignment の範囲ロック（STAGE 3B CORRECTION / F-3B-3）
    // ─────────────────────────────────────────────────────────────

    /** assignment の読み取りに FOR UPDATE を付けない（gap lock を作らない） */
    public function test_assignment読み取りにfor_updateを付けない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        // 既存 assignment がある状態（currentAssignments が行を読む経路）も通す
        $this->assign('news', $id, $term);

        $sqls = [];
        DB::listen(static function ($query) use (&$sqls): void {
            $sqls[] = strtolower(str_replace('`', '"', $query->sql));
        });

        $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));

        $assignmentReads = array_values(array_filter(
            $sqls,
            static fn (string $sql): bool => str_starts_with($sql, 'select')
                && str_contains($sql, 'taxonomy_term_assignments'),
        ));

        // currentAssignments と verifyFinalState の 2 本が必ず出る
        $this->assertGreaterThanOrEqual(2, count($assignmentReads), 'assignment を読んでいない');

        foreach ($assignmentReads as $sql) {
            $this->assertStringNotContainsString(
                'for update', $sql, "assignment に範囲ロックを掛けている: {$sql}",
            );
        }

        $locked = array_values(array_filter(
            $sqls,
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));

        if (DB::connection()->getDriverName() === 'mysql') {
            // 主 mutex（Content Entry 行）と term 行のロックは維持されている
            $this->assertNotSame([], $locked);
            $this->assertStringContainsString('from "news"', $locked[0]);
        } else {
            // SQLite は lockForUpdate を無視する（no-op）
            $this->assertSame([], $locked);
        }
    }

    /** 実装側でも assignment query に lockForUpdate() が残っていない */
    public function test_assignment_queryにlock_for_updateが残っていない(): void
    {
        $code = (string) file_get_contents(
            base_path('core/src/Application/Content/ContentTaxonomyAssignments.php'),
        );

        // コメントを除いた実コードでの lockForUpdate() は **term 行 lock の 1 箇所だけ**
        $calls = array_values(array_filter(
            explode("\n", $code),
            static fn (string $line): bool => str_contains($line, 'lockForUpdate()')
                && ! str_contains(ltrim($line), '*'),
        ));

        $this->assertCount(1, $calls, "lockForUpdate() の呼び出しが 1 箇所ではない:\n".implode("\n", $calls));
        $this->assertStringContainsString('TaxonomyTerm::query()', $calls[0]);

        // sharedLock も gap lock を残すので使わない（コメントでの言及は除く）
        $shared = array_values(array_filter(
            explode("\n", $code),
            static fn (string $line): bool => str_contains($line, 'sharedLock(')
                && ! str_contains(ltrim($line), '*'),
        ));

        $this->assertSame([], $shared, '共有ロックも gap lock を残すため使わない');
    }

    /** CT-4 / CT-9: assignment 側に type の許可リストを写していない */
    public function test_assignment側にtypeのhardcodeが無い(): void
    {
        $sources = [
            'core/src/Application/Content/ContentTaxonomyAssignments.php',
            'core/src/Application/Content/ContentTaxonomyInput.php',
            'core/src/Application/Content/ContentTaxonomyReader.php',
        ];

        foreach ($sources as $path) {
            $code = (string) file_get_contents(base_path($path));

            foreach (["'news'", "'works'", "'product'", "'media'", "'contact'", "'site'"] as $literal) {
                $this->assertStringNotContainsString($literal, $code, "{$path} に {$literal} がある");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // payload contract（§K.1 / §K.2）
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomies省略時はassignmentへ触れない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $result = app(UpdateContent::class)->execute('news', $id, ['title' => '改題'], $this->admin, partial: true);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
        $this->assertArrayNotHasKey('taxonomies', $result->meta);
    }

    public function test_taxonomies_nullはvalidation_error(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $result = $this->update($id, TaxonomyPayload::of(null));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('taxonomies', $result->errors);
        $this->assertStringContainsString('null は使えません', $result->errors['taxonomies'][0]);
        $this->assertSame([$term->id], $this->assignedIds('news', $id), 'null で解除された');
    }

    public function test_不正なscalarを拒否する(): void
    {
        $id = $this->createEntry('news');

        foreach (['x', 1, 1.5, true, false] as $raw) {
            $result = $this->update($id, TaxonomyPayload::of($raw));

            $this->assertSame(OperationStatus::ValidationError, $result->status, var_export($raw, true));
            $this->assertArrayHasKey('taxonomies', $result->errors);
        }
    }

    /** PHP では空 JSON object と空 list が同じ [] になる境界 */
    public function test_空mapは変更なし_非空のnumeric_listは拒否(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        // 空（{} と [] は PHP では区別できない）→ 対象 key が無いので変更なし
        $empty = $this->update($id, TaxonomyPayload::of([]));
        $this->assertTrue($empty->isSuccess());
        $this->assertSame([$term->id], $this->assignedIds('news', $id));

        // 非空の numeric list は taxonomy map として受理しない
        $list = $this->update($id, TaxonomyPayload::of([$term->id]));
        $this->assertSame(OperationStatus::ValidationError, $list->status);
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
    }

    public function test_宣言されていないtaxonomy_keyを拒否する(): void
    {
        $id = $this->createEntry('news');
        $foreign = $this->makeTerm('other_tax', 'x');

        $result = $this->update($id, TaxonomyPayload::of(['other_tax' => [$foreign->id]]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('taxonomies.other_tax', $result->errors);
        $this->assertSame([], $this->assignedIds('news', $id), '未宣言 key を黙って書き込んだ');
    }

    public function test_taxonomy値がlistでない場合を拒否する(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        foreach ([$term->id, 1, true, null, ['id' => $term->id]] as $value) {
            $result = $this->update($id, TaxonomyPayload::of(['news_category' => $value]));

            $this->assertSame(OperationStatus::ValidationError, $result->status, var_export($value, true));
            $this->assertArrayHasKey('taxonomies.news_category', $result->errors);
        }

        $this->assertSame([], $this->assignedIds('news', $id));
    }

    public function test_term要素がstringでない場合を拒否する(): void
    {
        $id = $this->createEntry('news');

        foreach ([1, 1.5, true, null, [], ['id' => 'x'], ''] as $item) {
            $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$item]]));

            $this->assertSame(OperationStatus::ValidationError, $result->status, var_export($item, true));
            $this->assertArrayHasKey('taxonomies.news_category', $result->errors);
        }
    }

    public function test_空listは全解除になる(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => []]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->assignedIds('news', $id));
        $this->assertSame(1, $result->meta['taxonomies']['removed']);
    }

    public function test_重複は1件へ正規化しwarningを返す(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of([
            'news_category' => [$term->id, $term->id, 'press'],
        ]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
        $this->assertSame(1, TaxonomyTermAssignment::query()->count());
        $this->assertNotEmpty($result->meta['warnings']);
    }

    public function test_single_taxonomyに2件以上はvalidation_error(): void
    {
        $a = $this->makeTerm('news_single', 'a');
        $b = $this->makeTerm('news_single', 'b');
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_single' => [$a->id, $b->id]]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame([], $this->assignedIds('news', $id), '先頭だけ採用した');
    }

    public function test_required_taxonomyがcreateで未指定ならvalidation_error(): void
    {
        $result = app(CreateContent::class)->execute('news', $this->newsAttributes(), $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('taxonomies.news_required', $result->errors);
        $this->assertSame(0, News::query()->count(), 'validation 失敗なのに作成された');
    }

    public function test_required_taxonomyが空listならvalidation_error(): void
    {
        $required = $this->makeTerm('news_required', 'must');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $required);

        $result = $this->update($id, TaxonomyPayload::of(['news_required' => []]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame([$required->id], $this->assignedIds('news', $id));
    }

    public function test_optional_taxonomyは未指定でよい(): void
    {
        $required = $this->makeTerm('news_required', 'must');

        $result = app(CreateContent::class)->execute(
            'news', $this->newsAttributes(), $this->admin,
            TaxonomyPayload::of(['news_required' => [$required->id]]),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame(1, TaxonomyTermAssignment::query()->count());
    }

    // ─────────────────────────────────────────────────────────────
    // term ID / slug 解決（§K.3）
    // ─────────────────────────────────────────────────────────────

    public function test_i_dとslugのどちらでも解決できる(): void
    {
        $a = $this->makeTerm('news_category', 'press');
        $b = $this->makeTerm('news_category', 'topics');
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$a->id, 'topics']]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->assignedIds('news', $id));
    }

    public function test_別taxonomyのterm_i_dを拒否する(): void
    {
        $foreign = $this->makeTerm('other_tax', 'x');
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$foreign->id]]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('別の分類', $result->errors['taxonomies.news_category'][0]);
    }

    public function test_別taxonomyのslugを解決しない(): void
    {
        $this->makeTerm('other_tax', 'press');
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => ['press']]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    public function test_存在しない_i_dとslugを拒否し暗黙作成しない(): void
    {
        $id = $this->createEntry('news');
        $before = TaxonomyTerm::query()->count();

        foreach ([TaxonomyId::generate(), 'not-a-term'] as $input) {
            $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$input]]));

            $this->assertSame(OperationStatus::ValidationError, $result->status, $input);
        }

        $this->assertSame($before, TaxonomyTerm::query()->count(), 'term を暗黙作成した');
    }

    public function test_slugの大文字小文字と前後空白を緩和しない(): void
    {
        $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        foreach (['Press', 'PRESS', ' press', 'press ', ' press '] as $input) {
            $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$input]]));

            $this->assertSame(OperationStatus::ValidationError, $result->status, "[{$input}] を受理した");
        }

        $this->assertSame([], $this->assignedIds('news', $id));
    }

    /** MySQL の ci collation / PAD SPACE でだけ一致する slug をバイト比較で弾く */
    public function test_d_b_collationでだけ一致するslugを拒否する(): void
    {
        $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        $matched = TaxonomyTerm::query()->where('slug', 'PRESS')->count()
            + TaxonomyTerm::query()->where('slug', 'press ')->count();

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => ['PRESS']]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(
            [],
            $this->assignedIds('news', $id),
            $matched > 0
                ? 'collation が一致させた値をバイト比較で弾けていない'
                : 'driver は一致させないが、それでも保存された',
        );
    }

    /** K-4: ID と slug の両方で解決できる場合は ID を優先し、meta に注記する */
    public function test_i_dとslugの両方で解決できる場合は_i_d優先(): void
    {
        // 26 桁の数字は ULID としても slug としても妥当になる（唯一の衝突形）
        $collision = '01234567890123456789012345';

        $byId = new TaxonomyTerm;
        $byId->forceFill([
            'id' => $collision, 'taxonomy_id' => $this->taxonomyId('news_category'),
            'name' => 'ID 側', 'slug' => 'id-side', 'sort_order' => 0,
        ])->save();

        $bySlug = $this->makeTerm('news_category', $collision);

        $id = $this->createEntry('news');
        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$collision]]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame([$byId->id], $this->assignedIds('news', $id));
        $this->assertNotSame($bySlug->id, $this->assignedIds('news', $id)[0]);
        $this->assertNotEmpty($result->meta['warnings']);
        $this->assertStringContainsString('ID として解釈', $result->meta['warnings'][0]);
    }

    public function test_最終的なassignmentにはterm_i_dだけを保存する(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        $this->update($id, TaxonomyPayload::of(['news_category' => ['press']]));

        $row = TaxonomyTermAssignment::query()->firstOrFail();
        $this->assertSame($term->id, $row->term_id);
    }

    // ─────────────────────────────────────────────────────────────
    // archived semantics（AS-1〜AS-11）
    // ─────────────────────────────────────────────────────────────

    /** AS-1 */
    public function test_as1_createでarchived_termを指定するとvalidation_error(): void
    {
        $archived = $this->makeTerm('news_category', 'old', archived: true);
        $required = $this->makeTerm('news_required', 'must');

        $result = app(CreateContent::class)->execute(
            'news', $this->newsAttributes(), $this->admin,
            TaxonomyPayload::of([
                'news_required' => [$required->id],
                'news_category' => [$archived->id],
            ]),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString(
            'アーカイブ済み', $result->errors['taxonomies.news_category'][0],
        );
        $this->assertStringContainsString('そのまま残せます', $result->errors['taxonomies.news_category'][0]);
        $this->assertSame(0, News::query()->count(), 'Content Entry が残った');
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    /** AS-2 */
    public function test_as2_未付与のarchived_termをupdateで追加できない(): void
    {
        $archived = $this->makeTerm('news_category', 'old', archived: true);
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$archived->id]]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    /** AS-3 / AS-9: kept は許可され、行を作り直さない */
    public function test_as3_既存のarchived_termをkeptとして維持できる(): void
    {
        $archived = $this->makeTerm('news_category', 'old');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $archived);
        $this->archive($archived);

        $before = TaxonomyTermAssignment::query()->firstOrFail();
        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$archived->id]]));

        $after = TaxonomyTermAssignment::query()->firstOrFail();

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame($before->id, $after->id, 'kept 行を作り直した');
        $this->assertSame(
            (string) $before->created_at, (string) $after->created_at, 'created_at が変わった',
        );
        $this->assertSame(1, $result->meta['taxonomies']['kept']);
        $this->assertSame(0, $result->meta['taxonomies']['added']);
    }

    /** AS-4 */
    public function test_as4_既存のarchived_termを解除できる(): void
    {
        $archived = $this->makeTerm('news_category', 'old');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $archived);
        $this->archive($archived);

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => []]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    /** AS-5 */
    public function test_as5_single_taxonomyでarchivedからactiveへ置換できる(): void
    {
        $old = $this->makeTerm('news_single', 'old');
        $new = $this->makeTerm('news_single', 'new');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $old);
        $this->archive($old);

        $result = $this->update($id, TaxonomyPayload::of(['news_single' => [$new->id]]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame([$new->id], $this->assignedIds('news', $id));
        $this->assertSame(1, $result->meta['taxonomies']['added']);
        $this->assertSame(1, $result->meta['taxonomies']['removed']);
    }

    /** AS-6 */
    public function test_as6_archivedを維持したままactiveを追加できる(): void
    {
        $old = $this->makeTerm('news_category', 'old');
        $new = $this->makeTerm('news_category', 'new');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $old);
        $this->archive($old);

        $result = $this->update($id, TaxonomyPayload::of([
            'news_category' => [$old->id, $new->id],
        ]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $this->assignedIds('news', $id));
        $this->assertSame(1, $result->meta['taxonomies']['kept']);
        $this->assertSame(1, $result->meta['taxonomies']['added']);
    }

    /** AS-7: slug 指定でも added 判定は同じ */
    public function test_as7_archived_termをslugで新規指定できない(): void
    {
        $this->makeTerm('news_category', 'old', archived: true);
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => ['old']]));

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('アーカイブ済み', $result->errors['taxonomies.news_category'][0]);
    }

    /** AS-8: required / cardinality は最終集合で判定する */
    public function test_as8_検証は差分の途中ではなく最終集合で行う(): void
    {
        $a = $this->makeTerm('news_single', 'a');
        $b = $this->makeTerm('news_single', 'b');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $a);

        // 途中では 2 件になるが、最終集合は 1 件なので成功する
        $result = $this->update($id, TaxonomyPayload::of(['news_single' => [$b->id]]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame([$b->id], $this->assignedIds('news', $id));
    }

    /** AS-9: 変更が無い kept 行へ UPDATE を発行しない */
    public function test_as9_変更のないkept行へ_updateを発行しない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));

        $writes = $this->captureSql(function () use ($id, $term): void {
            $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));
        }, 'taxonomy_term_assignments');

        $this->assertSame([], $writes, '不要な書き込みを発行した: '.implode(' / ', $writes));
    }

    /** AS-10 の文言 */
    public function test_as10_archivedのエラー文言が既存維持を案内する(): void
    {
        $archived = $this->makeTerm('news_category', 'old', archived: true);
        $id = $this->createEntry('news');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$archived->id]]));

        $message = $result->errors['taxonomies.news_category'][0];
        $this->assertStringContainsString('アーカイブ済みの分類は新しく設定できません', $message);
        $this->assertStringContainsString('そのまま残せます', $message);
    }

    /** AS-11: read した payload をそのまま書き戻せる */
    public function test_as11_archivedを含むround_tripが壊れない(): void
    {
        $archived = $this->makeTerm('news_category', 'old');
        $active = $this->makeTerm('news_category', 'new');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $archived);
        $this->assign('news', $id, $active, 1);
        $this->archive($archived);

        $read = app(GetContent::class)->execute('news', $id, $this->admin);
        $ids = array_column($read->data['item']['taxonomies']['news_category']['terms'], 'id');

        $this->assertCount(2, $ids, 'archived assignment が read から消えた');

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => $ids]));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame(2, $result->meta['taxonomies']['kept']);
    }

    // ─────────────────────────────────────────────────────────────
    // sort_order
    // ─────────────────────────────────────────────────────────────

    public function test_sort_orderはpayloadの並び順を表す(): void
    {
        $a = $this->makeTerm('news_category', 'a');
        $b = $this->makeTerm('news_category', 'b');
        $id = $this->createEntry('news');

        $this->update($id, TaxonomyPayload::of(['news_category' => [$b->id, $a->id]]));

        $this->assertSame(0, $this->sortOrderOf($id, $b->id));
        $this->assertSame(1, $this->sortOrderOf($id, $a->id));
    }

    public function test_並び順の変更はcreated_atを変えずsort_orderだけ更新する(): void
    {
        $a = $this->makeTerm('news_category', 'a');
        $b = $this->makeTerm('news_category', 'b');
        $id = $this->createEntry('news');

        $this->update($id, TaxonomyPayload::of(['news_category' => [$a->id, $b->id]]));

        $rows = TaxonomyTermAssignment::query()->orderBy('id')->get();
        $ids = $rows->pluck('id')->all();
        $createdAt = $rows->pluck('created_at')->map(static fn ($v): string => (string) $v)->all();

        $this->update($id, TaxonomyPayload::of(['news_category' => [$b->id, $a->id]]));

        $after = TaxonomyTermAssignment::query()->orderBy('id')->get();

        $this->assertSame($ids, $after->pluck('id')->all(), '行を作り直した');
        $this->assertSame(
            $createdAt,
            $after->pluck('created_at')->map(static fn ($v): string => (string) $v)->all(),
            'created_at が変わった',
        );
        $this->assertSame(0, $this->sortOrderOf($id, $b->id));
        $this->assertSame(1, $this->sortOrderOf($id, $a->id));
    }

    // ─────────────────────────────────────────────────────────────
    // transaction / lock
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomy_validation失敗時に_content_entryが作られない(): void
    {
        $required = $this->makeTerm('news_required', 'must');

        $result = app(CreateContent::class)->execute(
            'news', $this->newsAttributes(), $this->admin,
            TaxonomyPayload::of([
                'news_required' => [$required->id],
                'news_category' => [TaxonomyId::generate()],
            ]),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(0, News::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    public function test_assignment_insert失敗時に_content_entry作成もrollbackする(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $required = $this->makeTerm('news_required', 'must');

        $this->failOnQuery('insert into "taxonomy_term_assignments"');

        $result = app(CreateContent::class)->execute(
            'news', $this->newsAttributes(), $this->admin,
            TaxonomyPayload::of([
                'news_required' => [$required->id],
                'news_category' => [$term->id],
            ]),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(0, News::query()->count(), 'Content Entry が残った');
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
        $this->assertStringNotContainsString('insert into', (string) $result->message);
    }

    public function test_assignment失敗時にfield変更もrollbackする(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $title = (string) News::query()->findOrFail($id)->title;

        $this->failOnQuery('insert into "taxonomy_term_assignments"');

        $result = app(UpdateContent::class)->execute(
            'news', $id, ['title' => '変わってはいけない'], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => [$term->id]]),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame($title, (string) News::query()->findOrFail($id)->title);
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    public function test_field保存失敗時にassignment変更もrollbackする(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        $this->failOnQuery('update "news" set');

        $result = app(UpdateContent::class)->execute(
            'news', $id, ['title' => '失敗する更新'], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => [$term->id]]),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame([], $this->assignedIds('news', $id), 'assignment だけ残った');
    }

    /** transaction 前に取得した stale model へ保存しない */
    public function test_lock後のfresh_stateで判定する(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        // find() が成功した後・lock の直前に行が消えるケース
        $this->beforeQuery(
            fn (string $sql): bool => str_contains($sql, 'from "news"') && str_contains($sql, 'limit 1'),
            function () use ($id): void {
                News::query()->whereKey($id)->delete();
            },
            skip: 1,
        );

        $result = app(UpdateContent::class)->execute(
            'news', $id, ['title' => '消えた行への更新'], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of(['news_category' => [$term->id]]),
        );

        $this->assertSame(OperationStatus::NotFound, $result->status);
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    public function test_content_entry行を主_mutexとしてlockする(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');

        $locks = $this->captureLockOrder(function () use ($id, $term): void {
            $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertNotSame([], $locks, 'lock が 1 つも発行されていない');
            $this->assertSame('news', $locks[0], '主 mutex が Content Entry 行ではない');
            $this->assertNotContains('taxonomies', $locks, 'taxonomy 行を lock している');
        } else {
            // SQLite は lockForUpdate を無視する（Laravel の SQLite grammar が no-op）。
            // ここでは「transaction + 事後検証」に縮退することを事実として固定する
            $this->assertSame([], $locks);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // read response（§K.4）
    // ─────────────────────────────────────────────────────────────

    public function test_assignmentが0件でも宣言済みtaxonomyを返す(): void
    {
        $id = $this->createEntry('news');

        $item = app(GetContent::class)->execute('news', $id, $this->admin)->data['item'];

        $this->assertSame(
            ['news_category', 'news_single', 'news_required'],
            array_keys($item['taxonomies']),
        );
        $this->assertSame([], $item['taxonomies']['news_category']['terms']);
        $this->assertTrue($item['taxonomies']['news_category']['multiple']);
        $this->assertFalse($item['taxonomies']['news_category']['required']);
        $this->assertFalse($item['taxonomies']['news_single']['multiple']);
        $this->assertTrue($item['taxonomies']['news_required']['required']);
        $this->assertSame('カテゴリ', $item['taxonomies']['news_category']['label']);
    }

    public function test_single_multiple_archivedをread_responseへ返す(): void
    {
        $single = $this->makeTerm('news_single', 'only');
        $a = $this->makeTerm('news_category', 'a');
        $b = $this->makeTerm('news_category', 'b');
        $id = $this->createEntry('news');

        $this->assign('news', $id, $single);
        $this->assign('news', $id, $a);
        $this->assign('news', $id, $b, 1);
        $this->archive($b);

        $item = app(GetContent::class)->execute('news', $id, $this->admin)->data['item'];

        $this->assertCount(1, $item['taxonomies']['news_single']['terms']);
        $this->assertCount(2, $item['taxonomies']['news_category']['terms']);

        $terms = $item['taxonomies']['news_category']['terms'];
        $this->assertSame(['id', 'name', 'slug', 'archived'], array_keys($terms[0]));
        $this->assertFalse($terms[0]['archived']);
        $this->assertTrue($terms[1]['archived'], 'archived が read から消えた');
    }

    public function test_read_responseはfieldsの既存表現を変えない(): void
    {
        $id = $this->createEntry('news');

        $item = app(GetContent::class)->execute('news', $id, $this->admin)->data['item'];

        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayNotHasKey('data', $item, 'data JSON の引き上げが壊れた');
        $this->assertArrayHasKey('taxonomies', $item);
    }

    public function test_create_update_responseにもtaxonomiesを返す(): void
    {
        $required = $this->makeTerm('news_required', 'must');
        $term = $this->makeTerm('news_category', 'press');

        $created = app(CreateContent::class)->execute(
            'news', $this->newsAttributes(), $this->admin,
            TaxonomyPayload::of(['news_required' => [$required->id]]),
        );

        $this->assertTrue($created->isSuccess(), (string) $created->message);
        $this->assertSame([], $created->data['item']['taxonomies']['news_category']['terms']);

        $id = (int) $created->data['item']['id'];
        $updated = $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));

        $this->assertSame(
            [$term->id],
            array_column($updated->data['item']['taxonomies']['news_category']['terms'], 'id'),
        );
    }

    public function test_listでもtaxonomiesを返し_n1クエリを作らない(): void
    {
        $term = $this->makeTerm('news_category', 'press');

        for ($i = 0; $i < 5; $i++) {
            $id = $this->createEntry('news', "一覧 {$i}");
            $this->assign('news', $id, $term);
        }

        $queries = [];
        DB::listen(static function ($q) use (&$queries): void {
            $queries[] = strtolower($q->sql);
        });

        $result = app(ListContent::class)->execute('news', $this->admin, perPage: 5);

        $this->assertCount(5, $result->data['items']);

        foreach ($result->data['items'] as $item) {
            $this->assertSame([$term->id], array_column($item['taxonomies']['news_category']['terms'], 'id'));
        }

        $assignmentQueries = count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'taxonomy_term_assignments'),
        ));
        $termQueries = count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "taxonomy_terms"')
                || str_contains($sql, 'from `taxonomy_terms`'),
        ));

        $this->assertSame(1, $assignmentQueries, 'assignment を件数ぶん引いている');
        $this->assertSame(1, $termQueries, 'term を件数ぶん引いている');
    }

    public function test_read_responseにtaxonomy_i_dを含めない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $item = app(GetContent::class)->execute('news', $id, $this->admin)->data['item'];
        $json = (string) json_encode($item['taxonomies'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($this->taxonomyId('news_category'), $json);
        $this->assertStringContainsString($term->id, $json);
    }

    /** 予約キーが field と衝突していないこと（同梱 schema の健全性） */
    public function test_同梱schemaにtaxonomiesという名前のfieldが無い(): void
    {
        $registry = new SchemaRegistry(base_path('content/schemas'));

        foreach ($registry->all() as $schema) {
            $this->assertNotContains('taxonomies', $schema->fieldKeys(), "{$schema->id} が予約キーを使っている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Admin / API / MCP 統合
    // ─────────────────────────────────────────────────────────────

    /**
     * assignment 専用の操作は**作らない**（`content_create` / `content_update` の
     * `taxonomies` キーへ統合する — §P.3）。分類の 7 操作は #14 / #15 で別途公開済み。
     */
    public function test_assignment専用のoperationを作っていない(): void
    {

        foreach ([
            'content.taxonomies.set', 'content.taxonomies.update', 'content.assign',
            'term.assign', 'term.unassign', 'assignment.create', 'assignment.delete',
        ] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }

        // assignment 専用 route も無い
        $routes = collect(app('router')->getRoutes())->map(static fn ($r): string => $r->uri())->all();

        foreach ($routes as $uri) {
            $this->assertStringNotContainsString('assignments', $uri);
        }
    }

    public function test_mcpは既存content_toolでtaxonomiesを渡せる(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $required = $this->makeTerm('news_required', 'must');
        $catalog = app(OperationCatalog::class);
        $agent = OperationContext::agent(OperationInterface::Mcp, 'test-agent');

        $created = $catalog->invoke('content.create', [
            'type' => 'news',
            'data' => $this->newsAttributes(),
            'taxonomies' => ['news_required' => [$required->id]],
        ], $agent);

        $this->assertTrue($created->isSuccess(), (string) $created->message);

        $id = (int) $created->data['item']['id'];
        $updated = $catalog->invoke('content.update', [
            'type' => 'news', 'id' => $id, 'data' => [],
            'taxonomies' => ['news_category' => [$term->id]],
        ], $agent);

        $this->assertTrue($updated->isSuccess(), (string) $updated->message);
        $this->assertContains($term->id, $this->assignedIds('news', $id));
    }

    /** $arr() のような正規化で不正値を [] へ潰さない */
    public function test_mcpはtaxonomiesの不正値を黙って無視しない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $catalog = app(OperationCatalog::class);
        $agent = OperationContext::agent(OperationInterface::Mcp, 'test-agent');

        foreach ([null, 'x', 1, true] as $raw) {
            $result = $catalog->invoke('content.update', [
                'type' => 'news', 'id' => $id, 'data' => [], 'taxonomies' => $raw,
            ], $agent);

            $this->assertSame(OperationStatus::ValidationError, $result->status, var_export($raw, true));
        }

        // キー未指定なら触らない
        $untouched = $catalog->invoke('content.update', [
            'type' => 'news', 'id' => $id, 'data' => [],
        ], $agent);

        $this->assertTrue($untouched->isSuccess());
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
    }

    public function test_api_updateはtaxonomiesの3状態を区別する(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);
        $user = User::factory()->create();

        // ① 未指定 → 変更しない
        $this->actingAs($user)
            ->patchJson("/api/v1/content/news/{$id}", ['data' => ['title' => 'API 更新']])
            ->assertSuccessful();
        $this->assertSame([$term->id], $this->assignedIds('news', $id));

        // ② null → validation error
        $this->actingAs($user)
            ->patchJson("/api/v1/content/news/{$id}", ['data' => [], 'taxonomies' => null])
            ->assertStatus(422);
        $this->assertSame([$term->id], $this->assignedIds('news', $id));

        // ③ object → 全置換
        $this->actingAs($user)
            ->patchJson("/api/v1/content/news/{$id}", ['data' => [], 'taxonomies' => ['news_category' => []]])
            ->assertSuccessful();
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    public function test_adminフォームがtaxonomiesを送らなければ変更しない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);
        $user = User::factory()->create();

        $news = News::query()->findOrFail($id);

        $this->actingAs($user)->put("/admin/news/{$id}", [
            'title' => '管理画面から更新',
            'slug' => $news->slug,
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertSame([$term->id], $this->assignedIds('news', $id));
        $this->assertSame('管理画面から更新', (string) News::query()->findOrFail($id)->title);
    }

    public function test_adminはtaxonomiesを通常fieldとして渡さない(): void
    {
        $code = (string) file_get_contents(base_path('core/src/Admin/SchemaResourceController.php'));

        $this->assertStringContainsString('unset($attributes[ContentTaxonomyInput::RESPONSE_KEY])', $code);
        $this->assertStringNotContainsString('TaxonomyTermAssignment', $code, 'adapter に assignment 保存がある');
        $this->assertStringNotContainsString('TaxonomyTerm::', $code, 'adapter に term 検証がある');
    }

    // ─────────────────────────────────────────────────────────────
    // Audit / Result meta
    // ─────────────────────────────────────────────────────────────

    public function test_auditには要約だけを記録する(): void
    {
        $term = $this->makeTerm('news_category', 'プレスリリース');
        $id = $this->createEntry('news');

        $this->update($id, TaxonomyPayload::of(['news_category' => [$term->id]]));

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.update')->orderByDesc('id')->value('metadata');
        $decoded = json_decode($metadata, true);

        $this->assertSame('news', $decoded['type']);
        $this->assertSame($id, $decoded['id']);
        $this->assertSame(1, $decoded['added']);
        $this->assertSame(0, $decoded['removed']);
        $this->assertStringNotContainsString('プレスリリース', $metadata, 'term 名を記録した');
        $this->assertStringNotContainsString($term->id, $metadata, 'term ID を記録した');
    }

    public function test_validation失敗を成功_auditへ記録しない(): void
    {
        $id = $this->createEntry('news');
        DB::table('audit_logs')->delete();

        $this->update($id, TaxonomyPayload::of(['news_category' => [TaxonomyId::generate()]]));

        /*
         * 失敗した試行も 1 行だけ残る（Correction 2-5）。
         * 大事なのは **成功として記録されないこと**なので、
         * 行数ではなく result を見る。
         */
        $rows = DB::table('audit_logs')->where('operation', 'content.update')->get();

        $this->assertCount(1, $rows, '失敗した試行が記録されていない、または二重記録されている');
        $this->assertSame('validation_error', $rows[0]->result, '失敗が成功として記録された');
        $this->assertSame(0, DB::table('audit_logs')->where('result', 'success')->count());
    }

    public function test_result_metaにtypeとassignment件数を統合する(): void
    {
        $a = $this->makeTerm('news_category', 'a');
        $b = $this->makeTerm('news_category', 'b');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $a);

        $result = $this->update($id, TaxonomyPayload::of(['news_category' => [$a->id, $b->id]]));

        $this->assertSame('news', $result->meta['type']);
        $this->assertSame(1, $result->meta['taxonomies']['added']);
        $this->assertSame(1, $result->meta['taxonomies']['kept']);
        $this->assertSame(0, $result->meta['taxonomies']['removed']);
        $this->assertSame(
            ['added' => 1, 'kept' => 1, 'removed' => 0],
            $result->meta['taxonomies']['byKey']['news_category'],
        );
    }

    // ─────────────────────────────────────────────────────────────
    // DeleteContent の assignment cleanup（§G.5）
    // ─────────────────────────────────────────────────────────────

    public function test_confirmation_required時は両方とも変わらない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $result = app(DeleteContent::class)->execute('news', $id, $this->admin);

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotNull(News::query()->find($id));
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
    }

    public function test_不正なtokenでも両方とも変わらない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        app(DeleteContent::class)->execute('news', $id, $this->admin);

        $result = app(DeleteContent::class)
            ->execute('news', $id, $this->admin->withConfirmationToken('でたらめ'));

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotNull(News::query()->find($id));
        $this->assertSame([$term->id], $this->assignedIds('news', $id));
    }

    /**
     * ゴミ箱へ移しても **分類の割り当てを消さない**（Phase 9.8 / STAGE 21C・SPEC §E）。
     *
     * 9.8 より前、`content.delete` は物理削除で、孤児を残さないために
     * 割り当てを先に消してから本体を消していた。**いまは何も消さない。**
     * ゴミ箱はデータを失わない場所なので、割り当てを消すと
     * 「戻したのに分類だけ無い」が起きる。
     */
    public function test_ゴミ箱へ移しても分類の割り当ては残る(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $id = $this->createEntry('news');
        $this->assign('news', $id, $term);

        $order = $this->captureSql(function () use ($id): void {
            $this->confirmDelete('news', $id);
        });

        // 通常の読み取りからは消える。だが行も割り当ても残っている
        $this->assertNull(News::query()->find($id));
        $this->assertSame([$term->id], $this->assignedIds('news', $id));

        $this->assertNull(
            $this->firstIndexOf($order, 'delete from "taxonomy_term_assignments"'),
            '割り当てを消している',
        );
        $this->assertNull($this->firstIndexOf($order, 'delete from "news"'), '本体を消している');
    }

    public function test_割り当てが0件でもゴミ箱へ移せる(): void
    {
        $id = $this->createEntry('news');

        $result = $this->confirmDelete('news', $id);

        $this->assertTrue($result->isSuccess());
        $this->assertNull(News::query()->find($id));
        $this->assertSame([], $this->assignedIds('news', $id));
    }

    public function test_他の_content_entryの割り当てに触れない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $target = $this->createEntry('news', '捨てる方');
        $other = $this->createEntry('news', '残す方');
        $this->assign('news', $target, $term);
        $this->assign('news', $other, $term);

        $this->confirmDelete('news', $target);

        $this->assertSame([$term->id], $this->assignedIds('news', $target));
        $this->assertSame([$term->id], $this->assignedIds('news', $other));
    }

    public function test_同じ_content_idでもtypeが違う割り当てに触れない(): void
    {
        $term = $this->makeTerm('news_category', 'press');
        $newsId = $this->createEntry('news');

        // 別 type の同じ content_id
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'works', 'content_id' => $newsId,
        ]);
        $this->assign('news', $newsId, $term);

        $this->confirmDelete('news', $newsId);

        $this->assertSame([$term->id], $this->assignedIds('news', $newsId));
        $this->assertSame([$term->id], $this->assignedIds('works', $newsId), '別 type まで触った');
    }

    /**
     * 削除の監査は **結末ごとに 1 行**（STAGE #18A Correction 7）。
     *
     * 1 回目（確認待ち）と 2 回目（成功）は別の結末なので、それぞれ残る。
     * 守りたいのは「確認しただけで削除成功として数えない」こと。
     */
    public function test_削除は結末ごとに_auditへ記録する(): void
    {
        $id = $this->createEntry('news');
        app(DeleteContent::class)->execute('news', $id, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'content.delete', 'result' => 'confirmation_required',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'operation' => 'content.delete', 'result' => 'success',
        ]);

        $this->confirmDelete('news', $id);

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'content.delete', 'result' => 'success',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /** 同梱 schema を複製し、`taxonomies` を足した一時 schema へ差し替える */
    private function useSchemasWithTaxonomies(): void
    {
        $source = base_path('content/schemas');
        $this->schemaPath = sys_get_temp_dir().'/agentcms-taxonomy-schemas-'.uniqid();

        mkdir($this->schemaPath.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (in_array($data['id'] ?? '', ['news', 'works', 'product'], true)) {
                $data['taxonomies'] = [
                    ['key' => 'news_category', 'multiple' => true, 'required' => false],
                ];
            }

            if (($data['id'] ?? '') === 'news') {
                $data['taxonomies'][] = ['key' => 'news_single', 'multiple' => false, 'required' => false];
                $data['taxonomies'][] = ['key' => 'news_required', 'multiple' => true, 'required' => true];
            }

            file_put_contents(
                $this->schemaPath.'/'.basename($file),
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob($source.'/overrides/*.json') ?: [] as $file) {
            copy($file, $this->schemaPath.'/overrides/'.basename($file));
        }

        config(['agentcms.schema_path' => $this->schemaPath]);
        app()->forgetInstance(SchemaRegistry::class);
    }

    /** @return array<string, mixed> */
    private function newsAttributes(string $title = 'テスト記事'): array
    {
        return ['title' => $title, 'body' => '<p>本文</p>', 'status' => 'draft'];
    }

    private function createEntry(string $type, string $title = 'テスト記事'): int
    {
        $attributes = match ($type) {
            'works' => ['title' => $title, 'category' => 'web', 'body' => '<p>本文</p>', 'status' => 'draft'],
            'product' => ['title' => $title, 'status' => 'draft'],
            default => $this->newsAttributes($title),
        };

        // required taxonomy を持つ type は、作成時に最小の 1 件を渡す
        $payload = null;

        if ($type === 'news') {
            $term = TaxonomyTerm::query()
                ->where('taxonomy_id', $this->taxonomyId('news_required'))->first()
                ?? $this->makeTerm('news_required', 'required-'.uniqid());
            $payload = TaxonomyPayload::of(['news_required' => [$term->id]]);
        }

        $result = app(CreateContent::class)->execute($type, $attributes, $this->admin, $payload);

        if (! $result->isSuccess()) {
            $this->fail("{$type} の作成に失敗: ".json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        }

        // 作成時に足した required 分は、以後の差分判定へ影響しないよう外しておく
        TaxonomyTermAssignment::query()
            ->where('content_type', $type)
            ->where('content_id', (int) $result->data['item']['id'])
            ->delete();

        return (int) $result->data['item']['id'];
    }

    private function update(int $id, ?TaxonomyPayload $taxonomies): Result
    {
        return app(UpdateContent::class)
            ->execute('news', $id, [], $this->admin, partial: true, taxonomies: $taxonomies);
    }

    private function confirmDelete(string $type, int $id): Result
    {
        $token = app(DeleteContent::class)->execute($type, $id, $this->admin)->confirmation?->token;

        return app(DeleteContent::class)
            ->execute($type, $id, $this->admin->withConfirmationToken($token));
    }

    private function makeTaxonomy(string $key): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => $key,
            'hierarchical' => true, 'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();

        return $taxonomy;
    }

    private function taxonomyId(string $key): string
    {
        return (string) Taxonomy::query()->where('key', $key)->firstOrFail()->id;
    }

    private function makeTerm(string $taxonomyKey, string $slug, bool $archived = false): TaxonomyTerm
    {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $this->taxonomyId($taxonomyKey),
            'name' => $slug,
            'slug' => $slug,
            'sort_order' => 0,
            'archived_at' => $archived ? now() : null,
        ])->save();

        return $term;
    }

    private function archive(TaxonomyTerm $term): void
    {
        $term->forceFill(['archived_at' => now()])->save();
    }

    private function assign(string $type, int $contentId, TaxonomyTerm $term, int $sortOrder = 0): void
    {
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id,
            'content_type' => $type,
            'content_id' => $contentId,
            'sort_order' => $sortOrder,
        ]);
    }

    /** @return list<string> */
    private function assignedIds(string $type, int $contentId): array
    {
        return TaxonomyTermAssignment::query()
            ->where('content_type', $type)
            ->where('content_id', $contentId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('term_id')
            ->all();
    }

    private function sortOrderOf(int $contentId, string $termId): int
    {
        return (int) TaxonomyTermAssignment::query()
            ->where('content_type', 'news')
            ->where('content_id', $contentId)
            ->where('term_id', $termId)
            ->value('sort_order');
    }

    /**
     * 実行された SQL を（引用符を外して）順番に集める。
     *
     * @return list<string>
     */
    private function captureSql(callable $action, ?string $contains = null): array
    {
        $sqls = [];
        DB::listen(static function ($query) use (&$sqls): void {
            $sqls[] = strtolower(str_replace('`', '"', $query->sql));
        });

        $action();

        if ($contains === null) {
            return $sqls;
        }

        return array_values(array_filter(
            $sqls,
            static fn (string $sql): bool => str_contains($sql, $contains)
                && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')
                    || str_starts_with($sql, 'delete')),
        ));
    }

    /** @param  list<string>  $sqls */
    private function firstIndexOf(array $sqls, string $needle): ?int
    {
        foreach ($sqls as $index => $sql) {
            if (str_contains($sql, $needle)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * `for update` を発行したテーブルを順番に集める（MySQL でのみ実際に出る）。
     *
     * @return list<string>
     */
    private function captureLockOrder(callable $action): array
    {
        $tables = [];
        DB::listen(static function ($query) use (&$tables): void {
            $sql = strtolower(str_replace('`', '"', $query->sql));

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if (preg_match('/from "([a-z_]+)"/', $sql, $m) === 1) {
                $tables[] = $m[1];
            }
        });

        $action();

        return $tables;
    }

    /** 指定の SQL が実行される直前に副作用を起こす */
    private function beforeQuery(callable $matches, callable $action, int $skip = 0): void
    {
        $seen = 0;
        $done = false;

        DB::beforeExecuting(function (string $query) use ($matches, $action, $skip, &$seen, &$done): void {
            if ($done || ! $matches(strtolower(str_replace('`', '"', $query)))) {
                return;
            }

            if ($seen++ < $skip) {
                return;
            }

            $done = true;
            $action();
        });
    }

    /** 指定の SQL を失敗させる（driver の引用符差を吸収する） */
    private function failOnQuery(string $needle): void
    {
        DB::beforeExecuting(static function (string $query) use ($needle): void {
            if (! str_contains(strtolower(str_replace('`', '"', $query)), $needle)) {
                return;
            }

            throw new QueryException(
                'testing', $query, [], new PDOException('injected failure'),
            );
        });
    }
}
