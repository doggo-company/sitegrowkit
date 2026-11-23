<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\MediaUsageInspector;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaMutationClassifier;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\OperationCatalog;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Application\UploadMedia;
use Modules\Media\Models\Media;
use Modules\Products\Models\Product;
use Modules\Works\Models\Work;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.2B: Custom Fields foundation の受け入れ（owner 指定 25 項目）。
 *
 * 実験台には synthetic な Product を使い、**既存の News / Works は壊さない**。
 */
final class CustomFieldsTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(DemoContentSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    /** @param array<string, mixed> $overrides */
    private function createProduct(array $overrides = []): Product
    {
        $result = app(CreateContent::class)->execute('product', array_merge([
            'title' => 'テスト商品',
            'description' => '<p>説明</p>',
            'price' => 19800,
            'status' => 'draft',
        ], $overrides), $this->admin());

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        return Product::query()->findOrFail($result->data['item']['id']);
    }

    private function media(string $name = '画像'): Media
    {
        $result = app(UploadMedia::class)->execute(
            UploadedFile::fake()->image('photo.png', 100, 100),
            $this->admin(),
            $name,
        );

        return Media::query()->findOrFail($result->data['media']['id']);
    }

    /**
     * `file` 型が指すのは **kind=file の Media** である（Phase 9.5-D / SPEC §6）。
     * 画像の ID を入れても通らない。
     */
    private function generalFile(string $name = '資料'): Media
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agentcms-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, "%PDF-1.7\n%%EOF\n");

        $result = app(UploadMedia::class)->execute(
            new UploadedFile($path, $name.'.pdf', null, null, true),
            $this->admin(),
            $name,
        );

        return Media::query()->findOrFail($result->data['media']['id']);
    }

    // 1. 全 target field type が schema として妥当
    public function test_all_target_field_types_load_and_validate(): void
    {
        $schema = app(SchemaRegistry::class)->get('product');
        $types = array_map(fn ($f) => $f->type, $schema->fields);

        foreach (['text', 'textarea', 'richtext', 'number', 'date', 'datetime', 'boolean',
            'select', 'radio', 'checkbox', 'image', 'images', 'file', 'url', 'email', 'tel',
            'relation', 'relations', 'group', 'repeater'] as $type) {
            $this->assertContains($type, $types, "field type [{$type}] が Product schema にありません");
        }

        // すべてのフィールドが immutable ID を持つ（owner 決定 G6）
        foreach ($schema->fields as $field) {
            $this->assertNotNull($field->id, "field [{$field->key}] に id がありません");
        }
    }

    // 2. primitive はカラムへ保存される
    public function test_primitive_fields_are_stored_in_columns(): void
    {
        $product = $this->createProduct(['product_type' => 'physical', 'featured' => true, 'tags' => ['new']]);
        $row = DB::table('products')->where('id', $product->id)->first();

        $this->assertSame('テスト商品', $row->title);
        $this->assertEquals(19800, (float) $row->price);
        $this->assertSame('physical', $row->product_type);
        $this->assertEquals(1, $row->featured);
    }

    // 3. 複合フィールドは data JSON へ保存される
    public function test_complex_fields_are_stored_in_the_data_json(): void
    {
        $product = $this->createProduct([
            'specs' => ['weight' => 1200, 'dimensions' => 'W300'],
            'features' => [['heading' => '軽い']],
        ]);

        $data = json_decode((string) DB::table('products')->where('id', $product->id)->value('data'), true);

        $this->assertSame(1200, (int) $data['specs']['weight']);
        $this->assertSame('軽い', $data['features'][0]['heading']);

        // 二重保存していない
        $columns = array_keys((array) DB::table('products')->where('id', $product->id)->first());
        $this->assertNotContains('specs', $columns);
        $this->assertNotContains('features', $columns);
    }

    // 4. 既存 News / Works は無変更
    public function test_existing_news_and_works_are_unchanged(): void
    {
        $news = app(SchemaRegistry::class)->get('news');
        $works = app(SchemaRegistry::class)->get('works');

        // どちらも複合フィールドを持たない = schema としては data を要求しない
        $this->assertFalse($news->needsDataColumn());
        $this->assertFalse($works->needsDataColumn());

        /*
         * `data` カラムは Phase 9.5-B で **受け皿として**足した（owner 決定 G2-(a)）。
         * schema が要求しているのではなく、Field Group が overlay で足す項目の
         * 置き場所である。既存行は NULL のままで、既存フィールドは 1 つも動いていない。
         */
        foreach (['news', 'works'] as $table) {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);

            $this->assertContains('data', $columns);
            // 既存の列は 1 つも消えていない
            $this->assertSame([], array_diff(
                ['id', 'title', 'slug', 'body', 'status', 'published_at', 'created_at', 'updated_at'],
                $columns,
            ));
        }

        // 既存行の data は NULL のまま（埋め戻しをしていない）
        $this->assertNull(DB::table('news')->value('data'));

        // works.category は select のまま（taxonomy 化しない — G3 / Phase 9.3）
        $this->assertSame('select', $works->field('category')->type);

        $this->get('/news')->assertOk();
        $this->get('/works')->assertOk();
    }

    // 5. group の作成・読み取り・更新
    public function test_group_fields_round_trip(): void
    {
        $product = $this->createProduct(['specs' => ['weight' => 1000, 'dimensions' => 'W100']]);

        $read = app(GetContent::class)->execute('product', (int) $product->id, $this->admin());
        $this->assertSame(1000, (int) $read->data['item']['specs']['weight']);

        // 部分更新で片方だけ変えても、もう片方は残る
        app(UpdateContent::class)->execute('product', (int) $product->id,
            ['specs' => ['weight' => 2000]], $this->admin(), partial: true);

        $after = app(GetContent::class)->execute('product', (int) $product->id, $this->admin());
        $this->assertSame(2000, (int) $after->data['item']['specs']['weight']);
        $this->assertSame('W100', $after->data['item']['specs']['dimensions']);
    }

    // 6 + 7. repeater の作成と安定 ULID
    public function test_repeater_items_get_stable_ulids(): void
    {
        $product = $this->createProduct([
            'features' => [['heading' => 'A'], ['heading' => 'B']],
        ]);

        $items = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())
            ->data['item']['features'];

        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $item['_id']);
        }

        $this->assertNotSame($items[0]['_id'], $items[1]['_id']);
    }

    // 8. 特定 item だけ更新
    public function test_a_single_repeater_item_can_be_updated(): void
    {
        $product = $this->createProduct(['features' => [['heading' => 'A'], ['heading' => 'B']]]);
        $items = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())->data['item']['features'];

        $items[1]['heading'] = 'B を書き換え';

        app(UpdateContent::class)->execute('product', (int) $product->id,
            ['features' => $items], $this->admin(), partial: true);

        $after = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())->data['item']['features'];

        $this->assertSame('A', $after[0]['heading']);
        $this->assertSame('B を書き換え', $after[1]['heading']);
        $this->assertSame($items[0]['_id'], $after[0]['_id']);
        $this->assertSame($items[1]['_id'], $after[1]['_id']);
    }

    // 9. 並べ替えても _id は変わらない
    public function test_reordering_a_repeater_keeps_item_ids(): void
    {
        $product = $this->createProduct(['features' => [['heading' => 'A'], ['heading' => 'B']]]);
        $items = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())->data['item']['features'];

        app(UpdateContent::class)->execute('product', (int) $product->id,
            ['features' => [$items[1], $items[0]]], $this->admin(), partial: true);

        $after = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())->data['item']['features'];

        $this->assertSame('B', $after[0]['heading']);
        $this->assertSame($items[1]['_id'], $after[0]['_id']);
        $this->assertSame($items[0]['_id'], $after[1]['_id']);
    }

    // 10 + 11. relation の妥当 / 不正な参照
    public function test_a_single_relation_accepts_a_valid_target_and_rejects_an_invalid_one(): void
    {
        $work = Work::query()->firstOrFail();

        $ok = app(CreateContent::class)->execute('product', [
            'title' => '関連あり', 'status' => 'draft', 'related_work' => $work->id,
        ], $this->admin());
        $this->assertSame(OperationStatus::Success, $ok->status);

        $ng = app(CreateContent::class)->execute('product', [
            'title' => '関連なし', 'status' => 'draft', 'related_work' => 999999,
        ], $this->admin());

        $this->assertSame(OperationStatus::ValidationError, $ng->status);
        $this->assertArrayHasKey('related_work', $ng->errors);
    }

    // 12 + 13. relations の順序と重複拒否
    public function test_multiple_relations_keep_order_and_reject_duplicates(): void
    {
        $works = Work::query()->limit(2)->pluck('id')->all();

        $product = $this->createProduct(['related_items' => [$works[1], $works[0]]]);
        $read = app(GetContent::class)->execute('product', (int) $product->id, $this->admin());

        $this->assertSame([$works[1], $works[0]], $read->data['item']['related_items']);

        $duplicate = app(CreateContent::class)->execute('product', [
            'title' => '重複', 'status' => 'draft', 'related_items' => [$works[0], $works[0]],
        ], $this->admin());

        $this->assertSame(OperationStatus::ValidationError, $duplicate->status);
    }

    // 14. 参照されているコンテンツは削除できない
    /**
     * 参照されていても **ゴミ箱へは移せる**（Phase 9.8 / STAGE 21C・OG4 = (c)）。
     *
     * 9.8 より前は参照中なら拒否していた（RESTRICT）。理由は「データを失わせない」
     * ことだったが、ゴミ箱は**失わない**ので、その理由は当てはまらない。
     * 拒否したままだと「捨てる前に参照を全部外す」が毎回必要になり、運用が重い。
     *
     * 参照を守る RESTRICT は **完全削除（purge）の側**へ移った。それは backup が
     * できるまで作らない（OG8 = (b)）ので、いまはどこにも物理削除の口が無い。
     */
    public function test_a_referenced_entry_can_still_be_trashed(): void
    {
        $work = Work::query()->firstOrFail();
        $this->createProduct(['related_work' => $work->id]);

        $result = $this->confirmTrash('works', (int) $work->id);

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertSame('trashed', $result->data['state']);

        // 参照は壊れていない。行も参照元も残っている
        $this->assertDatabaseHas('works', ['id' => $work->id]);
        $this->assertNull(Work::query()->find($work->id));
    }

    public function test_a_referenced_entry_through_relations_can_still_be_trashed(): void
    {
        $work = Work::query()->firstOrFail();
        $this->createProduct(['related_items' => [$work->id]]);

        $result = $this->confirmTrash('works', (int) $work->id);

        $this->assertSame(OperationStatus::Success, $result->status);
    }

    /** 確認を取ってからゴミ箱へ移す（2 回呼ぶのが正規の経路） */
    private function confirmTrash(string $type, int $id): Result
    {
        $token = app(DeleteContent::class)->execute($type, $id, $this->admin())->confirmation?->token;

        return app(DeleteContent::class)->execute(
            $type, $id, $this->admin()->withConfirmationToken((string) $token),
        );
    }

    // 15 + 16. image / images
    public function test_media_references_are_stored_as_ids(): void
    {
        $one = $this->media('single');
        $two = $this->media('second');

        $manual = $this->generalFile('取扱説明書');
        $product = $this->createProduct(['manual' => $manual->id, 'gallery' => [$two->id, $one->id]]);
        $read = app(GetContent::class)->execute('product', (int) $product->id, $this->admin())->data['item'];

        $this->assertSame($manual->id, (int) $read['manual']);
        $this->assertSame([$two->id, $one->id], $read['gallery']);
    }

    // 17 + 18. 入れ子・repeater の中の media も使用中として検出され、削除できない（F-S3）
    public function test_media_inside_json_fields_is_detected_and_protected(): void
    {
        $gallery = $this->media('ギャラリー画像');
        $icon = $this->media('アイコン');
        $inspector = app(MediaUsageInspector::class);

        $this->assertSame([], $inspector->usages($gallery->id));

        $this->createProduct([
            'gallery' => [$gallery->id],
            'features' => [['heading' => '特長', 'icon_id' => $icon->id]],
        ]);

        $this->assertNotSame([], $inspector->usages($gallery->id), 'images の中の media が検出されていません');
        $this->assertNotSame([], $inspector->usages($icon->id), 'repeater の中の media が検出されていません');

        $used = $inspector->usedMap([$gallery->id, $icon->id]);
        $this->assertTrue($used[$gallery->id]);
        $this->assertTrue($used[$icon->id]);

        // 使用中なので削除できない
        foreach ([$gallery, $icon] as $media) {
            $result = app(DeleteMedia::class)->execute($media->id, $this->admin());
            $this->assertSame(OperationStatus::Conflict, $result->status);
            $this->assertDatabaseHas('media', ['id' => $media->id]);
        }
    }

    // 19. richtext は書き込み境界でサニタイズされる
    public function test_richtext_is_sanitized_at_the_write_boundary(): void
    {
        $product = $this->createProduct([
            'description' => '<p>安全</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
        ]);

        $stored = (string) DB::table('products')->where('id', $product->id)->value('description');

        $this->assertStringContainsString('安全', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    // 20. Admin / API / MCP が同じ検証
    public function test_admin_api_and_mcp_share_the_same_validation(): void
    {
        $invalid = ['title' => '不正', 'status' => 'draft', 'price' => -1, 'product_type' => 'unknown'];

        $fromAdmin = app(CreateContent::class)->execute('product', $invalid, $this->admin());
        $fromAgent = app(OperationCatalog::class)->invoke('content.create',
            ['type' => 'product', 'data' => $invalid], $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $fromAdmin->status);
        $this->assertSame(OperationStatus::ValidationError, $fromAgent->status);
        $this->assertSame(array_keys($fromAdmin->errors), array_keys($fromAgent->errors));

        $product = $this->createProduct(['title' => 'API 検証用']);

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/content/product/{$product->id}", ['data' => ['price' => -1]])
            ->assertStatus(422);
    }

    // 21. content_schema_get が新しい metadata を返す
    public function test_the_schema_surface_exposes_the_new_field_metadata(): void
    {
        $result = app(OperationCatalog::class)->invoke('content.schema.get',
            ['type' => 'product'], $this->agent());

        $fields = collect($result->data['schema']['fields'])->keyBy('key');

        $this->assertNotNull($fields['price']['id']);
        $this->assertSame('number', $fields['price']['type']);
        $this->assertSame(['min' => 0, 'max' => 10000000], $fields['price']['validation']);
        $this->assertSame('works', $fields['related_work']['target']);
        $this->assertSame('json', $fields['features']['storage']);
        $this->assertSame(['heading', 'detail', 'icon_id'],
            array_column($fields['features']['fields'], 'key'));
        $this->assertSame('seo', $fields['seo_title']['fieldset']);
    }

    // 22. PageDocument binding 互換
    public function test_page_document_binding_can_reference_new_field_types(): void
    {
        $schema = app(SchemaRegistry::class)->get('product');

        // field ID / key / ドット記法のいずれでも解決できる
        $this->assertNotNull($schema->fieldById('01KZTC0000FIELDPRICE00004'));
        $this->assertNotNull($schema->fieldByPath('price'));
        $this->assertNotNull($schema->fieldByPath('specs.weight'));
        $this->assertNotNull($schema->fieldByPath('features.heading'));
        $this->assertNull($schema->fieldByPath('specs.nope'));
    }

    // 23 + 24. schema 変更の分類
    public function test_schema_changes_are_classified(): void
    {
        $classifier = app(SchemaMutationClassifier::class);
        $before = app(SchemaRegistry::class)->get('product');

        $rename = $this->schemaWith($before, '01KZTC0000FIELDPRICE00004', ['key' => 'amount']);
        $this->assertSame('migration_required', $classifier->compare($before, $rename)['level']->value);

        $deleted = $this->schemaWithout($before, '01KZTC0000FIELDPRICE00004');
        $this->assertSame('destructive', $classifier->compare($before, $deleted)['level']->value);

        $relabel = $this->schemaWith($before, '01KZTC0000FIELDPRICE00004', ['label' => '税込価格']);
        $this->assertSame('safe', $classifier->compare($before, $relabel)['level']->value);
    }

    // 25. News / Works を hardcode していない
    public function test_no_hardcoded_content_type_behaviour(): void
    {
        foreach ([
            'core/src/Application/Content/ContentValues.php',
            'core/src/Application/Content/ContentReferences.php',
            'core/src/Schema/SchemaRules.php',
            'core/src/Schema/Field.php',
            'core/src/Media/MediaUsageInspector.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString('Modules\\', $source, "{$file} が module へ依存しています");
            $this->assertStringNotContainsString("'news'", $source, "{$file} に news が hardcode されています");
            $this->assertStringNotContainsString("'works'", $source, "{$file} に works が hardcode されています");
        }
    }

    /** @param array<string, mixed> $changes */
    private function schemaWith(ContentSchema $schema, string $fieldId, array $changes): ContentSchema
    {
        $raw = $this->rawSchema();
        $renamed = null;

        $raw['fields'] = array_map(
            function (array $f) use ($fieldId, $changes, &$renamed): array {
                if (($f['id'] ?? null) !== $fieldId) {
                    return $f;
                }

                $updated = array_merge($f, $changes);

                if (($updated['key'] ?? null) !== $f['key']) {
                    $renamed = [$f['key'], $updated['key']];
                }

                return $updated;
            },
            $raw['fields'],
        );

        if ($renamed !== null) {
            [$from, $to] = $renamed;
            $raw['admin']['list'] = array_map(fn (string $k): string => $k === $from ? $to : $k, $raw['admin']['list']);
            $raw['admin']['search'] = array_map(fn (string $k): string => $k === $from ? $to : $k, $raw['admin']['search']);
        }

        return ContentSchema::fromArray($raw);
    }

    private function schemaWithout(ContentSchema $schema, string $fieldId): ContentSchema
    {
        $raw = $this->rawSchema();
        $raw['fields'] = array_values(array_filter(
            $raw['fields'],
            fn (array $f): bool => ($f['id'] ?? null) !== $fieldId,
        ));
        $raw['admin']['list'] = array_values(array_diff($raw['admin']['list'], ['price']));

        return ContentSchema::fromArray($raw);
    }

    /** @return array<string, mixed> */
    private function rawSchema(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents(base_path('content/schemas/product.json')), true);

        return $raw;
    }
}
