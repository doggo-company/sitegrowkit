<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TermArchiveLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 分類の一覧へ「押せる先が在るときだけ」リンクする（R05・2026-09-09）。
 *
 * `news` / `works`（名前付き route の世界）と、CMS が作った型（fallback の世界）で
 * **URL の形が違う**。view に形を書くと 404 を配るので、判定を 1 か所にした。
 *
 * ## 見ている世界
 *
 * - module の route が在る型 → `route('{型}.term')`
 * - route が無い型（新しく生成したサイトには `news` / `works` が無い）→ fallback の形。
 *   公開されていなければ null（一覧は 404 なのでリンクにしない）
 * - `has_archive` を持たない型 → どちらの世界でも null
 */
final class TermArchiveLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_moduleのrouteが在る型はその形で作る(): void
    {
        $this->assertSame('/news/news_category/press', $this->links()->url('news', 'news_category', 'press'));
        $this->assertSame('/works/gyoshu/inshoku', $this->links()->url('works', 'gyoshu', 'inshoku'));
    }

    public function test_一覧を持たない設定ではnull(): void
    {
        $this->setting('news', ['has_archive' => false]);

        $this->assertNull($this->links()->url('news', 'news_category', 'press'));
    }

    /** 公開した CMS の型は fallback の形（`PublicContentController` が 3 segment で受ける） */
    public function test_fallbackの型は入口から作る(): void
    {
        $this->setting('post', ['public' => true], publicSlug: 'blog');

        $this->assertSame('/blog/shinryo_kamoku/term-1', $this->links()->url('post', 'shinryo_kamoku', 'term-1'));
    }

    /** 🔴 公開していない型の一覧は 404 なので、リンクにしない */
    public function test_公開していない型はnull(): void
    {
        $this->setting('post', ['public' => false], publicSlug: 'blog');

        $this->assertNull($this->links()->url('post', 'shinryo_kamoku', 'term-1'));
    }

    /**
     * 🔴 **route が無い生成物**（新しいサイトには `news` の module が無い）。
     *
     * route を無いことにすると fallback の世界に落ち、公開スラッグの行が無ければ null になる
     * — `/news/…` を組み立てて 404 を配らない。
     */
    public function test_routeが無ければfallbackの判定に落ちる(): void
    {
        $this->withoutRoutes(['news.term', 'news.index'], function (): void {
            $this->assertNull($this->links()->url('news', 'news_category', 'press'));
        });
    }

    /** 綴りに URL で危ない文字が来ても、そのまま出さない */
    public function test_綴りはurlエンコードする(): void
    {
        $this->setting('post', ['public' => true], publicSlug: 'blog');

        $this->assertSame('/blog/k/a%20b%3Fc', $this->links()->url('post', 'k', 'a b?c'));
    }

    public function test_for_entryは各項目にurlを足す(): void
    {
        $this->setting('post', ['public' => true], publicSlug: 'blog');
        $taxonomy = '01J00000000000000000000000';
        $term = '01J00000000000000000000001';

        DB::table('taxonomies')->insert([
            'id' => $taxonomy, 'key' => 'shinryo_kamoku', 'label' => '診療科目', 'hierarchical' => false,
            'status' => 'active', 'managed_by' => 'cms', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_terms')->insert([
            'id' => $term, 'taxonomy_id' => $taxonomy, 'name' => '予防接種', 'slug' => 'term-1',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $term, 'content_type' => 'post', 'content_id' => 1, 'sort_order' => 0, 'created_at' => now(),
        ]);

        $this->assertSame([[
            'key' => 'shinryo_kamoku', 'label' => '診療科目',
            'terms' => [['name' => '予防接種', 'slug' => 'term-1', 'url' => '/blog/shinryo_kamoku/term-1']],
        ]], $this->links()->forEntry('post', 1));

        $this->assertSame([], $this->links()->forEntry('post', 2));
    }

    // ── helpers ────────────────────────────────────────────

    private function links(): TermArchiveLinks
    {
        app()->forgetScopedInstances();

        return app(TermArchiveLinks::class);
    }

    /** @param  array<string, bool>  $registration */
    private function setting(string $type, array $registration, ?string $publicSlug = null): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'public_slug' => $publicSlug,
                'registration' => json_encode($registration, JSON_UNESCAPED_UNICODE),
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /**
     * その名前の route を**無いことにして**本体を走らせる（`DefaultNavigationTest` と同じ手）。
     *
     * @param  list<string>  $names
     */
    private function withoutRoutes(array $names, callable $body): void
    {
        $router = app('router');
        $original = $router->getRoutes();

        foreach ($names as $name) {
            $this->assertTrue($original->hasNamedRoute($name), "前提: route [{$name}] が在ること");
        }

        $without = new RouteCollection;

        foreach ($original->getRoutes() as $route) {
            if (! in_array($route->getName(), $names, true)) {
                $without->add($route);
            }
        }

        $router->setRoutes($without);

        try {
            $body();
        } finally {
            $router->setRoutes($original);
        }
    }
}
