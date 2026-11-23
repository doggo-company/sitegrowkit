<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Menus\GetMenu;
use AgentCms\Core\Application\Menus\SaveMenu;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Menus\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Round3MenuCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_controller_supplies_saved_header_and_copy_is_saved_as_new_rows(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $context = OperationContext::admin($owner->id, 'tester');
        foreach (['header', 'footer'] as $key) {
            (new Menu)->forceFill(['key' => $key, 'name' => $key, 'lock_version' => 0])->save();
        }
        $saved = app(SaveMenu::class)->execute('header', ['name' => 'ヘッダー'], [
            ['title' => '親', 'link_type' => 'custom', 'link_url' => '/one', 'target' => '_self', 'children' => [
                ['title' => '子', 'link_type' => 'custom', 'link_url' => '/two', 'target' => '_self', 'children' => []],
            ]],
        ], $context, 0);
        $this->assertTrue($saved->isSuccess(), (string) json_encode($saved->errors));
        $this->actingAs($owner)->get('/admin/menus/footer?copy=header')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('copyHeader', true)
                ->where('headerItems.0.link_url', '/one')->where('headerItems.0.children.0.link_url', '/two')
                ->has('items', 0)->where('hasFooter', true));
        // 読み込むだけではフッターを変えない。
        $this->assertSame([], app(GetMenu::class)->execute('footer', $context)->data['items']);
        $header = app(GetMenu::class)->execute('header', $context)->data['items'];
        $copy = function (array $items) use (&$copy): array {
            return array_map(fn (array $item): array => array_merge($item, ['id' => null, 'children' => $copy($item['children'])]), $items);
        };
        $result = app(SaveMenu::class)->execute('footer', ['name' => 'フッター'], $copy($header), $context, 0);
        $this->assertTrue($result->isSuccess(), (string) json_encode($result->errors));
        $footer = app(GetMenu::class)->execute('footer', $context)->data['items'];
        $this->assertSame('/one', $footer[0]['link_url']);
        $this->assertSame('/two', $footer[0]['children'][0]['link_url']);
        $this->assertNotSame($header[0]['id'], $footer[0]['id']);
        $this->assertNotSame($header[0]['children'][0]['id'], $footer[0]['children'][0]['id']);
    }

    public function test_read_only_user_cannot_start_copy_from_query(): void
    {
        foreach (['header', 'footer'] as $key) {
            (new Menu)->forceFill(['key' => $key, 'name' => $key, 'lock_version' => 0])->save();
        }
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/admin/menus/footer?copy=header')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWrite', false)->where('copyHeader', false));
    }
}
