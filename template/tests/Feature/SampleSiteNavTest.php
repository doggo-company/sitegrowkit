<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 同梱の見本ページも、メニュー設定のとおりに描く（WP 一周 2 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * `layouts/imported.blade.php` は About / Service / Contact を**直に書いていた**。
 * メニュー画面で並べ替えても足しても、**この見本ページだけ変わらない**。
 * 投稿一覧やページ（`layouts/public.blade.php`）には反映されるので、
 * **同じサイトの中でページによってナビが違う**状態だった。
 *
 * 実測（2 周目）: トップは `About / Service / Contact`（英語）、
 * `/blog` は `私たちについて / サービス / お問い合わせ / 投稿`（メニューのとおり）。
 * 買った人はトップを見るので「メニューが効いていない」と受け取る。
 */
final class SampleSiteNavTest extends TestCase
{
    use RefreshDatabase;

    private function headerMenu(): Menu
    {
        $menu = new Menu;
        $menu->key = 'header';
        $menu->name = 'ヘッダー';
        $menu->managed_by = Menu::MANAGED_BY_CODE;
        $menu->save();

        return $menu;
    }

    private function addLink(Menu $menu, string $title, string $url, int $order): void
    {
        $item = new MenuItem;
        $item->menu_id = (int) $menu->getKey();
        $item->fill([
            'title' => $title,
            'link_type' => 'custom',
            'link_url' => $url,
            'sort_order' => $order,
            'target' => '_self',
        ]);
        $item->save();
    }

    public function test_見本のトップがメニューのとおりに描く(): void
    {
        $menu = $this->headerMenu();
        $this->addLink($menu, '庭しごとについて', 'https://example.test/niwa', 0);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('庭しごとについて', (string) $html, 'メニューの項目が出ていない');
    }

    /**
     * **並べ替えが効くこと。** 出ているだけでは足りない —
     * 直に書いていた頃も「それらしい項目」は出ていた。
     */
    public function test_見本のトップがメニューの順で描く(): void
    {
        $menu = $this->headerMenu();
        $this->addLink($menu, 'あとに出るもの', 'https://example.test/b', 1);
        $this->addLink($menu, 'さきに出るもの', 'https://example.test/a', 0);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(
            mb_strpos($html, 'あとに出るもの'),
            mb_strpos($html, 'さきに出るもの'),
            'メニューの順で出ていない',
        );
    }

    /**
     * **訪問者にナビが無い状態を公開しない**（`<x-navigation :fallback="true">` と同じ）。
     * メニューが空でも、既定の導線へ落ちる。
     */
    public function test_メニューが空でもナビは消えない(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<nav class="gnav".*?<a /su',
            $html,
            'メニューが空のときにナビが消えている',
        );
    }
}
