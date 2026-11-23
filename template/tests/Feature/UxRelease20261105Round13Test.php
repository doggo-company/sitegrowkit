<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Menus\Menu;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 公開前の UX 一周 13 周目（2026-11-05・こども英語と書道の教室・ブラウザの「戻る」をよく使う・rc17）の回帰。
 *
 * - R13-1・R13-2・R12-2 残り: ブラウザの「戻る」で古い画面が出る。管理画面の応答は
 *   ブラウザに残させない（Cache-Control: no-store。bfcache に入れない）
 * - R13-3: メニューが指しているページをゴミ箱へ移すと、メニューの項目が黙って公開から消えた
 * - R4-6: タブレットでもフォームのブロックが「右側の『配置するフォーム』」と言った
 */
final class UxRelease20261105Round13Test extends TestCase
{
    use RefreshDatabase;

    public function test_管理画面の応答はブラウザに残させない(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        foreach (['/admin/pages', '/dashboard'] as $path) {
            $cache = (string) $this->actingAs($admin)->get($path)->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache, "{$path} が no-store でない: {$cache}");
        }
    }

    public function test_公開サイトの応答には_no_store_を付けない(): void
    {
        $cache = (string) $this->get('/')->headers->get('Cache-Control');
        $this->assertStringNotContainsString('no-store', $cache);
    }

    public function test_メニューが指しているページをゴミ箱へ移す前に知らせる(): void
    {
        $this->seed(MenuSeeder::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $context = OperationContext::admin($admin->getKey(), 'fixture');

        $created = app(CreatePage::class)->execute(['title' => '先生の紹介', 'slug' => 'sensei'], $context);
        $this->assertTrue($created->isSuccess(), (string) $created->message);
        $pageId = (int) $created->data['page']['id'];

        foreach (['header', 'footer'] as $key) {
            DB::table('menu_items')->insert([
                'menu_id' => Menu::query()->where('key', $key)->value('id'),
                'sort_order' => 99,
                'title' => null,
                'link_type' => 'page',
                'page_id' => $pageId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $result = app(TrashPage::class)->execute($pageId, $context);
        $impact = implode("\n", (array) ($result->confirmation?->toArray()['impact'] ?? []));

        $this->assertStringContainsString('ヘッダー', $impact);
        $this->assertStringContainsString('フッター', $impact);
        $this->assertStringContainsString('メニュー', $impact);
    }

    public function test_メニューに無いページのゴミ箱の確認はいままでどおり(): void
    {
        $this->seed(MenuSeeder::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $context = OperationContext::admin($admin->getKey(), 'fixture');
        $created = app(CreatePage::class)->execute(['title' => '単独', 'slug' => 'solo'], $context);

        $result = app(TrashPage::class)->execute((int) $created->data['page']['id'], $context);
        $impact = (array) ($result->confirmation?->toArray()['impact'] ?? []);

        $this->assertSame(['公開サイトから見えなくなります', 'ゴミ箱からは元に戻せます'], $impact);
    }

    public function test_フォームのブロックの案内は右側と言わない(): void
    {
        $blade = (string) file_get_contents(resource_path('views/components/document/form.blade.php'));

        $this->assertStringNotContainsString('右側の', $blade);
        $this->assertStringContainsString('配置するフォーム', $blade);
    }
}
