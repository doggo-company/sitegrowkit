<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 同梱の見本サイト（`layouts/imported.blade.php`）が、メニューの **2 階層目**を描く（R03 / R05・2026-09-09）。
 *
 * ## 何が起きていたか
 *
 * メニュー画面は 2 階層まで入れ子にできる（`MenuTreeValidator::MAX_DEPTH`）し、
 * `MenuTree::forKey()` は `children` を返している。ところがこの layout は
 * 1 階層目しか描いておらず、**入れ子にした子が見本サイトでは 1 つも出なかった**。
 * `layouts/public.blade.php`（`<x-navigation>`）では出るので、同じサイトの中で
 * ページによって導線が違っていた。
 *
 * ## ここで固定すること
 *
 * - 子が親の `<li>` の中の `<ul class="gnav__sub">` に出る（ヘッダー・フッター両方）
 * - キーボード / タッチから届く形（開閉ボタンに `aria-expanded` / `aria-controls`）
 * - `aria-current` / `target` / `rel` は `components/navigation.blade.php` と同じ付け方
 * - 出せない子（ゴミ箱のページ）は出ない — 判定は `MenuTree` に任せる
 */
final class ImportedNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ヘッダーの子が親の中の入れ子リストに出る(): void
    {
        $parent = $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('header', ['title' => '庭の手入れ', 'link_type' => 'custom', 'link_url' => '/service#care', 'parent_id' => $parent]);

        $html = $this->norm($this->home());

        $this->assertStringContainsString(
            '<li class="gnav__item gnav__item--has-children"><a href="/service">サービス</a>'
            .'<button class="gnav__toggle" type="button" aria-expanded="false" aria-controls="gnav-sub-'.$parent.'">'
            .'<span class="visually-hidden">サービスのサブメニュー</span></button>'
            .'<ul class="gnav__sub" id="gnav-sub-'.$parent.'"><li class="gnav__sub-item"><a href="/service#care">庭の手入れ</a></li></ul></li>',
            $html,
            '子が親の <li> の中の gnav__sub に出ていない',
        );
    }

    /** ホバーだけにしない — 開閉ボタンが子リストを指す */
    public function test_開閉ボタンが子リストを指す(): void
    {
        $parent = $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('header', ['title' => '子', 'link_type' => 'route', 'link_route' => 'about', 'parent_id' => $parent]);

        $html = $this->home();

        $this->assertStringContainsString(
            '<button class="gnav__toggle" type="button" aria-expanded="false" aria-controls="gnav-sub-'.$parent.'">',
            $html,
        );
        $this->assertStringContainsString('サービスのサブメニュー', $html);
        // 開閉の script は同一オリジンの外部ファイル（inline JS を足して CSP を緩めない）
        $this->assertStringContainsString('imported/js/gnav-submenu.js', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    /** 子が無い項目には、ボタンも空の <ul> も出さない */
    public function test_子が無ければ入れ子は出ない(): void
    {
        $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);

        $html = $this->home();

        $this->assertStringContainsString('<li class="gnav__item"><a href="/service">サービス</a></li>', $this->norm($html));
        $this->assertStringNotContainsString('gnav__sub', $html);
        $this->assertStringNotContainsString('gnav__toggle', $html);
    }

    /** aria-current は子にも付き、子が現在なら親にも付く（`navigation.blade.php` と同じ） */
    public function test_子の現在地がariaで分かる(): void
    {
        $parent = $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('header', ['title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about', 'parent_id' => $parent]);

        $html = $this->norm($this->home('/about'));

        $this->assertStringContainsString('<a href="/service" aria-current="page">サービス</a>', $html);
        $this->assertStringContainsString('<a href="/about" aria-current="page">私たちについて</a>', $html);
    }

    /** target / rel は子にも同じ付け方 */
    public function test_外部リンクの子にtargetとrelが付く(): void
    {
        $parent = $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('header', [
            'title' => '外部', 'link_type' => 'custom', 'link_url' => 'https://example.com/x',
            'target' => '_blank', 'parent_id' => $parent,
        ]);

        $html = $this->norm($this->home());

        $this->assertStringContainsString(
            '<li class="gnav__sub-item"><a href="https://example.com/x" target="_blank" rel="noopener">外部</a></li>',
            $html,
        );
    }

    /** 🔴 出せない子は出ない。判定は `MenuTree` のもの（ここで 2 つ目の判定を作らない） */
    public function test_ゴミ箱のページを指す子は出ない(): void
    {
        $page = $this->page('company');
        $parent = $this->item('header', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('header', ['title' => '消える子', 'link_type' => 'page', 'page_id' => $page, 'parent_id' => $parent]);

        $this->assertStringContainsString('消える子', $this->home());

        DB::table('pages')->where('id', $page)->update(['trashed_at' => now()]);

        $html = $this->home();

        $this->assertStringNotContainsString('消える子', $html);
        $this->assertStringNotContainsString('gnav__sub', $html, '子が 0 になったのに入れ子の器が残っている');
    }

    public function test_フッターの子も入れ子リストに出る(): void
    {
        $parent = $this->item('footer', ['title' => 'サービス', 'link_type' => 'route', 'link_route' => 'service']);
        $this->item('footer', ['title' => 'フッターの子', 'link_type' => 'route', 'link_route' => 'about', 'parent_id' => $parent]);

        $html = $this->norm($this->home());

        $this->assertStringContainsString(
            '<ul class="footer-nav"><li class="footer-nav__item"><a href="/service">サービス</a>'
            .'<ul class="footer-nav__sub"><li class="footer-nav__sub-item"><a href="/about">フッターの子</a></li></ul></li></ul>',
            $html,
        );
    }

    /** 既定の導線（メニューが空）には children が無い。落ちずに 1 階層で出る */
    public function test_メニューが空でも壊れない(): void
    {
        $html = $this->norm($this->home());

        $this->assertStringContainsString('<nav class="gnav" id="global-nav" aria-label="グローバル"><ul><li class="gnav__item"><a href="/about">私たちについて</a></li>', $html);
        $this->assertStringNotContainsString('gnav__sub', $html);
    }

    // ── helpers ────────────────────────────────────────────

    /** 次の要求を模す（`MenuTree` の memo は scoped なので、忘れさせてから引く） */
    private function home(string $path = '/'): string
    {
        app()->forgetScopedInstances();

        return (string) $this->get($path)->assertOk()->getContent();
    }

    /**
     * 属性の間の空白を畳む。Blade の `@if … @endif` は付かなかった属性のぶんの空白を残す
     * （`<a href="/x"  >`）。見ているのは属性と入れ子であって空白ではない
     */
    private function norm(string $html): string
    {
        return (string) preg_replace(['/\s{2,}/', '/\s+>/', '/>\s+</'], [' ', '>', '><'], $html);
    }

    /** @param  array<string, mixed>  $attributes */
    private function item(string $menuKey, array $attributes): int
    {
        $menuId = DB::table('menus')->where('key', $menuKey)->value('id')
            ?? DB::table('menus')->insertGetId([
                'key' => $menuKey, 'name' => $menuKey, 'managed_by' => 'code',
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('menu_items')->insertGetId($attributes + [
            'menu_id' => $menuId,
            'parent_id' => null,
            'sort_order' => (int) DB::table('menu_items')->where('menu_id', $menuId)->count(),
            'link_type' => 'custom',
            'target' => '_self',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 公開版を持つページ（`MenuRenderTest` と同じ作り方） */
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
}
