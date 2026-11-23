<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\Media\MediaUsageInspector;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Models\Media;
use Modules\News\Models\News;
use Modules\Products\Models\Product;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * `file` field の契約（Phase 9.5-D / SPEC §6）。
 *
 * `file` が指すのは **kind=file の Media** で、`media` / `images` が指すのは
 * **kind=image の Media** である。取り違えは server で拒否する — 画面で隠すだけにしない。
 */
final class FileFieldTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaries as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    private function owner(): User
    {
        return User::factory()->create([
            // カスタムフィールドの画面は作りの画面（制作者だけ・2026-09-28）
            'role' => Role::Developer->value,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Field Group を 1 つ作る。
     *
     * 作ったあとに **scoped instance を捨てる**。SchemaRegistry は scoped なので、
     * 捨てないと overlay を読み直さず、足したばかりの項目が見えない。
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function defineGroup(User $owner, array $fields): void
    {
        $this->actingAs($owner)->post('/admin/field-groups', [
            'key' => 'attachments',
            'label' => '添付',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => $fields,
        ])->assertRedirect('/admin/field-groups');

        app()->forgetScopedInstances();
    }

    /** @param array<string, mixed> $overrides */
    private function image(array $overrides = []): Media
    {
        return Media::query()->create(array_merge([
            'name' => '画像',
            'path' => 'media/'.fake()->uuid().'.png',
            'mime' => 'image/png',
            'size' => 10,
            'width' => 40,
            'height' => 30,
        ], $overrides));
    }

    private function file(string $name = '資料'): Media
    {
        return Media::query()->create([
            'kind' => 'file',
            'disk' => 'local',
            'name' => $name,
            'original_name' => $name.'.pdf',
            'path' => 'files/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
            'width' => null,
            'height' => null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createProduct(array $overrides = []): Result
    {
        return app(CreateContent::class)->execute('product', array_merge([
            'title' => 'テスト商品',
            'description' => '<p>説明</p>',
            'price' => 1000,
            'status' => 'draft',
        ], $overrides), $this->admin());
    }

    // --- 種別の食い違い --------------------------------------------------

    public function test_a_file_field_refuses_an_image(): void
    {
        $result = $this->createProduct(['manual' => $this->image()->id]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('manual', $result->errors);
    }

    public function test_a_media_field_refuses_a_general_file(): void
    {
        // works.main_image_id は media 型。ファイルを指させない
        $result = app(CreateContent::class)->execute('works', [
            'title' => '実績',
            'slug' => 'jisseki',
            'body' => '<p>本文</p>',
            'category' => 'web',
            'status' => 'draft',
            'main_image_id' => $this->file()->id,
        ], $this->admin());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('main_image_id', $result->errors);
    }

    public function test_an_images_field_refuses_a_general_file(): void
    {
        $result = $this->createProduct(['gallery' => [$this->file()->id]]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('gallery.0', $result->errors);
    }

    public function test_a_file_field_accepts_a_general_file(): void
    {
        $file = $this->file();
        $result = $this->createProduct(['manual' => $file->id]);

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);
        $this->assertSame($file->id, (int) Product::sole()->manual);
    }

    public function test_a_missing_id_is_still_refused(): void
    {
        $result = $this->createProduct(['manual' => 999999]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    // --- 入れ子 ----------------------------------------------------------

    public function test_file_is_available_as_a_field_group_type(): void
    {
        $this->assertContains('file', FieldGroupDraft::ALLOWED_TYPES);
    }

    /**
     * `file` は multipart ではなく **Media の ID を選ぶ**ので、入れ子でも保存され、
     * もう一度開いたときにその値が戻ってくる。
     */
    public function test_a_nested_file_round_trips_through_the_admin_form(): void
    {
        $file = $this->file('入れ子の資料');
        $owner = $this->owner();

        $this->defineGroup($owner, [
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'spec_file', 'type' => 'file', 'label' => '資料'],
            ]],
        ]);

        $this->actingAs($owner)->post('/admin/news', [
            'title' => 'お知らせ',
            'slug' => 'oshirase',
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'spec_group' => ['spec_file' => $file->id],
        ])->assertSessionHasNoErrors();

        $news = News::sole();
        $this->assertSame($file->id, (int) $news->data['spec_group']['spec_file']);

        // 編集画面へ戻したときにも値が出る（保存できても読み出せなければ意味が無い）
        $this->actingAs($owner)
            ->get("/admin/news/{$news->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('item.spec_group.spec_file', $file->id));
    }

    public function test_a_nested_file_field_still_refuses_an_image(): void
    {
        $owner = $this->owner();

        $this->defineGroup($owner, [
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'spec_file', 'type' => 'file', 'label' => '資料'],
            ]],
        ]);

        $this->actingAs($owner)->post('/admin/news', [
            'title' => 'お知らせ',
            'slug' => 'oshirase',
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'spec_group' => ['spec_file' => $this->image()->id],
        ])->assertSessionHasErrors('spec_group.spec_file');
    }

    // --- 使用中の保護 ----------------------------------------------------

    public function test_a_file_in_use_is_detected_and_cannot_be_deleted(): void
    {
        $file = $this->file();
        $inspector = app(MediaUsageInspector::class);

        $this->assertSame([], $inspector->usages($file->id));

        $this->createProduct(['manual' => $file->id]);

        $this->assertNotSame([], $inspector->usages($file->id), '使用中のファイルが検出されていない');
        $this->assertTrue($inspector->usedMap([$file->id])[$file->id]);
    }

    /**
     * **入れ子の中**で使われているファイルも守る。
     *
     * `data` JSON の中を辿らないと、group / repeater に入れたとたん
     * 「使っているのに消せてしまう」事故になる。
     */
    public function test_a_file_nested_in_json_is_protected_too(): void
    {
        $file = $this->file('入れ子の資料');
        $owner = $this->owner();

        $this->defineGroup($owner, [
            ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'fields' => [
                ['key' => 'row_file', 'type' => 'file', 'label' => '資料'],
            ]],
        ]);

        $this->actingAs($owner)->post('/admin/news', [
            'title' => 'お知らせ',
            'slug' => 'oshirase',
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'spec_rows' => [['row_file' => $file->id]],
        ])->assertSessionHasNoErrors();

        $inspector = app(MediaUsageInspector::class);

        $this->assertNotSame([], $inspector->usages($file->id), 'repeater の中のファイルが検出されていない');
        $this->assertTrue($inspector->usedMap([$file->id])[$file->id]);

        // 削除は使用中として拒否される
        $this->actingAs($owner)
            ->delete("/admin/media/{$file->id}")
            ->assertSessionHasErrors('media');
    }

    public function test_the_conflict_message_names_the_file_not_an_image(): void
    {
        $file = $this->file();
        $this->createProduct(['manual' => $file->id]);

        $result = app(DeleteMedia::class)
            ->execute($file->id, $this->admin());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('このファイルは使用中', (string) $result->message);
    }

    public function test_removing_the_reference_frees_the_file(): void
    {
        $file = $this->file();
        $result = $this->createProduct(['manual' => $file->id]);

        $updated = app(UpdateContent::class)->execute(
            'product',
            (int) $result->data['item']['id'],
            ['manual' => null],
            $this->admin(),
            partial: true,
        );

        $this->assertSame(OperationStatus::Success, $updated->status, (string) $updated->message);
        $this->assertSame([], app(MediaUsageInspector::class)->usages($file->id));
    }

    // --- 定義の面 --------------------------------------------------------

    public function test_the_product_schema_still_declares_file(): void
    {
        $schema = app(SchemaRegistry::class)->get('product');

        $this->assertSame('file', $schema->field('manual')->type);
    }
}
