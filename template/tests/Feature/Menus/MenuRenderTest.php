<?php

declare(strict_types=1);

namespace Tests\Feature\Menus;

use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use AgentCms\Core\Menus\MenuTree;
use App\Models\User;
use App\Support\OperationCatalog;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 公開面の描画（Phase 9.9 / STAGE 22C・SPEC §D）。
 *
 * ここで固定するのは **URL がどう作られるか**と、**出せない項目が出ないこと**である。
 * 保存の形は 22B（`MenuStorageTest`）が見ている。
 */
final class MenuRenderTest extends TestCase
{
    use RefreshDatabase;

    // ── URL の導出（保存されていないものを作る） ──────────────

    public function test_組み込みページはroute名から作る(): void
    {
        $this->item(['title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about']);

        $this->assertSame('/about', $this->tree()[0]['url']);
    }

    public function test_ページはslugから作る(): void
    {
        $page = $this->page('company');
        $this->item(['title' => null, 'link_type' => 'page', 'page_id' => $page]);

        $item = $this->tree()[0];

        $this->assertSame('/company', $item['url']);
        // 表示名を省略したら対象の名前を使う（SPEC §B.8）
        $this->assertSame('会社概要', $item['title']);
    }

    public function test_投稿タイプの一覧は公開スラッグから作る(): void
    {
        $this->publicSlug('post', 'blog');
        $this->item(['title' => null, 'link_type' => 'content_index', 'content_type' => 'post']);

        $item = $this->tree()[0];

        $this->assertSame('/blog', $item['url']);
        $this->assertSame('投稿', $item['title']);
    }

    public function test_投稿はスラッグを繋いで作る(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('はじめての記事', 'first-post');

        $this->item(['title' => null, 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        $item = $this->tree()[0];

        $this->assertSame('/blog/first-post', $item['url']);
        $this->assertSame('はじめての記事', $item['title']);
    }

    /** **これが 9.9 の要点。** slug を変えても URL が追随する */
    public function test_slugを変えるとurlも変わる(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('記事', 'before');
        $this->item(['title' => '記事', 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        $this->assertSame('/blog/before', $this->tree()[0]['url']);

        DB::table('post')->where('id', $id)->update(['slug' => 'after']);

        // memo は 1 リクエスト内だけなので、次の要求では新しい URL になる
        $this->assertSame('/blog/after', $this->freshTree()[0]['url']);
    }

    public function test_外部urlはそのまま出す(): void
    {
        $this->item([
            'title' => '採用情報', 'link_type' => 'custom',
            'link_url' => 'https://example.com/recruit', 'target' => '_blank',
        ]);

        $item = $this->tree()[0];

        $this->assertSame('https://example.com/recruit', $item['url']);
        $this->assertSame('_blank', $item['target']);
        // OG10: 常に付ける
        $this->assertSame('noopener', $item['rel']);
    }

    // ── 出せない項目は落とす（OG4） ──────────────────────────

    public function test_ゴミ箱の投稿は出さない(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('消えた記事', 'gone');
        $this->item(['title' => '記事', 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        $this->assertCount(1, $this->tree());

        DB::table('post')->where('id', $id)->update(['deleted_at' => now()]);

        $this->assertSame([], $this->freshTree());
        $this->assertStringContainsString('ゴミ箱', implode(' ', app(MenuTree::class)->unavailable('header')));
    }

    public function test_戻せば導線も戻る(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('記事', 'back');
        $this->item(['title' => '記事', 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        DB::table('post')->where('id', $id)->update(['deleted_at' => now()]);
        $this->assertSame([], $this->freshTree());

        DB::table('post')->where('id', $id)->update(['deleted_at' => null]);
        $this->assertCount(1, $this->freshTree());
    }

    public function test_下書きの投稿は出さない(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('下書き', 'draft-post', status: 'draft');
        $this->item(['title' => '記事', 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        $this->assertSame([], $this->tree());
        $this->assertStringContainsString('下書き', implode(' ', app(MenuTree::class)->unavailable('header')));
    }

    public function test_ゴミ箱のページは出さない(): void
    {
        $page = $this->page('company');
        $this->item(['title' => '会社概要', 'link_type' => 'page', 'page_id' => $page]);

        DB::table('pages')->where('id', $page)->update(['trashed_at' => now()]);

        $this->assertSame([], $this->freshTree());
    }

    public function test_routeが無ければ出さない(): void
    {
        // module が入っていない生成物を模す
        $this->item(['title' => '実績', 'link_type' => 'route', 'link_route' => 'about']);
        MenuItem::query()->update(['link_route' => 'works.index.missing']);

        $this->assertSame([], $this->freshTree());
    }

    public function test_親が出せないなら子も出さない(): void
    {
        $page = $this->page('company');
        $parent = $this->item(['title' => '親', 'link_type' => 'page', 'page_id' => $page]);
        $this->item(['title' => '子', 'link_type' => 'route', 'link_route' => 'about', 'parent_id' => $parent]);

        $this->assertCount(1, $this->tree());
        $this->assertCount(1, $this->tree()[0]['children']);

        DB::table('pages')->where('id', $page)->update(['trashed_at' => now()]);

        // 子だけ繰り上げない（編集者が組んだ構造と違うものを表に出さない）
        $this->assertSame([], $this->freshTree());
    }

    // ── いまここ ────────────────────────────────────────────

    public function test_いま見ているページに印が付く(): void
    {
        $this->item(['title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about']);
        $this->item(['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);

        $items = app(MenuTree::class)->forKey('header', 'about');

        $this->assertTrue($items[0]['current']);
        $this->assertFalse($items[1]['current']);
    }

    public function test_子が現在なら親にも印が付く(): void
    {
        $parent = $this->item(['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item(['title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about', 'parent_id' => $parent]);

        $items = app(MenuTree::class)->forKey('header', '/about/');

        $this->assertTrue($items[0]['current'], '子が現在なのに親に印が無い');
        $this->assertTrue($items[0]['children'][0]['current']);
    }

    // ── 描画 ────────────────────────────────────────────────

    /**
     * `/about` などは**取り込み済みテーマ**の layout を使う（SPEC §H.3 で対象外）。
     * 置き換えたのは `layouts/public.blade.php` なので、そちらを使う面で見る。
     * 記事の一覧もサイト内検索もサイトの枠に載るようになった（UX 一周 2026-09-28）ので、
     * 店の枠を外して（`withoutSiteFrame()`）、kit 既定の枠に載ったサイト内検索（/_search）で見る。
     */
    public function test_公開ページの導線がデータから出る(): void
    {
        $this->publicSlug('post', 'blog');
        $this->item(['title' => 'データから出た項目', 'link_type' => 'route', 'link_route' => 'about']);

        $this->withoutSiteFrame();
        $html = (string) $this->get('/_search')->assertOk()->getContent();

        $this->assertStringContainsString('データから出た項目', $html);
        // 要素と class は据え置き（取り込み済みテーマの CSS を壊さない — SPEC §H.2）
        $this->assertMatchesRegularExpression('/<nav [^>]*class="site-nav"[^>]*aria-label="メイン"/u', $html);
    }

    /**
     * **ヘッダーだけは、空でも導線を出す**（B-28 で変えた）。
     *
     * 以前はここで「空の landmark を置かない」を見ていた。
     * トップにページを選ぶと layout が `layouts.public` へ替わるので、
     * メニューが 0 件の生成物では**公開サイトから導線が丸ごと消えていた**（実測 `href` 19 → 3）。
     * 訪問者にナビが無い状態は公開品質として通らないので、**既定の導線へ落ちる**。
     *
     * 「空の landmark を置かない」という規則自体は生きている — 下のフッターの試験がそれである。
     */
    public function test_項目が無ければ既定の導線へ落ちる(): void
    {
        $this->publicSlug('post', 'blog');
        Menu::query()->where('key', 'header')->delete();

        $this->withoutSiteFrame();
        $html = (string) $this->get('/_search')->assertOk()->getContent();

        $this->assertStringContainsString('data-default-navigation', $html);
        // 要素と class は据え置き（取り込み済みテーマの CSS を壊さない）
        $this->assertMatchesRegularExpression('/<nav [^>]*class="site-nav"[^>]*aria-label="メイン"/u', $html);
    }

    /**
     * **フッターの肯定側**（監査の指摘）。
     *
     * 下の否定側（空なら出さない）だけだと、**class 名を変えた変異が素通りする**
     * — 検査が名前を 1 度も見ていないからである。出る側と出ない側の**対**で成立させる。
     */
    public function test_フッターの導線がデータから出る(): void
    {
        $this->publicSlug('post', 'blog');

        $footerId = Menu::query()->where('key', 'footer')->value('id')
            ?? DB::table('menus')->insertGetId([
                'key' => 'footer', 'name' => 'フッター', 'managed_by' => Menu::MANAGED_BY_ADMIN,
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('menu_items')->insert([
            'menu_id' => $footerId, 'parent_id' => null, 'sort_order' => 0,
            'title' => 'フッターから出た項目', 'link_type' => 'route', 'link_route' => 'about',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withoutSiteFrame();
        $html = (string) $this->get('/_search')->assertOk()->getContent();

        $this->assertStringContainsString('フッターから出た項目', $html);
        // 要素と class は据え置き（取り込み済みテーマの CSS を壊さない）
        $this->assertMatchesRegularExpression(
            '/<nav [^>]*class="site-footer__nav"[^>]*aria-label="フッター"/u',
            $html,
        );
    }

    /** **逃げ道を渡していない本は、これまでどおり空なら出さない**（勝手に生やさない） */
    public function test_フッターは項目が無ければnavごと出さない(): void
    {
        $this->publicSlug('post', 'blog');
        Menu::query()->where('key', 'footer')->delete();

        $html = (string) $this->get('/blog')->assertOk()->getContent();

        $this->assertStringNotContainsString('site-footer__nav', $html);
    }

    public function test_題名に仕込んだscriptは文字として出る(): void
    {
        $this->item([
            'title' => '<script>window.__xss=1</script>',
            'link_type' => 'route', 'link_route' => 'about',
        ]);

        $this->publicSlug('post', 'blog');

        $html = (string) $this->get('/blog')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>window.__xss=1</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_直書きのリンクが残っていない(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/public.blade.php'));

        $this->assertStringContainsString('<x-navigation menu="header"', $source);
        $this->assertStringContainsString('<x-navigation menu="footer"', $source);
        // 導線の直書きが 1 つも残っていない
        $this->assertStringNotContainsString("route('about')", $source);
        $this->assertStringNotContainsString("route('works.index')", $source);
        $this->assertStringNotContainsString("route('contact.show')", $source);
    }

    // ── seeder ──────────────────────────────────────────────

    public function test_seederは既定の2本を入れる(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(['footer', 'header'], Menu::query()->orderBy('key')->pluck('key')->all());

        // テーマが参照する本なので code 管理（消せない）
        $this->assertTrue(Menu::query()->where('key', 'header')->first()->isCodeManaged());

        $titles = MenuItem::query()
            ->whereIn('menu_id', Menu::query()->where('key', 'header')->pluck('id'))
            ->orderBy('sort_order')
            ->pluck('title')
            ->all();

        // 直書きにあった項目のうち、**この生成物に在るものだけ**
        $this->assertContains('私たちについて', $titles);
        $this->assertContains('お問い合わせ', $titles);
        // kit には works / news の schema が在るので入る。無い生成物では入らない
        $this->assertContains('実績', $titles);
    }

    public function test_seederは2回流しても増えない(): void
    {
        $this->seed(MenuSeeder::class);
        $before = MenuItem::query()->count();

        // header と footer の両方から消える（2 行）
        $removed = MenuItem::query()->where('title', 'サービス')->delete();
        $this->seed(MenuSeeder::class);

        // 利用者が組み替えたものを上書きしない
        $this->assertSame($before - $removed, MenuItem::query()->count());
        $this->assertSame(2, Menu::query()->count());
    }

    // ── API と公開面 ────────────────────────────────────────

    public function test_apiはurl付きの木を返す(): void
    {
        $this->publicSlug('post', 'blog');
        $id = $this->entry('記事', 'api-post');
        $this->item(['title' => '記事', 'link_type' => 'content', 'content_type' => 'post', 'content_id' => $id]);

        $response = $this->actingAs($this->owner())->getJson('/api/v1/menus/header');

        $response->assertOk()
            ->assertJsonPath('data.menu.key', 'header')
            ->assertJsonPath('data.items.0.url', '/blog/api-post');

        // 「いま見ているページ」は API には無い（SPEC §D.3）
        $this->assertArrayNotHasKey('current', $response->json('data.items.0'));
    }

    public function test_apiは未認証では返さない(): void
    {
        $this->getJson('/api/v1/menus')->assertUnauthorized();
        $this->getJson('/api/v1/menus/header')->assertUnauthorized();
    }

    public function test_公開面はmenuのapiを使わない(): void
    {
        // 公開ページは Blade component で描く。**未認証で辿れる menu の route を作らない**
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_contains($route->uri(), 'menu'),
        ));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains(
                'auth',
                $route->gatherMiddleware(),
                "{$route->uri()} が未認証で開ける",
            );
        }
    }

    public function test_aiへ公開するのは参照だけ(): void
    {
        $ids = OperationCatalog::ids();

        $this->assertContains('menu.list', $ids);
        $this->assertContains('menu.get', $ids);
        $this->assertNotContains('menu.save', $ids);
        $this->assertNotContains('menu.delete', $ids);
    }

    // ── helpers ────────────────────────────────────────────

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function menu(): Menu
    {
        $existing = Menu::query()->where('key', 'header')->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = DB::table('menus')->insertGetId([
            'key' => 'header', 'name' => 'ヘッダー', 'managed_by' => Menu::MANAGED_BY_ADMIN,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Menu::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function item(array $attributes): int
    {
        $menu = $this->menu();

        return (int) DB::table('menu_items')->insertGetId($attributes + [
            'menu_id' => $menu->getKey(),
            'parent_id' => null,
            'sort_order' => (int) MenuItem::query()->where('menu_id', $menu->getKey())->count(),
            'link_type' => 'custom',
            'target' => '_self',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 公開されているページ。
     *
     * **公開版を持たせるのを省かない。** `PublicPageController` は
     * `isPublicallyVisible()`（status = published かつ公開版あり）で 404 を決めているので、
     * 版の無いページを「公開」として作ると、テストだけが通る状態になる。
     */
    private function page(string $slug): int
    {
        $id = (int) DB::table('pages')->insertGetId([
            'slug' => $slug, 'title' => '会社概要', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = (int) DB::table('page_versions')->insertGetId([
            'page_id' => $id, 'revision' => 1, 'schema_version' => 1,
            'document' => json_encode(['type' => 'root', 'children' => []]),
            'created_interface' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $id)->update(['published_version_id' => $version]);

        return $id;
    }

    private function entry(string $title, string $slug, string $status = 'published'): int
    {
        return (int) DB::table('post')->insertGetId([
            'title' => $title, 'slug' => $slug, 'body' => '<p>本文</p>', 'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay() : null,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function publicSlug(string $type, string $slug): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'public_slug' => $slug,
                // 公開スラッグだけでは公開にならない（`public` フラグが要る）
                'registration' => json_encode(['public' => true]),
                'menu_order' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /** @return list<array<string, mixed>> */
    private function tree(): array
    {
        return app(MenuTree::class)->forKey('header');
    }

    /** memo を持たない新しい instance（次の要求を模す） */
    private function freshTree(): array
    {
        app()->forgetScopedInstances();

        return app(MenuTree::class)->forKey('header');
    }

    /**
     * 店の枠を外す（kit 既定の枠 `layouts.public` を見る試験のため）。
     *
     * 載せられない形の `layouts.imported` を手前に置くと、`DocumentPageView::siteLayout()` が
     * kit 既定へ落ちる（本物の案件で、枠がページを載せられない形のときと同じ道）。
     */
    private function withoutSiteFrame(): void
    {
        $dir = sys_get_temp_dir().'/sgk-no-site-frame-'.getmypid();
        @mkdir($dir.'/layouts', 0777, true);
        file_put_contents($dir.'/layouts/imported.blade.php', '<html><body>枠ではない</body></html>');
        app('view')->getFinder()->prependLocation($dir);
        app('view')->getFinder()->flush();
    }
}
