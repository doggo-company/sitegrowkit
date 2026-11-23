<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 公開サイトのレンダリング検証。
 * Phase 5 以降、公開サイトは Template Import（Green Leaf Design）由来のため、
 * seed も期待値も TemplateContentSeeder に合わせる（DemoContentSeeder は管理系デモ用に残置）。
 */
final class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    public function test_all_five_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('Green Leaf Design');
        $this->get('/about')->assertOk()->assertSee('会社概要');
        $this->get('/service')->assertOk()->assertSee('料金プラン');
        $this->get('/works')->assertOk()->assertSee('制作実績');
        $this->get('/contact')->assertOk()->assertSee('お問い合わせ');
    }

    public function test_home_shows_latest_news_and_works(): void
    {
        $this->get('/')
            ->assertSee('夏季休業のお知らせ')
            ->assertSee('花屋のオンラインストア');
    }

    public function test_works_page_lists_imported_cards_in_template_order(): void
    {
        $response = $this->get('/works')->assertOk();
        $content = (string) $response->getContent();

        // テンプレートのカード順（published_at desc）が保たれている
        $first = mb_strpos($content, '花屋のオンラインストア');
        $last = mb_strpos($content, 'ハーブティー パッケージ');
        $this->assertNotFalse($first);
        $this->assertNotFalse($last);
        $this->assertLessThan($last, $first);
        $response->assertSee('EC / 2026');
    }

    public function test_sitemap_lists_published_content(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('/works/imported-work-1')
            ->assertSee('/news/imported-news-1');
    }

    public function test_sitemap_lists_generated_standalone_pages(): void
    {
        /*
         * 🔴 **取り込みで起こしたページが sitemap に載っていなかった**
         * （2026-08-28・実案件で実測。`/company` と `/recruit` が両方欠けていた）。
         *
         * **リンクからは辿れるので、画面を見ても気づけない。**
         * だが**検索から入る人には無いのと同じ**で、会社概要と求人は
         * 外から探して来る人が一番多いページ。
         *
         * ▷ **型名を書かない。**`Route::view` で登録された、引数の無い GET route を
         *   拾う — 案件ごとにページ名が違うので、名前で書くと他所で効かない。
         */
        Route::view('/generated-standalone', 'app')
            ->name('generated.standalone');

        // 素直でない例: route は在るが view が無い（開けば 500 になる）
        Route::view('/generated-missing', 'no.such.view')
            ->name('generated.missing');

        // 素直でない例: 引数を取る（1 本の URL に定まらない）
        Route::view('/generated-any/{slug}', 'app')
            ->name('generated.any');

        $response = $this->get('/sitemap.xml')->assertOk();

        $response->assertSee('/generated-standalone');
        $response->assertDontSee('/generated-missing');
        $response->assertDontSee('/generated-any');
    }

    public function test_robots_txt_points_to_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:');
    }

    public function test_meta_description_is_preserved_from_template(): void
    {
        // import 元テンプレートの meta description が保持されている（spec §27）
        $this->get('/')->assertSee('植物のように育つウェブサイト', false);
    }
}
