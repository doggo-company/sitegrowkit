<?php

declare(strict_types=1);

namespace Tests\Feature\Menus;

use AgentCms\Core\Application\Menus\DeleteMenu;
use AgentCms\Core\Application\Menus\GetMenu;
use AgentCms\Core\Application\Menus\ListMenus;
use AgentCms\Core\Application\Menus\SaveMenu;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * メニューの storage と Application Service（Phase 9.9 / STAGE 22B）。
 *
 * ここで固定するのは **保存される形**と**保存の手順**である。
 * 公開面の描画（URL の導出・リンク切れの除外）は 22C の担当で、ここでは見ない。
 */
final class MenuStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    // ── 表の形 ─────────────────────────────────────────────

    public function test_表と索引ができている(): void
    {
        $this->assertTrue(Schema::hasTable('menus'));
        $this->assertTrue(Schema::hasTable('menu_items'));

        $indexes = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('menu_items'),
        );

        // 逆引きできないと、ゴミ箱へ入れるときに警告が出せない
        $this->assertContains('idx_menu_item_tree', $indexes);
        $this->assertContains('idx_menu_item_page', $indexes);
        $this->assertContains('idx_menu_item_content', $indexes);

        $menuIndexes = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('menus'),
        );

        $this->assertContains('uq_menu_key', $menuIndexes);
    }

    public function test_downは項目が残っていれば止まる(): void
    {
        $menu = $this->menu();
        MenuItem::query()->insert($this->row($menu, ['title' => 'のこる']));

        $this->assertThrows(fn () => $this->migration()->down(), \RuntimeException::class);

        // **1 つも落ちていない**
        $this->assertTrue(Schema::hasTable('menu_items'));
        $this->assertTrue(Schema::hasTable('menus'));
    }

    public function test_downは空なら通って戻せる(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('menu_items'));
        $this->assertFalse(Schema::hasTable('menus'));

        // 片道にしない
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('menus'));
        $this->assertTrue(Schema::hasTable('menu_items'));
    }

    public function test_keyは重複できない(): void
    {
        $this->menu('header');

        $this->assertThrows(
            fn () => $this->menu('header'),
            QueryException::class,
        );
    }

    // ── 読み ────────────────────────────────────────────────

    public function test_一覧は項目数を添えて返す(): void
    {
        $menu = $this->menu('header');
        MenuItem::query()->insert($this->row($menu, ['title' => 'あ']));
        MenuItem::query()->insert($this->row($menu, ['title' => 'い']));
        $this->menu('footer');

        $result = app(ListMenus::class)->execute($this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->meta['count']);
        $this->assertSame('footer', $result->data['menus'][0]['key']);
        $this->assertSame(0, $result->data['menus'][0]['item_count']);
        $this->assertSame(2, $result->data['menus'][1]['item_count']);
    }

    public function test_無いキーはnot_foundになる(): void
    {
        $result = app(GetMenu::class)->execute('nope', $this->context());

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }

    public function test_木は親子と並び順のとおりに返る(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [
            $this->item(['title' => '親 B']),
            $this->item(['title' => '親 A', 'children' => [
                $this->item(['title' => '子 1']),
                $this->item(['title' => '子 2']),
            ]]),
        ]);

        $result = app(GetMenu::class)->execute('header', $this->context());
        $items = $result->data['items'];

        // 送った順がそのまま並び順になる（並べ替えは送る側が決める）
        $this->assertSame(['親 B', '親 A'], array_column($items, 'title'));
        $this->assertSame(['子 1', '子 2'], array_column($items[1]['children'], 'title'));
        $this->assertSame([], $items[0]['children']);
        $this->assertSame(4, $result->meta['total']);
    }

    // ── 保存 ────────────────────────────────────────────────

    public function test_木ごと差し替えても既存のidは残る(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [$this->item(['title' => '最初'])]);

        $id = (int) MenuItem::query()->value('id');

        // 同じ項目を id つきで送り直す
        $this->save($menu, [$this->item(['id' => $id, 'title' => '書き換えた'])], lockVersion: 1);

        $this->assertSame(1, MenuItem::query()->count());
        $this->assertSame($id, (int) MenuItem::query()->value('id'), 'id が作り直されている');
        $this->assertSame('書き換えた', MenuItem::query()->value('title'));
    }

    public function test_送られてこなかった項目は消える(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [
            $this->item(['title' => 'のこす']),
            $this->item(['title' => 'けす']),
        ]);

        $keep = (int) MenuItem::query()->where('title', 'のこす')->value('id');

        $this->save($menu, [$this->item(['id' => $keep, 'title' => 'のこす'])], lockVersion: 1);

        $this->assertSame(['のこす'], MenuItem::query()->pluck('title')->all());
    }

    public function test_親を消しても残す子は消えない(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [
            $this->item(['title' => '親', 'children' => [$this->item(['title' => '子'])]]),
        ]);

        $child = (int) MenuItem::query()->where('title', '子')->value('id');

        // 親を送らず、子だけを root として送る（cascade で巻き添えにしない）
        $this->save($menu, [$this->item(['id' => $child, 'title' => '子'])], lockVersion: 1);

        $this->assertSame(['子'], MenuItem::query()->pluck('title')->all());
        $this->assertNull(MenuItem::query()->value('parent_id'));
    }

    public function test_並び順は0から詰めて振り直す(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [
            $this->item(['title' => 'あ']),
            $this->item(['title' => 'い']),
            $this->item(['title' => 'う']),
        ]);

        $this->assertSame(
            [0, 1, 2],
            MenuItem::query()->orderBy('sort_order')->pluck('sort_order')->map(intval(...))->all(),
        );
    }

    public function test_種類を変えると前の参照は消える(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [$this->item([
            'title' => '外部', 'link_type' => 'custom', 'link_url' => 'https://example.com',
        ])]);

        $id = (int) MenuItem::query()->value('id');

        $this->save($menu, [[
            'id' => $id, 'title' => 'ホーム', 'link_type' => 'route',
            'link_route' => 'home', 'target' => '_self', 'children' => [],
        ]], lockVersion: 1);

        $row = MenuItem::query()->first();

        $this->assertSame('route', $row->link_type);
        $this->assertSame('home', $row->link_route);
        // 前の種類の値が残らない（同じことを 2 通りで持たない）
        $this->assertNull($row->link_url);
    }

    // ── 検査 ────────────────────────────────────────────────

    public function test_3階層目は受け付けない(): void
    {
        $menu = $this->menu('header');

        $result = $this->attempt($menu, [
            $this->item(['title' => '親', 'children' => [
                $this->item(['title' => '子', 'children' => [
                    $this->item(['title' => '孫']),
                ]]),
            ]]),
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('items.0.children.0.children', $result->errors);
        $this->assertSame(0, MenuItem::query()->count(), '弾いたのに書かれている');
    }

    public function test_使わない列に値が入っていたら弾く(): void
    {
        $menu = $this->menu('header');
        $page = $this->page();

        $result = $this->attempt($menu, [[
            'title' => 'ページ', 'link_type' => 'page', 'page_id' => $page,
            // page なのに URL も持っている = 同じことを 2 通りで持っている
            'link_url' => 'https://example.com', 'target' => '_self', 'children' => [],
        ]]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('items.0.link_url', $result->errors);
    }

    public function test_javascriptのurlは通さない(): void
    {
        $menu = $this->menu('header');

        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'JavaScript:alert(1)'] as $url) {
            $result = $this->attempt($menu, [$this->item([
                'title' => '罠', 'link_type' => 'custom', 'link_url' => $url,
            ])]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $url);
            $this->assertArrayHasKey('items.0.link_url', $result->errors, $url);
        }
    }

    public function test_安全なurlは通す(): void
    {
        $menu = $this->menu('header');

        // mailto: と tel: は Owner 追加決定で許可（フッターの導線に要る）
        foreach ([
            'https://example.com/a', 'http://example.com', '/works', '#main',
            'mailto:info@example.com', 'tel:+81-3-1234-5678',
        ] as $url) {
            $result = $this->attempt($menu, [$this->item([
                'title' => 'リンク', 'link_type' => 'custom', 'link_url' => $url,
            ])], lockVersion: (int) Menu::query()->whereKey($menu->getKey())->value('lock_version'));

            $this->assertTrue($result->isSuccess(), $url.' が通らない: '.json_encode($result->errors));
        }
    }

    public function test_名前を作れない種類では表示名が必須(): void
    {
        $menu = $this->menu('header');

        $result = $this->attempt($menu, [[
            'title' => null, 'link_type' => 'route', 'link_route' => 'home',
            'target' => '_self', 'children' => [],
        ]]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('items.0.title', $result->errors);
    }

    public function test_名前を作れる種類では表示名を省略できる(): void
    {
        $menu = $this->menu('header');
        $page = $this->page();

        $result = $this->attempt($menu, [[
            'title' => null, 'link_type' => 'page', 'page_id' => $page,
            'target' => '_self', 'children' => [],
        ]]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors));
        $this->assertNull(MenuItem::query()->value('title'));
    }

    public function test_存在しない参照は弾く(): void
    {
        $menu = $this->menu('header');

        $cases = [
            ['link_type' => 'route', 'link_route' => 'no.such.route', 'key' => 'items.0.link_route'],
            ['link_type' => 'page', 'page_id' => 999999, 'key' => 'items.0.page_id'],
            ['link_type' => 'content_index', 'content_type' => 'nope', 'key' => 'items.0.content_type'],
            ['link_type' => 'content', 'content_type' => 'post', 'content_id' => 999999, 'key' => 'items.0.content_id'],
        ];

        foreach ($cases as $case) {
            $key = $case['key'];
            unset($case['key']);

            $result = $this->attempt($menu, [$case + ['title' => 'X', 'target' => '_self', 'children' => []]]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $key);
            $this->assertArrayHasKey($key, $result->errors, $key);
        }
    }

    public function test_ゴミ箱にある投稿でも指定できる(): void
    {
        $menu = $this->menu('header');

        $id = DB::table('post')->insertGetId([
            'title' => 'ゴミ箱の投稿', 'slug' => 'trashed-post', 'body' => '<p>本文</p>',
            'status' => 'draft', 'deleted_at' => now(), 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 消えてはいないので保存は通る。公開面から落とすのは読むときの仕事（OG4）
        $result = $this->attempt($menu, [[
            'title' => 'ゴミ箱の投稿', 'link_type' => 'content',
            'content_type' => 'post', 'content_id' => $id, 'target' => '_self', 'children' => [],
        ]]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors));
    }

    public function test_ほかのメニューの項目は横取りできない(): void
    {
        $header = $this->menu('header');
        $footer = $this->menu('footer');

        $this->save($footer, [$this->item(['title' => 'フッターの項目'])]);
        $stolen = (int) MenuItem::query()->value('id');

        $result = $this->attempt($header, [$this->item(['id' => $stolen, 'title' => '横取り'])]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('items', $result->errors);
        $this->assertSame('フッターの項目', MenuItem::query()->whereKey($stolen)->value('title'));
    }

    // ── 版 ──────────────────────────────────────────────────

    public function test_版を送らなければ保存できない(): void
    {
        $menu = $this->menu('header');

        $result = app(SaveMenu::class)->execute(
            $menu->key, [], [$this->item(['title' => 'あ'])], $this->context(),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('lock_version', $result->errors);
    }

    public function test_古い版では保存できない(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [$this->item(['title' => '1 回目'])]);

        // 版 0 のまま送り直す（誰かが先に保存している）
        $result = $this->attempt($menu, [$this->item(['title' => '2 回目'])], lockVersion: 0);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame('1 回目', MenuItem::query()->value('title'), '競合したのに書かれている');
    }

    public function test_保存すると版が1つ進む(): void
    {
        $menu = $this->menu('header');

        $this->save($menu, [$this->item(['title' => 'あ'])]);

        $this->assertSame(1, (int) Menu::query()->whereKey($menu->getKey())->value('lock_version'));
    }

    public function test_keyとmanaged_byはこの経路で変えられない(): void
    {
        $menu = $this->menu('header');

        $result = app(SaveMenu::class)->execute(
            $menu->key,
            ['key' => 'hacked', 'managed_by' => 'code'],
            [],
            $this->context(),
            0,
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('key', $result->errors);
        $this->assertArrayHasKey('managed_by', $result->errors);
        $this->assertSame('header', Menu::query()->whereKey($menu->getKey())->value('key'));
    }

    // ── 削除 ────────────────────────────────────────────────

    public function test_削除は確認を取ってから消える(): void
    {
        $menu = $this->menu('drawer');
        $this->save($menu, [$this->item(['title' => 'あ', 'children' => [$this->item(['title' => 'い'])]])]);

        $first = app(DeleteMenu::class)->execute('drawer', $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        // 何件消えるかを必ず出す（戻せないので）
        $this->assertStringContainsString('2 件', implode(' ', $first->confirmation?->impact ?? []));
        $this->assertSame(1, Menu::query()->where('key', 'drawer')->count());

        $token = (string) $first->confirmation?->token;
        $second = app(DeleteMenu::class)->execute(
            'drawer', $this->context()->withConfirmationToken($token),
        );

        $this->assertTrue($second->isSuccess());
        $this->assertSame(0, Menu::query()->where('key', 'drawer')->count());
        // 項目は FK の cascade で落ちる（木の一部だけ残らない）
        $this->assertSame(0, MenuItem::query()->count());
    }

    public function test_テーマが持つメニューは消せない(): void
    {
        $menu = $this->menu('header', managedBy: Menu::MANAGED_BY_CODE);

        $result = app(DeleteMenu::class)->execute('header', $this->context());

        // 確認すら出さない（出すと「押せば消える」と読める）
        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNull($result->confirmation);
        $this->assertSame(1, Menu::query()->whereKey($menu->getKey())->count());
    }

    public function test_書き込みは監査に残る(): void
    {
        $menu = $this->menu('header');
        $this->save($menu, [$this->item(['title' => 'あ'])]);

        $row = DB::table('audit_logs')->where('operation', 'menu.save')->first();

        $this->assertNotNull($row);
        $this->assertSame('menu', $row->resource_type);
        $this->assertSame('header', $row->resource_id);
    }

    // ── helpers ────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function menu(string $key = 'header', string $managedBy = Menu::MANAGED_BY_ADMIN): Menu
    {
        $id = DB::table('menus')->insertGetId([
            'key' => $key, 'name' => $key, 'managed_by' => $managedBy,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Menu::query()->findOrFail($id);
    }

    private function page(string $slug = 'about-us'): int
    {
        return (int) DB::table('pages')->insertGetId([
            'slug' => $slug, 'title' => '会社概要', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'title' => '項目',
            'link_type' => 'custom',
            'link_url' => 'https://example.com',
            'target' => '_self',
            'children' => [],
        ];
    }

    /** @return array<string, mixed> 1 行分（生 insert 用） */
    private function row(Menu $menu, array $overrides = []): array
    {
        return $overrides + [
            'menu_id' => $menu->getKey(), 'parent_id' => null, 'sort_order' => 0,
            'title' => '項目', 'link_type' => 'custom', 'link_url' => 'https://example.com',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @param  list<mixed>  $items */
    private function save(Menu $menu, array $items, int $lockVersion = 0): void
    {
        $result = $this->attempt($menu, $items, $lockVersion);

        $this->assertTrue($result->isSuccess(), '保存できていない: '.json_encode($result->errors, JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<mixed>  $items */
    private function attempt(Menu $menu, array $items, int $lockVersion = 0): Result
    {
        return app(SaveMenu::class)->execute($menu->key, [], $items, $this->context(), $lockVersion);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('core/database/migrations/2026_08_19_000010_create_menu_tables.php');

        return $migration;
    }
}
