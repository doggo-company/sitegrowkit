<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\DefaultNavigation;
use Database\Seeders\MenuSeeder;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * メニューが空でも、公開ページから導線を消さない（B-28）。
 *
 * ## 何が起きていたか（実測）
 *
 * トップにページを選ぶと、描く layout が `layouts.imported` から `layouts.public` へ替わる。
 * `layouts.public` のナビは **CMS のメニュー由来**なので、メニューが 0 件だと
 * `<nav>` ごと消えていた（`href` **19 → 3**）。
 *
 * ## 直し方（(b) — director 裁定）
 *
 * 空なら**既定の導線へ落ちる**。判定は component が使っているのと**同じ 1 つの呼び出し**で、
 * 出す項目は seeder と**同じ宣言**（`App\Support\DefaultNavigation`）から来る。
 *
 * ## 落とさないが、黙らない
 *
 * 訪問者にはナビを出す。運用者にはサイト設定の画面で**出ていることを言う**
 * （「直さない。言う。」— 黙って代替を出さない）。
 */
final class DefaultNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    // ── 公開側 ──────────────────────────────

    public function test_メニューが空ならページのトップにも導線を出す(): void
    {
        $this->selectFrontPage($this->makePage('company'));

        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('data-default-navigation', $html);
        $this->assertStringContainsString('私たちについて', $html);
        $this->assertStringContainsString('お問い合わせ', $html);
    }

    /**
     * **利用者の側でも数える。** import していない生成物でも `layouts.public` は使われる
     * （投稿タイプの一覧・詳細がそこに載る）。「kit で緑」は「利用者で緑」ではない。
     */
    public function test_メニューが空でも投稿タイプの一覧に導線を出す(): void
    {
        $html = $this->get('/news')->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('data-default-navigation', $html);
        $this->assertStringContainsString('私たちについて', $html);
    }

    public function test_メニューが在れば既定の導線は出さない(): void
    {
        $this->seed(MenuSeeder::class);
        $this->selectFrontPage($this->makePage('company'));

        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertStringNotContainsString('data-default-navigation', $html);
        $this->assertStringContainsString('私たちについて', $html); // CMS のメニューが出ている
    }

    /**
     * **既定のトップはメニューに依存しない**（実測 ④ を pin する）。
     *
     * ここが壊れると「メニューを空にしたら既定のトップまで変わった」になる。
     */
    public function test_既定のトップはメニューが空でも変わらない(): void
    {
        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertStringNotContainsString('data-default-navigation', $html);
        $this->assertStringContainsString('class="gnav"', $html);

        /*
         * 19 → 14 になった（WP 一周 2 周目 2026-09-03）。
         *
         * 見本のトップは About / Service / Contact を**直に書いていた**ので、
         * メニューを空にしてもヘッダーとフッターに同じ数の link が出ていた。
         * いまはメニュー設定から描くので、
         *
         *   - ヘッダー … 空なら既定の導線へ落ちる（訪問者にナビが無い状態を公開しない）
         *   - フッター … 空なら**出さない**（意図して空にした本を勝手に生やさない。
         *     `<x-navigation>` が fallback を header だけに限っているのと同じ考え方）
         *
         * 減った 5 本はフッターのぶんである。**生成した project では
         * `MenuSeeder` が両方に項目を入れる**ので、この空の状態は起きない。
         *
         * 14 → 15（UX 一周 2026-09-28）。見本の枠にも「本文へスキップ」（#main）を足した 1 本。
         * 15 → 16（同日）。店の枠のフッターにサイト内検索への入口を足した 1 本（kit 既定の枠と揃えた）。
         */
        $this->assertSame(16, substr_count($html, 'href="'));
    }

    /** **メニューを空にしても、訪問者にナビが無いトップは出さない**（ヘッダーだけ既定へ落ちる） */
    public function test_メニューが空でもヘッダーのナビは残る(): void
    {
        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertMatchesRegularExpression('/<nav class="gnav".*?<a /su', $html);
    }

    /** **フッターは空にできる**（意図して空にした本を復活させない） */
    public function test_メニューが空ならフッターのナビは出さない(): void
    {
        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertStringNotContainsString('class="footer-nav"', $html);
    }

    /** 出す項目は seeder と同じ宣言から来る（順序も表示名も 1 か所） */
    public function test_既定の導線はseederと同じ宣言から出る(): void
    {
        $this->selectFrontPage($this->makePage('company'));

        $html = $this->get('/')->getContent() ?: '';
        $titles = array_column(DefaultNavigation::links(), 'title');

        $this->assertNotSame([], $titles, '宣言が空なら、この試験は何も見ていない');

        foreach ($titles as $title) {
            $this->assertStringContainsString($title, $html);
        }
    }

    /** 定義ファイルだけ残った投稿タイプは、操作不能な初期メニューにしない。 */
    public function test_schema_without_a_registered_content_type_is_not_seeded(): void
    {
        $this->assertTrue(app(SchemaRegistry::class)->has('news'));
        app()->instance(ContentTypeRegistry::class, new ContentTypeRegistry);
        $this->seed(MenuSeeder::class);
        $this->assertSame(0, DB::table('menu_items')->whereIn('content_type', ['news', 'works'])->count());
        $this->assertNotContains('ニュース', array_column(DefaultNavigation::links(), 'title'));
        $this->assertContains('お問い合わせ', array_column(DefaultNavigation::links(), 'title'));
    }

    // ── 運用者への 1 行 ──────────────────────────

    public function test_既定の導線が出ていることを設定画面で言う(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/site')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigationNotice', fn (?string $v): bool => is_string($v)
                    && str_contains($v, '既定の導線')));
    }

    public function test_メニューが在れば設定画面では黙る(): void
    {
        $this->seed(MenuSeeder::class);

        $this->actingAs($this->owner())
            ->get('/admin/site')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('navigationNotice', null));
    }

    // ── helpers ──────────────────────────────

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    private function selectFrontPage(int $id): void
    {
        app(SettingsStore::class)->put([FrontPage::SETTING_KEY => $id]);
        app()->forgetScopedInstances();
    }

    private function makePage(string $slug): int
    {
        $id = (int) DB::table('pages')->insertGetId([
            'slug' => $slug, 'title' => '会社概要', 'status' => 'published',
            'managed_by' => 'document', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = (int) DB::table('page_versions')->insertGetId([
            'page_id' => $id, 'revision' => 1, 'schema_version' => 1,
            'document' => json_encode(['root' => [
                'type' => 'page', 'id' => (string) Str::ulid(),
                'children' => [['type' => 'section', 'id' => (string) Str::ulid(), 'children' => [
                    ['type' => 'heading', 'id' => (string) Str::ulid(), 'props' => ['text' => 'トップ', 'level' => 1]],
                ]]],
            ]], JSON_UNESCAPED_UNICODE),
            'created_interface' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $id)->update(['published_version_id' => $version]);

        return $id;
    }

    // ── 「経路が在る」ではなく「描けるか」で見る（B-46） ──

    /**
     * **route が在っても、view が無ければ導線に出さない。**
     *
     * ▷ **実際に起きた**（2026-08-27・実案件）: 取り込んだ見本のサンプル本文を消して
     * `/about` `/service` を 404 にしたのに（B-40）、**route は「次に戻すときの足場」として
     * 残してある**。route だけを見ていると、**404 のページを既定の導線に載せる。**
     *
     * 残した判断は正しいので、**読み方のほうを直した。**
     */
    public function test_viewが無い同梱ページは既定の導線に出さない(): void
    {
        $this->withoutView('public.about', function (): void {
            $titles = array_column(DefaultNavigation::links(), 'title');

            $this->assertNotContains('私たちについて', $titles, '404 になるページを導線に載せている');
            $this->assertContains('サービス', $titles, '関係ない項目まで落としている');
        });
    }

    /** seeder 側も同じ判定で落とす（**片方だけ育たない**） */
    public function test_viewが無い同梱ページはseederにも入らない(): void
    {
        $this->withoutView('public.about', function (): void {
            $routes = array_column(DefaultNavigation::seedItems(), 'link_route');

            $this->assertNotContains('about', $routes, 'seeder が 404 のページを入れている');
            $this->assertContains('service', $routes);
        });
    }

    /** **view の名前を持たない項目は、これまでどおり route だけで見る** */
    public function test_viewを持たない項目は今までどおり出る(): void
    {
        $this->withoutView('public.about', function (): void {
            $titles = array_column(DefaultNavigation::links(), 'title');

            $this->assertContains('お問い合わせ', $titles, 'view の名前で決まらない項目まで落としている');
        });
    }

    /**
     * **route が無い生成物では、その項目ごと入れない。**
     *
     * ▷ この試験は**変異で生き残ったから足した**。
     * `Route::has()` の判定を外しても 10 本とも緑のままだった —
     * **宣言表の Route 項目は、この環境では全部 route を持っている**からである
     * （`about` / `service` / `contact.show` は `routes/public.php` が必ず登録する）。
     * **「利用者の素の生成物には無い route がある」と docblock に書いてあるのに、
     * その状態を作った例が 1 つも無かった。**
     */
    public function test_routeが無い項目は導線にもseederにも入らない(): void
    {
        $this->withoutRoute('about', function (): void {
            $this->assertNotContains(
                '私たちについて',
                array_column(DefaultNavigation::links(), 'title'),
                'route が無いのに導線へ出している',
            );
            $this->assertNotContains(
                'about',
                array_column(DefaultNavigation::seedItems(), 'link_route'),
                'route が無いのに seeder へ入れている',
            );
        });
    }

    /**
     * その名前の route を**無いことにして**本体を走らせる（必ず片づける）。
     *
     * 生成物によっては登録されない route がある、という状態をここで作る。
     */
    private function withoutRoute(string $name, callable $body): void
    {
        $router = app('router');
        $original = $router->getRoutes();

        $this->assertTrue($original->hasNamedRoute($name), "前提: route [{$name}] が在ること");

        $without = new RouteCollection;

        foreach ($original->getRoutes() as $route) {
            if ($route->getName() !== $name) {
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

    /**
     * その view を**無いことにして**本体を走らせる（必ず片づける）。
     *
     * kit には同梱ページの view が在るので、**消してみないとこの判定は試せない**。
     */
    private function withoutView(string $name, callable $body): void
    {
        $path = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');
        $away = $path.'.b46-backup';
        $moved = is_file($path) && rename($path, $away);

        $this->assertTrue($moved, "前提: {$name} の view が在ること（無いと何も試していない）");

        try {
            $body();
        } finally {
            if ($moved) {
                rename($away, $path);
            }
        }
    }
}
