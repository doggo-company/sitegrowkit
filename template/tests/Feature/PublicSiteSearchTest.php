<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 横断検索は公開済みだけを返し、ページ送りでも検索条件を失わない。 */
final class PublicSiteSearchTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $title, string $status = 'published', bool $future = false): void
    {
        DB::table('news')->insert(['title' => $title, 'slug' => 'search-'.uniqid(), 'body' => '<p>犬と暮らす毎日</p>',
            'status' => $status, 'published_at' => $future ? now()->addDay() : now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function page(string $title, string $status = 'published', bool $noindex = false): Page
    {
        $page = Page::query()->create(['title' => $title, 'slug' => 'page-'.uniqid(), 'status' => $status, 'managed_by' => 'document', 'published_at' => now()->subDay(), 'seo' => ['noindex' => $noindex]]);
        $version = PageVersion::query()->create(['page_id' => $page->id, 'revision' => 1, 'document' => ['version' => 1, 'blocks' => []]]);
        $page->update(['published_version_id' => $version->id]);

        return $page;
    }

    public function test_記事本文とページタイトルを同じ窓で検索する(): void
    {
        $this->article('暮らしの読み物');
        $this->page('犬のお手入れ');
        $this->get('/_search?q='.urlencode('犬'))->assertOk()->assertSee('暮らしの読み物')->assertSee('犬のお手入れ')->assertSee('2件')->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_下書きと予約と検索除外ページを出さない(): void
    {
        $this->article('犬・公開');
        $this->article('犬・下書き', 'draft');
        $this->article('犬・予約', 'published', true);
        $this->page('犬・未公開ページ', 'draft');
        $this->page('犬・検索除外', 'published', true);
        $this->page('犬・将来のページ')->update(['published_at' => now()->addDay()]);
        $this->page('犬・ごみ箱')->update(['trashed_at' => now()]);
        $this->get('/_search?q='.urlencode('犬'))->assertOk()->assertSee('犬・公開')->assertSee('1件')
            ->assertDontSee('犬・下書き')->assertDontSee('犬・予約')->assertDontSee('犬・未公開ページ')->assertDontSee('犬・検索除外')->assertDontSee('犬・将来のページ')->assertDontSee('犬・ごみ箱');
    }

    public function test_型をまたいで十二件ずつページ送りできる(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->article('犬の投稿'.$i);
        }
        $this->page('犬のページ');
        $this->get('/_search?q='.urlencode('犬'))->assertOk()->assertSee('14件')->assertSee('次へ')->assertDontSee('犬のページ');
        $this->get('/_search?q='.urlencode('犬').'&page=2')->assertOk()->assertSee('14件')->assertSee('犬のページ')->assertSee('前へ');
    }

    public function test_停止した型の投稿は検索に出ない(): void
    {
        $this->article('犬・停止中');
        DB::table('content_types')->insert(['content_type_id' => 'news', 'status' => 'archived', 'lifecycle' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/_search?q='.urlencode('犬'))->assertOk()->assertSee('0件')->assertDontSee('犬・停止中');
    }

    public function test_空検索と危険な文字列と不正入力を扱う(): void
    {
        $this->article('犬の公開');
        $this->get('/_search')->assertOk()->assertDontSee('犬の公開');
        $this->get('/_search?q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('0件');
        $this->getJson('/_search?q[]=bad')->assertUnprocessable();
        $this->getJson('/_search?q='.str_repeat('x', 201))->assertUnprocessable();
        $this->getJson('/_search?q=test&page=-1')->assertUnprocessable();
    }
}
