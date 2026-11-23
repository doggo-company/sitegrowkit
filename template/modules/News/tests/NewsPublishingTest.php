<?php

declare(strict_types=1);

namespace Modules\News\Tests;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\News\Models\News;
use Tests\TestCase;

final class NewsPublishingTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function makeNews(array $overrides = []): News
    {
        return News::query()->create(array_merge([
            'title' => 'テストニュース',
            'slug' => 'test-news',
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_published_scope_excludes_drafts_and_future_posts(): void
    {
        $this->makeNews(['slug' => 'published-one']);
        $this->makeNews(['slug' => 'draft-one', 'status' => 'draft']);
        $this->makeNews(['slug' => 'future-one', 'published_at' => now()->addDay()]);
        $this->makeNews(['slug' => 'no-date', 'published_at' => null]);

        $this->assertSame(['published-one'], News::published()->pluck('slug')->all());
    }

    public function test_public_page_shows_published_and_404s_draft(): void
    {
        $this->makeNews(['slug' => 'visible', 'title' => '公開記事']);
        $this->makeNews(['slug' => 'hidden', 'status' => 'draft']);

        $this->get('/news/visible')->assertOk()->assertSee('公開記事');
        $this->get('/news/hidden')->assertNotFound();
        $this->get('/news')->assertOk()->assertSee('公開記事');
    }

    public function test_richtext_body_is_sanitized_on_render(): void
    {
        $this->makeNews([
            'slug' => 'xss-check',
            'body' => '<p>安全な本文</p><script>alert(1)</script>',
        ]);

        $response = $this->get('/news/xss-check');
        $response->assertOk()->assertSee('安全な本文');
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_fillable_and_casts_derive_from_schema(): void
    {
        $news = $this->makeNews();

        $this->assertContains('title', $news->getFillable());
        $this->assertContains('published_at', $news->getFillable());
        $this->assertInstanceOf(CarbonInterface::class, $news->published_at);
    }
}
