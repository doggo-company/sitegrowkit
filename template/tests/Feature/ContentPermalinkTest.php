<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Permalink\ContentPermalink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\News\Models\News;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開 URL の形を知っている場所を 1 つにする（P1 ③ 段 1）。
 *
 * ## 何を守りたいのか
 *
 * 実測したら **6 か所**が別々に URL を組み立てていた（sitemap は同じ物を 2 通りで）。
 * このままで構造を設定にすると、**管理画面のリンクと sitemap と公開ページが食い違い、
 * どれが正しいかを誰も言えなくなる**。
 *
 * ## 炙り方
 *
 * **`publicSlug` を動かして、全部の面が追随するか**を見る。
 * 口を通っていない場所は、動かしても古い URL を出し続けるので**そこで赤くなる**。
 */
final class ContentPermalinkTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** CMS が作った型（fallback の世界）を 1 つ用意する */
    private function publishType(string $type, string $base): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'lock_version' => 0,
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'public_slug' => $base,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        /*
         * 設定は**要求のあいだ持たれる**（`ContentTypeSettingsResolver` が memoize する）。
         * 同じ実行の中で入口を動かすので、持っている分を捨てる。
         * **アプリごと作り直さない** — in-memory の DB まで消える。
         */
        app()->forgetScopedInstances();
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        app()->forgetInstance(ContentPermalink::class);
    }

    private function news(string $title, string $slug): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function permalink(): ContentPermalink
    {
        return app(ContentPermalink::class);
    }

    /** 管理一覧の 1 行目が指している公開 URL */
    private function adminRowUrl(): ?string
    {
        $url = null;

        $this->actingAs($this->operator())->get('/admin/post')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$url): void {
                /** @var array<string, mixed> $items */
                $items = $page->toArray()['props']['items'] ?? [];
                $url = $items['data'][0]['publicUrl'] ?? null;
            });

        return is_string($url) ? $url : null;
    }

    /**
     * **名前付き route の世界**（`news` / `works`）。
     *
     * 形は `route()` が決める。ここを勝手に変えない — 既に配ったサイトの URL である。
     */
    public function test_名前付きrouteの型はrouteが決めた形になる(): void
    {
        $this->news('記事', 'hello');
        $entry = News::query()->firstOrFail();

        $this->assertSame('/news/hello', $this->permalink()->entry('news', $entry, absolute: false));
        $this->assertSame(url('/news/hello'), $this->permalink()->entry('news', $entry));
        $this->assertSame(url('/news'), $this->permalink()->index('news'));
    }

    /**
     * **fallback の世界**。`publicSlug` が形を決める。
     */
    public function test_public_slugの型はslugが形を決める(): void
    {
        $this->publishType('news', 'oshirase');

        // **名前付き route が在るので、そちらが勝つ**（いまの振る舞いを固定する）
        $this->news('記事', 'hello');
        $entry = News::query()->firstOrFail();

        $this->assertSame('/news/hello', $this->permalink()->entry('news', $entry, absolute: false));

        // 入口の segment からは、その型が引ける（**解く側の入口**）
        $this->assertSame('news', $this->permalink()->typeForBase('oshirase'));
        $this->assertNull($this->permalink()->typeForBase('shiranai'));
    }

    /** **slug を持たない行では作らない**（無効なリンクを出さない） */
    public function test_slugが無ければurlを作らない(): void
    {
        $entry = new News;

        $this->assertNull($this->permalink()->entry('news', $entry));
    }

    /** **知らない型では作らない** */
    public function test_知らない型ではurlを作らない(): void
    {
        $this->news('記事', 'hello');
        $entry = News::query()->firstOrFail();

        $this->assertNull($this->permalink()->entry('unknown_type', $entry));
        $this->assertNull($this->permalink()->index('unknown_type'));
    }

    /**
     * **口を通っていない場所を炙る。**
     *
     * `publicSlug` を動かしたとき、**管理画面の行・sitemap・公開ページ**が
     * そろって追随すること。自分で URL を組み立てている場所は、ここで取り残される。
     */
    public function test_slugを動かすと全部の面が追随する(): void
    {
        $this->publishType('post', 'blog');
        DB::table('post')->insert([
            'title' => '記事', 'slug' => 'hello', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 公開ページが `/blog/hello` で開く
        $this->get('/blog/hello')->assertOk();

        // sitemap に `/blog/hello` が載る
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/blog/hello'), false);

        /*
         * 管理画面の行も `/blog/hello` を指す。
         *
         * ▷ **ここは口を 1 つにしたことで直った所である。** これまでの `publicUrl` は
         *   「名前付き route が在るとき」しか URL を作らず、**CMS が作った型では
         *   公開ページが在るのにリンクが出なかった**（`Route::has()` で弾いていた）。
         */
        $this->assertSame('/blog/hello', $this->adminRowUrl());

        // **入口を動かす**
        $this->publishType('post', 'journal');

        $this->get('/blog/hello')->assertNotFound();
        $this->get('/journal/hello')->assertOk();
        $this->assertSame('/journal/hello', $this->adminRowUrl());
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(url('/journal/hello'), false)
            ->assertDontSee(url('/blog/hello'), false);
    }
}
