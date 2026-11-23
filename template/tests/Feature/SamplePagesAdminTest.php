<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuTree;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\BundledPages;
use App\Support\Role;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開前チェックが指摘する見本ページを、管理画面から直す・消す（UX 一周 2026-09-27）。
 *
 * ## 何が起きていたか
 *
 * 公開前チェックは `/` `/about` `/service` `/contact` を「同梱の見本のまま公開されています
 * （差し替えるか、このページを消してください）」と指摘する。ところが
 *
 * - 指摘の行を押しても何も起きない（リンクではない）
 * - ページ一覧に 4 つとも出てこない
 * - 管理画面のどこにも、消す・差し替える手段が無い
 *
 * **指示に従いたくても手段が無い**行き止まりだった。
 */
final class SamplePagesAdminTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    public function test_ページ一覧に見本ページが直し方つきで出る(): void
    {
        $this->actingAs($this->operator())
            ->get('/admin/pages')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('admin/pages/Index')
                ->where('samples', fn ($samples) => collect($samples)->pluck('key')->all() === ['home', 'about', 'service', 'contact'])
                ->where('samples.1.actions.0.kind', 'hide')
                ->where('canManageSamples', true),
            );
    }

    public function test_公開前チェックの見本の指摘から直す場所へ行ける(): void
    {
        $findings = collect((array) $this->actingAs($this->operator())
            ->get('/admin/readiness')
            ->assertOk()
            ->inertiaProps('findings'));

        $samples = $findings->filter(fn (array $f): bool => str_starts_with($f['what'], '同梱の見本のまま'));

        $this->assertNotEmpty($samples, '見本の指摘が 1 件も出ていない（前提が崩れている）');

        foreach ($samples as $finding) {
            $this->assertNotNull($finding['fix'], "{$finding['where']} の指摘に行き先が無い");
            $this->assertStringStartsWith('/admin/pages#sample-', $finding['fix']['href']);
        }

        // 問い合わせの通知先も、直す画面へ
        $notify = $findings->firstWhere('where', '問い合わせの通知先');

        if ($notify !== null) {
            $this->assertStringStartsWith('/admin/site', $notify['fix']['href']);
        }
    }

    public function test_非公開にすると開けずサイトマップとメニューからも外れ戻せる(): void
    {
        $this->headerMenuWithAbout();
        $this->get('/about')->assertOk();

        $this->actingAs($this->operator())
            ->post('/admin/sample-pages/about/hide')
            ->assertRedirect('/admin/pages#sample-about');

        $this->assertTrue(app(BundledPages::class)->isHidden('about'));
        $this->get('/about')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/about</loc>', false);
        $this->assertNotContains('/about', $this->headerUrls(), 'メニューに押すと 404 のリンクが残っている');

        // 一覧では「非公開」になり、戻すボタンが出る
        $this->actingAs($this->operator())
            ->get('/admin/pages')
            ->assertInertia(fn (Assert $p) => $p
                ->where('samples.1.key', 'about')
                ->where('samples.1.state', 'hidden')
                ->where('samples.1.actions.0.kind', 'show'),
            );

        $this->actingAs($this->operator())->post('/admin/sample-pages/about/show')->assertRedirect();

        $this->get('/about')->assertOk();
        $this->assertContains('/about', $this->headerUrls());
    }

    public function test_非公開にしたurlは同じslugのページに譲る(): void
    {
        $this->publishedPage('about', 'ここは作り直した会社概要です');

        /*
         * 同じ URL のページを公開したら、見本を非公開にしなくてもそちらが出る
         * （UX 一周 2026-09-28。以前は見本が勝ち、案内どおりに作った人が止まっていた）。
         */
        $this->get('/about')->assertOk()->assertSee('ここは作り直した会社概要です');

        app(BundledPages::class)->setHidden('about', true);

        $this->get('/about')->assertOk()->assertSee('ここは作り直した会社概要です');
    }

    public function test_同梱ページ以外は非公開にできない(): void
    {
        // トップは「非公開」にしない（ページを選んで差し替える）
        $this->actingAs($this->operator())->post('/admin/sample-pages/home/hide')->assertNotFound();
        $this->actingAs($this->operator())->post('/admin/sample-pages/works/hide')->assertNotFound();
        $this->assertSame([], app(SettingsStore::class)->get(BundledPages::HIDDEN_SETTING, []));
    }

    /**
     * `/contact` はフォームで出ていても、**描く枠が見本の意匠**だと指摘され続けた。
     * フォームの中身は運用者のものなので、外側（見本の意匠）だけを外せること。
     */
    public function test_お問い合わせは見本のデザインをやめると標準の表示になり指摘が消える(): void
    {
        $this->publishedContactForm();
        /*
         * フォームはこの店のもの（項目も説明も店が決める）なので、見本の枠で描いても
         * 「同梱のサンプルサイト」の断り書きは出さない（UX 一周 2026-09-28。ページと同じく、
         * サイトの枠は店の見た目として使う）。見本のデザインをやめる入口は残す。
         */
        $this->assertStringNotContainsString('同梱のサンプルサイト', (string) $this->get('/contact')->assertOk()->getContent());

        $this->actingAs($this->operator())
            ->get('/admin/pages')
            ->assertInertia(fn (Assert $p) => $p
                ->where('samples.3.key', 'contact')
                ->where('samples.3.state', 'sample')
                ->where('samples.3.actions.0.kind', 'hide'),
            );

        $this->actingAs($this->operator())
            ->post('/admin/sample-pages/contact/hide')
            ->assertRedirect('/admin/pages#sample-contact');

        $html = (string) $this->get('/contact')->assertOk()->getContent();
        $this->assertStringNotContainsString('同梱のサンプルサイト', $html);
        // フォームそのものは出続ける（外側だけを外す）
        $this->assertStringContainsString('<form', $html);

        $findings = collect((array) $this->actingAs($this->operator())->get('/admin/readiness')->inertiaProps('findings'));
        $this->assertNull(
            $findings->first(fn (array $f): bool => $f['where'] === '/contact' && str_starts_with($f['what'], '同梱の見本')),
            '見本のデザインをやめたのに /contact がまだ見本だと指摘される',
        );

        $this->actingAs($this->operator())->post('/admin/sample-pages/contact/show')->assertRedirect();
        $this->assertStringNotContainsString('同梱のサンプルサイト', (string) $this->get('/contact')->getContent());
    }

    public function test_サイト設定を触れない人は非公開にできない(): void
    {
        $this->actingAs($this->userWithRole(Role::Editor))
            ->post('/admin/sample-pages/about/hide')
            ->assertForbidden();

        $this->assertFalse(app(BundledPages::class)->isHidden('about'));
    }

    public function test_トップをページにすると見本のトップは一覧から消える(): void
    {
        $id = $this->publishedPage('company', '会社のトップ');
        app(SettingsStore::class)->put([FrontPage::SETTING_KEY => $id]);

        $this->actingAs($this->operator())
            ->get('/admin/pages')
            ->assertInertia(fn (Assert $p) => $p
                ->where('samples', fn ($samples) => ! collect($samples)->pluck('key')->contains('home')),
            );
    }

    public function test_見本から来た新規作成はタイトルとurlが埋まっている(): void
    {
        $this->actingAs($this->operator())
            ->get('/admin/pages/create?title='.urlencode('私たちについて').'&slug=about')
            ->assertInertia(fn (Assert $p) => $p
                ->where('initial.title', '私たちについて')
                ->where('initial.slug', 'about'),
            );

        // URL に使えない値は入れない（検証は保存側が持つが、画面に変な値を出さない）
        $this->actingAs($this->operator())
            ->get('/admin/pages/create?slug=../x')
            ->assertInertia(fn (Assert $p) => $p->where('initial.slug', ''));
    }

    public function test_お問い合わせフォームの新規作成は識別子contactが埋まっている(): void
    {
        $this->actingAs($this->operator())
            ->get('/admin/forms/new?key=contact&name='.urlencode('お問い合わせ'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('preset.key', 'contact')
                ->where('preset.name', 'お問い合わせ'),
            );

        $this->actingAs($this->operator())
            ->get('/admin/forms/new?key=Bad Key')
            ->assertInertia(fn (Assert $p) => $p->where('preset', null));
    }

    private function publishedContactForm(): void
    {
        $groupId = (string) Str::ulid();

        DB::table('forms')->where('key', 'contact')->delete();
        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('forms')->insert([
            'key' => 'contact', 'name' => 'お問い合わせ', 'field_group_id' => $groupId,
            'status' => 'published', 'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function headerMenuWithAbout(): void
    {
        $menu = Menu::query()->where('key', 'header')->first();

        $menuId = $menu?->getKey() ?? DB::table('menus')->insertGetId([
            'key' => 'header', 'name' => 'ヘッダー', 'managed_by' => Menu::MANAGED_BY_ADMIN,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('menu_items')->where('menu_id', $menuId)->delete();
        DB::table('menu_items')->insert([
            'menu_id' => $menuId, 'parent_id' => null, 'sort_order' => 0,
            'title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function headerUrls(): array
    {
        // MenuTree は要求ごとに覚えるので、毎回作り直して読む
        $this->app->forgetScopedInstances();

        return array_map(
            fn (array $item): string => (string) $item['url'],
            app(MenuTree::class)->forKey('header'),
        );
    }

    private function publishedPage(string $slug, string $heading): int
    {
        $id = (int) DB::table('pages')->insertGetId([
            'slug' => $slug, 'title' => $heading, 'status' => 'published',
            'managed_by' => 'document',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = (int) DB::table('page_versions')->insertGetId([
            'page_id' => $id, 'revision' => 1, 'schema_version' => 1,
            'document' => json_encode([
                'root' => [
                    'type' => 'page',
                    'id' => (string) Str::ulid(),
                    'children' => [[
                        'type' => 'section',
                        'id' => (string) Str::ulid(),
                        'children' => [[
                            'type' => 'heading',
                            'id' => (string) Str::ulid(),
                            'props' => ['text' => $heading, 'level' => 1],
                        ]],
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_interface' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $id)->update(['published_version_id' => $version]);

        return $id;
    }
}
