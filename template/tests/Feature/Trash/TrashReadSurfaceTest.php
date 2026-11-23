<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Media\MediaUsageInspector;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadinessResolver;
use App\Models\User;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Media\Models\Media;
use RuntimeException;
use Tests\TestCase;

/**
 * ゴミ箱の中身が **どの面からも漏れない**（Phase 9.8 / STAGE 21B・SPEC §E）。
 *
 * 面ごとに除外を書かないと決めた以上、確かめるのは面ごとである。
 * 1 か所で効いていることを、実際に開いて見る。
 *
 * この Stage に「ゴミ箱へ入れる」操作はまだ無いので、状態は列へ直接書いて作る。
 */
final class TrashReadSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_管理一覧と検索から消える(): void
    {
        $this->entry('active-one', 'のこる投稿');
        $this->putInTrash($this->entry('trashed-one', 'すてた投稿'));

        $result = app(ListContent::class)->execute('post', $this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['のこる投稿'], array_column($result->data['items'], 'title'));
        $this->assertSame(1, $result->meta['total'], '件数がゴミ箱を数えている');

        // 検索でも出てこない
        $searched = app(ListContent::class)->execute('post', $this->context(), 'すてた');
        $this->assertSame([], $searched->data['items']);
    }

    public function test_管理の詳細取得は見つからないになる(): void
    {
        $id = $this->entry('gone', 'すてた投稿');
        $this->putInTrash($id);

        $result = app(GetContent::class)->execute('post', $id, $this->context());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('not_found', $result->status->value);
    }

    public function test_管理画面の一覧と編集から消える(): void
    {
        $id = $this->entry('gone', 'すてた投稿');
        $this->entry('kept', 'のこる投稿');
        $this->putInTrash($id);

        // 一覧は Inertia。HTML ではなく渡された props を見る
        $this->actingAs($this->owner)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/resource/Index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'のこる投稿'),
            );

        /*
         * 編集 URL は **404 にしない**（Phase 9.8 / STAGE 21E）。
         *
         * 開けるのは「ゴミ箱にあります」という read-only の画面で、
         * 入力欄も保存も公開も出さない。404 にすると「消えた」に見えるが、
         * 実際には戻せる場所に在るので、そう伝えるほうが正確である。
         */
        $this->actingAs($this->owner)->get("/admin/post/{$id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/resource/Trashed'));
    }

    public function test_api_v1_の一覧と詳細から消える(): void
    {
        $id = $this->entry('gone', 'すてた投稿');
        $this->entry('kept', 'のこる投稿');
        $this->putInTrash($id);

        $index = $this->actingAs($this->owner)->getJson('/api/v1/content/post');
        $index->assertOk();
        $this->assertSame(['のこる投稿'], array_column((array) $index->json('data'), 'title'));

        // 存在そのものを漏らさない（403 ではなく 404）
        $this->actingAs($this->owner)->getJson("/api/v1/content/post/{$id}")->assertNotFound();
    }

    public function test_公開サイトの一覧と詳細から消える(): void
    {
        $this->publish();
        $kept = $this->entry('kept', 'のこる投稿');
        $trashed = $this->entry('gone', 'すてた投稿');
        $this->putInTrash($trashed);

        $this->get('/blog')->assertOk()->assertSee('のこる投稿')->assertDontSee('すてた投稿');
        $this->get('/blog/kept')->assertOk();
        $this->get('/blog/gone')->assertNotFound();

        $this->assertNotNull($kept);
    }

    public function test_sitemapから消える(): void
    {
        $this->publish();
        $this->entry('kept', 'のこる投稿');
        $this->putInTrash($this->entry('gone', 'すてた投稿'));

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/blog/kept')
            ->assertDontSee('/blog/gone');
    }

    public function test_関連の候補から消えるが選択済みの名前は引ける(): void
    {
        $kept = $this->entry('kept', 'のこる投稿');
        $trashed = $this->entry('gone', 'すてた投稿');
        $this->putInTrash($trashed);

        // 候補には出さない（新しく選ばせない）
        $candidates = $this->actingAs($this->owner)->getJson('/admin/relations/post/options');
        $candidates->assertOk();
        $this->assertSame([$kept], array_column($candidates->json('options'), 'id'));

        /*
         * 既に選ばれている相手は名前を引ける。引けないと画面には「ID 42」としか
         * 出ず、何を選んだのか読めないまま保存させることになる。
         */
        $selected = $this->actingAs($this->owner)->getJson("/admin/relations/post/options?ids={$trashed}");
        $selected->assertOk();
        $this->assertSame([$trashed], array_column($selected->json('options'), 'id'));
        $this->assertSame('すてた投稿', $selected->json('options.0.title'));
        $this->assertTrue($selected->json('options.0.trashed'), 'ゴミ箱にある印が付いていない');
    }

    public function test_ゴミ箱の投稿が使っているメディアは使用中のままにする(): void
    {
        $media = Media::query()->create([
            'name' => 'サンプル画像',
            'path' => 'media/sample.png',
            'mime' => 'image/png',
            'size' => 100,
            'width' => 40,
            'height' => 30,
        ]);

        /*
         * `post` にはメディアの項目が無いので、**メディアを持つ型**で確かめる。
         * `works` は kit の例だが、ここで見たいのは型ではなく
         * 「ゴミ箱に入っていても使用中と数えるか」である。
         */
        $workId = DB::table('works')->insertGetId([
            'title' => 'メディア付きの実績',
            'slug' => 'with-media',
            'main_image_id' => (int) $media->getKey(),
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('works')->where('id', $workId)->update([
            TrashColumns::DELETED_AT => now(),
        ]);

        /*
         * **ゴミ箱はデータを失わない。** 使っている投稿がゴミ箱にあるだけで
         * 「未使用」と数えると、そのメディアを消してよいと見えてしまう。
         * 復元したときに画像だけ無い、が起きる。
         */
        $mediaId = (int) $media->getKey();
        $inspector = app(MediaUsageInspector::class);

        $this->assertNotSame([], $inspector->usages($mediaId), 'ゴミ箱の投稿が使っているメディアが未使用に見えている');
        $this->assertTrue($inspector->usedMap([$mediaId])[$mediaId] ?? false, '削除してよいメディアに見えている');
    }

    public function test_分類の割り当ては消さない(): void
    {
        $id = $this->entry('tagged', '分類つき');
        $this->putInTrash($id);

        // trash は保管状態を変えるだけ。関連するものを削って回らない
        $this->assertSame(
            1,
            DB::table('post')->where('id', $id)->whereNotNull(TrashColumns::DELETED_AT)->count(),
        );
    }

    public function test_判定不能なら空の200ではなく503を返す(): void
    {
        $this->publish();
        $this->entry('kept', 'のこる投稿');
        $this->breakSchemaInspection();

        $response = $this->get('/blog');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
        // 原因の詳細を外へ出さない
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent() ?: '');
    }

    public function test_判定不能でもapiは空の成功を返さない(): void
    {
        $this->entry('kept', 'のこる投稿');
        $this->breakSchemaInspection();

        $response = $this->actingAs($this->owner)->getJson('/api/v1/content/post');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
    }

    public function test_判定不能でも投稿タイプの診断面は開く(): void
    {
        $this->entry('kept', 'のこる投稿');
        $this->breakSchemaInspection();

        $response = $this->actingAs($this->owner)->get('/admin/content-types');

        $response->assertOk();
        // 件数は諦める（対象のモデルを読まない）が、画面は落とさない
        $response->assertSee('post');
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->owner->getKey(), (string) $this->owner->name);
    }

    private function publish(string $slug = 'blog'): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'registration' => ['public' => true, 'publicly_queryable' => true, 'has_archive' => true],
            'public_slug' => $slug,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        app()->forgetScopedInstances();
    }

    private function entry(string $slug, string $title): int
    {
        return DB::table('post')->insertGetId([
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>本文です</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function putInTrash(int $id): void
    {
        DB::table('post')->where('id', $id)->update([
            TrashColumns::DELETED_AT => now(),
            TrashColumns::TRASHED_BY => (int) $this->owner->getKey(),
        ]);
    }

    private function breakSchemaInspection(): void
    {
        $schema = new class(app('db')->connection()) extends SchemaBuilder
        {
            public function hasColumn($table, $column)
            {
                throw new RuntimeException('接続できません');
            }
        };

        $this->app->instance(TrashReadinessResolver::class, new TrashReadinessResolver(
            app(SchemaRegistry::class),
            $schema,
        ));

        $this->assertNotNull(app(ContentTypeResolver::class));
    }
}
