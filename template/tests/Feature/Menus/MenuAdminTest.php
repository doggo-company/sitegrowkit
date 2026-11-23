<?php

declare(strict_types=1);

namespace Tests\Feature\Menus;

use AgentCms\Core\Application\Menus\DeleteMenu;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * メニューの管理画面（Phase 9.9 / STAGE 22D・SPEC §F）。
 *
 * 見た目そのものは Playwright が見る。ここで固定するのは
 * 「どの状態のときに何が渡り、何が渡らないか」と、権限の境界である。
 */
final class MenuAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 一覧 ────────────────────────────────────────────────

    public function test_一覧はメニューと項目数を渡す(): void
    {
        $menu = $this->menu('header');
        $this->item($menu, ['title' => 'あ']);
        $this->item($menu, ['title' => 'い']);

        $this->actingAs($this->owner)->get('/admin/menus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/menus/Index')
                ->has('menus', 1)
                ->where('menus.0.key', 'header')
                ->where('menus.0.item_count', 2)
                ->where('canWrite', true),
            );
    }

    public function test_編集画面は木と候補を渡す(): void
    {
        $menu = $this->menu('header');
        $parent = $this->item($menu, ['title' => '親']);
        $this->item($menu, ['title' => '子', 'parent_id' => $parent]);

        $this->actingAs($this->owner)->get('/admin/menus/header')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/menus/Edit')
                ->where('menu.key', 'header')
                ->has('items', 1)
                ->has('items.0.children', 1)
                // 「どこを指せるか」は画面が調べない（公開条件を 2 か所に分けない）
                ->has('choices.pages')
                ->has('choices.contentTypes')
                ->has('choices.routes'),
            );
    }

    public function test_無いメニューは404(): void
    {
        $this->actingAs($this->owner)->get('/admin/menus/nope')->assertNotFound();
    }

    public function test_出せない項目には理由が渡る(): void
    {
        $menu = $this->menu('header');
        $this->item($menu, ['title' => '壊れた', 'link_type' => 'route', 'link_route' => 'no.such.route']);

        $this->actingAs($this->owner)->get('/admin/menus/header')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // 空でも `{}` として渡すので object で受ける（[] だと配列になる）
                ->where('unavailable', fn (mixed $reasons): bool => (array) $reasons !== []),
            );
    }

    // ── 保存 ────────────────────────────────────────────────

    public function test_木ごと保存できる(): void
    {
        $this->menu('header');

        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'name' => 'ヘッダー',
            'lock_version' => 0,
            'items' => [
                [
                    'title' => '親', 'link_type' => 'route', 'link_route' => 'about',
                    'target' => '_self',
                    'children' => [
                        ['title' => '子', 'link_type' => 'custom', 'link_url' => '/a', 'target' => '_self', 'children' => []],
                    ],
                ],
            ],
        ])->assertRedirect();

        $this->assertSame(2, MenuItem::query()->count());
        $this->assertSame(1, (int) Menu::query()->where('key', 'header')->value('lock_version'));
    }

    public function test_壊れた木は保存されない(): void
    {
        $this->menu('header');

        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'lock_version' => 0,
            'items' => [[
                // route なのに URL も持っている
                'title' => 'X', 'link_type' => 'route', 'link_route' => 'about',
                'link_url' => 'https://example.com', 'target' => '_self', 'children' => [],
            ]],
        ])->assertSessionHasErrors();

        $this->assertSame(0, MenuItem::query()->count());
    }

    public function test_古い版では保存できない(): void
    {
        $this->menu('header');

        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'lock_version' => 0,
            'items' => [$this->payload('1 回目')],
        ])->assertRedirect();

        // 版 0 のまま送り直す。断るが、開発者向けのエラー画面ではなく画面の誤りとして返す（UX 一周 6 周目 R6-3）
        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'lock_version' => 0,
            'items' => [$this->payload('2 回目')],
        ])->assertRedirect()->assertSessionHasErrors('lock_version');

        $this->assertSame('1 回目', MenuItem::query()->value('title'));
    }

    /** 説明を空にして保存すると消える（UX 一周 6 周目: 空にしても前の説明が残った） */
    public function test_説明を空にして保存すると消える(): void
    {
        $this->menu('header');

        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'description' => '上のメニュー', 'lock_version' => 0, 'items' => [],
        ])->assertRedirect();
        $this->actingAs($this->owner)->patch('/admin/menus/header', [
            'description' => '', 'lock_version' => 1, 'items' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Menu::query()->where('key', 'header')->value('description'));
    }

    // ── 削除 ────────────────────────────────────────────────

    public function test_削除は2段階(): void
    {
        $menu = $this->menu('drawer');
        $this->item($menu, ['title' => 'あ']);

        $this->actingAs($this->owner)->post('/admin/menus/drawer/delete')->assertRedirect();
        $this->assertSame(1, Menu::query()->where('key', 'drawer')->count());

        $token = (string) app(DeleteMenu::class)
            ->execute('drawer', OperationContext::admin(
                (int) $this->owner->getKey(), (string) $this->owner->name,
            ))->confirmation?->token;

        $this->actingAs($this->owner)
            ->post('/admin/menus/drawer/delete', ['confirmation_token' => $token])
            ->assertRedirect('/admin/menus');

        $this->assertSame(0, Menu::query()->where('key', 'drawer')->count());
        $this->assertSame(0, MenuItem::query()->count());
    }

    public function test_テーマが持つメニューは削除できない(): void
    {
        $this->menu('header', Menu::MANAGED_BY_CODE);

        $this->actingAs($this->owner)->post('/admin/menus/header/delete')->assertStatus(409);
        $this->assertSame(1, Menu::query()->where('key', 'header')->count());
    }

    // ── 権限 ────────────────────────────────────────────────

    public function test_管理者も編集できる(): void
    {
        $admin = User::factory()->create(['role' => Role::Administrator->value]);
        $this->menu('header');

        $this->actingAs($admin)->get('/admin/menus/header')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canWrite', true));

        $this->actingAs($admin)->patch('/admin/menus/header', [
            'lock_version' => 0,
            'items' => [$this->payload('管理者が足した')],
        ])->assertRedirect();

        $this->assertSame('管理者が足した', MenuItem::query()->value('title'));
    }

    public function test_編集者は保存できない(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $this->menu('header');

        // 読めはする（導線の確認に要る）
        $this->actingAs($editor)->get('/admin/menus/header')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canWrite', false));

        // 画面で隠すだけにしない。経路も塞ぐ
        $this->actingAs($editor)->patch('/admin/menus/header', [
            'lock_version' => 0,
            'items' => [$this->payload('編集者が足した')],
        ])->assertStatus(403);

        $this->assertSame(0, MenuItem::query()->count());
    }

    public function test_未認証では開けない(): void
    {
        $this->get('/admin/menus')->assertRedirect('/login');
        $this->patch('/admin/menus/header', [])->assertRedirect('/login');
    }

    // ── 画面の作法 ──────────────────────────────────────────

    public function test_管理画面のroute数と動詞(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'admin/menu'),
        ));

        $actual = array_map(
            static fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $routes,
        );

        sort($actual);

        $this->assertSame([
            'GET admin/menu-entries/{type}',
            'GET admin/menus',
            'GET admin/menus/{key}',
            'PATCH admin/menus/{key}',
            'POST admin/menus/{key}/delete',
        ], $actual);

        // 破壊的操作に DELETE 動詞を使わない（既存 admin route と同じ作法）
        foreach ($routes as $route) {
            $this->assertNotContains('DELETE', $route->methods());
        }
    }

    public function test_画面はブラウザのconfirmもv_htmlも使わない(): void
    {
        foreach (['js/pages/admin/menus/Index.vue', 'js/pages/admin/menus/Edit.vue'] as $rel) {
            $source = self::withoutComments((string) file_get_contents(resource_path($rel)));

            $this->assertDoesNotMatchRegularExpression('/(^|[^A-Za-z])confirm\s*\(/m', $source, $rel);
            $this->assertStringNotContainsString('window.confirm', $source, $rel);
            $this->assertStringNotContainsString('v-html', $source, $rel);
        }
    }

    public function test_並べ替えはボタンでできる(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/admin/menus/Edit.vue'));

        // Drag & Drop を唯一の手段にしない（キーボードだけで完了できること）
        foreach (['menu-up-', 'menu-down-', 'menu-indent-', 'menu-outdent-'] as $testId) {
            $this->assertStringContainsString($testId, $source, "{$testId} が無い");
        }
    }

    // ── helpers ────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(string $title): array
    {
        return [
            'title' => $title, 'link_type' => 'custom', 'link_url' => 'https://example.com',
            'target' => '_self', 'children' => [],
        ];
    }

    private function menu(string $key, string $managedBy = Menu::MANAGED_BY_ADMIN): Menu
    {
        $id = DB::table('menus')->insertGetId([
            'key' => $key, 'name' => $key, 'managed_by' => $managedBy,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Menu::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function item(Menu $menu, array $attributes): int
    {
        return (int) DB::table('menu_items')->insertGetId($attributes + [
            'menu_id' => $menu->getKey(),
            'parent_id' => null,
            'sort_order' => 0,
            'link_type' => 'custom',
            'link_url' => 'https://example.com',
            'target' => '_self',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** コメントを落とす（動く側だけを見る） */
    private static function withoutComments(string $source): string
    {
        return (string) preg_replace(
            ['#/\*[\s\S]*?\*/#', '#(^|[^:])//[^\n]*#m', '#<!--[\s\S]*?-->#', '#\{\{--[\s\S]*?--\}\}#'],
            ['', '$1', '', ''],
            $source,
        );
    }
}
