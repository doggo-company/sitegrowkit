<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Support\SocialImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Media\Models\Media;
use Tests\TestCase;

/**
 * 公開ページの SEO 標準セット（P1 ①）。
 *
 * ## 型ごとに書かない
 *
 * canonical と og は**共有 layout が 1 か所で出す**。ページごとに書くと、
 * **新しいページを足した人が忘れる**し、忘れたことに誰も気づかない。
 *
 * `layouts/public.blade.php`（News / Works の詳細など）は最初から持っていた。
 * **足りなかったのは同梱テンプレートの `layouts/imported.blade.php`** で、
 * `home.blade.php` だけが `@push('head')` で og を手書きしていた。
 */
final class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_記事の構造化データは実データを使いscriptを脱出させない(): void
    {
        $title = '記事 </script><script>alert(9)</script> "犬"';
        DB::table('news')->insert(['title' => $title, 'slug' => 'structured-test', 'body' => '<p>本文</p>', 'excerpt' => '記事の説明',
            'status' => 'published', 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $html = (string) $this->get('/news/structured-test')->assertOk()->getContent();
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
        $articles = [];
        foreach ($matches[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($data['@type'] ?? null) === 'Article') {
                $articles[] = $data;
            }
        }
        $this->assertCount(1, $articles);
        $this->assertSame(strip_tags($title), $articles[0]['headline']);
        $this->assertSame(url('/news/structured-test'), $articles[0]['mainEntityOfPage']);
        $this->assertSame('記事の説明', $articles[0]['description']);
        $this->assertArrayHasKey('datePublished', $articles[0]);
        $this->assertArrayNotHasKey('author', $articles[0]);
        $this->assertStringNotContainsString('<script>alert(9)</script>', $html);
    }

    public function test_公開トップには記事の構造化データを捏造しない(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('"@type":"Article"', $html);
    }

    public function test_social_image_is_shared_by_public_layouts_and_escapes_alt(): void
    {
        $media = Media::query()->forceCreate([
            'name' => '共有画像',
            'path' => 'media/social-preview.jpg', 'mime' => 'image/jpeg', 'size' => 10,
            'width' => 1200, 'height' => 630, 'alt' => '画像 "<script>',
        ]);
        app(SettingsStore::class)->put(['social_image_id' => $media->getKey()]);

        DB::table('news')->insert([
            'title' => '共有の確認', 'slug' => 'social-test', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['/', '/news/social-test'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'property="og:image"'));
            $this->assertStringContainsString('summary_large_image', $html);
            $this->assertStringContainsString('画像 &quot;&lt;script&gt;', $html);
            if ($path === '/news/social-test') {
                preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $scripts);
                $articles = array_filter(array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $scripts[1]), static fn (array $data): bool => ($data['@type'] ?? null) === 'Article');
                $this->assertSame(url($media->url()), array_values($articles)[0]['image']);
            }
        }
    }

    public function test_social_image_never_exposes_private_media(): void
    {
        $media = Media::query()->forceCreate([
            'name' => '非公開画像',
            'path' => 'private/secret.jpg', 'mime' => 'image/jpeg', 'size' => 10,
            'visibility' => 'private', 'width' => 100, 'height' => 100,
        ]);
        $this->assertNull(app(SocialImage::class)->resolve(['social_image_id' => $media->getKey()]));
        app(SettingsStore::class)->put(['social_image_id' => $media->getKey()]);
        $this->get('/')->assertOk()->assertDontSee('property="og:image"', false)->assertDontSee('secret.jpg');
    }

    public function test_同梱テンプレートのページにcanonicalとogが出る(): void
    {
        foreach (['/' => 'ホーム', '/about' => 'About', '/service' => 'Service'] as $path => $label) {
            $html = (string) $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('rel="canonical"', $html, "{$label}: canonical が無い");

            foreach ([
                'og:site_name',
                'og:title',
                'og:description',
                'og:type',
                'og:url',
            ] as $property) {
                $this->assertStringContainsString(
                    "property=\"{$property}\"",
                    $html,
                    "{$label}: {$property} が無い",
                );
            }
        }
    }

    /** 記事詳細は `layouts/public.blade.php` を使う。**そちらは元から在る**ことも固定する */
    public function test_記事詳細にもcanonicalとogが出る(): void
    {
        $slug = 'seo-'.uniqid();

        DB::table('news')->insert([
            'title' => 'SEO 確認', 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
    }

    /**
     * **説明文を空で出さない**（`0615519`）。
     *
     * `<meta property="og:description" content="">` は「無い」のと同じで、
     * **共有されたときに何も伝わらない**。タグが在ることだけを見ていると、
     * **中身が空のまま気づかない**。
     */
    public function test_ホームの説明文が空でない(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        preg_match('#<meta property="og:description" content="([^"]*)"#', $html, $m);

        $this->assertNotEmpty($m, 'og:description が無い');
        $this->assertNotSame('', trim($m[1]), 'og:description が空');
    }

    /**
     * **同じ og を 2 度出さない。**
     *
     * layout へ寄せたのに手書きが残っていると、**同じ property が 2 つ**出る。
     * 検索エンジンがどちらを読むかは決まっておらず、
     * **直したつもりの方が読まれない**ことが起きる。
     */
    public function test_ogが重複しない(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach (['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name'] as $property) {
            $this->assertSame(
                1,
                substr_count($html, "property=\"{$property}\""),
                "{$property} が 2 つ以上出ている",
            );
        }

        $this->assertSame(1, substr_count($html, 'rel="canonical"'), 'canonical が 2 つ以上出ている');
    }
}
