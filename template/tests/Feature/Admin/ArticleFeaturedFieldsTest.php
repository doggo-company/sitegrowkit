<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\FeaturedImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Media\Models\Media;
use Modules\News\Models\News;
use Modules\Post\Models\Post;
use Tests\TestCase;

/** 写真・抜粋を付けた記事が保存、公開、使用中保護まで同じ値を使う。 */
final class ArticleFeaturedFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_投稿とお知らせに画像と抜粋を保存して再編集できる(): void
    {
        $owner = User::factory()->create();
        $media = $this->image();
        foreach (['post', 'news'] as $type) {
            $this->actingAs($owner)->post('/admin/'.$type, [
                'title' => '写真付きの紹介', 'slug' => 'featured', 'body' => '<p>本文です</p>',
                'status' => 'draft', 'main_image_id' => $media->getKey(), 'excerpt' => '短い紹介文',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $id = DB::table($type)->where('slug', 'featured')->value('id');
            $this->assertDatabaseHas($type, ['id' => $id, 'main_image_id' => $media->getKey(), 'excerpt' => '短い紹介文']);
            $this->get("/admin/{$type}/{$id}/edit")->assertOk();
        }
    }

    public function test_公開一覧と詳細に画像と抜粋が出て悪意ある抜粋は実行しない(): void
    {
        $media = $this->image();
        News::query()->create([
            'title' => '写真付き記事', 'slug' => 'featured', 'body' => '<p>本文です</p>',
            'status' => 'published', 'published_at' => now()->subMinute(),
            'main_image_id' => $media->getKey(), 'excerpt' => '<script>unsafe()</script>紹介',
        ]);
        foreach (['/news', '/news/featured'] as $url) {
            $this->get($url)->assertOk()->assertSee('media/featured.jpg', false)
                ->assertDontSee('<script>unsafe()</script>', false);
        }
        $this->get('/news')->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;紹介', false);
        $this->get('/news/featured')->assertSee('property="og:image"', false);
    }

    public function test_一覧の画像はまとめて取得し非公開画像を混ぜない(): void
    {
        $media = $this->image();
        $private = $this->image('private');
        $posts = collect([$media, $media, $private])->map(fn (Media $image, int $index): Post => Post::query()->create([
            'title' => '画像 '.$index, 'slug' => 'image-'.$index, 'status' => 'draft', 'main_image_id' => $image->getKey(),
        ]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $images = app(FeaturedImages::class)->forEntries($posts);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $images);
        $this->assertCount(2, $queries, '画像と派生物をそれぞれ一括取得する');
        $this->assertArrayNotHasKey($posts[2]->getKey(), $images);
    }

    public function test_存在しない画像と長すぎる抜粋は保存しない(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/post', [
            'title' => '不正な参照', 'slug' => 'invalid', 'status' => 'draft',
            'main_image_id' => 99999, 'excerpt' => str_repeat('あ', 301),
        ])->assertSessionHasErrors(['main_image_id', 'excerpt']);
        $this->assertDatabaseMissing('post', ['slug' => 'invalid']);
    }

    private function image(string $visibility = 'public'): Media
    {
        return Media::query()->forceCreate([
            'name' => '写真', 'path' => 'media/featured.jpg', 'mime' => 'image/jpeg', 'size' => 10,
            'kind' => 'image', 'visibility' => $visibility, 'width' => 1200, 'height' => 800, 'alt' => '紹介の写真',
        ]);
    }
}
