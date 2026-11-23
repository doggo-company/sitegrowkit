<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8.5-C O-10: Setup Wizard が入力されたサイト名を反映するための
 * 非対話コマンド。管理画面と同じ経路（schema 検証 → SettingsStore）を通ること、
 * schema にない key を受け付けないこと、値を素通しで保存すること（XSS は
 * 出力側の Blade escaping が担当）を固定する。
 */
final class SetSiteSettingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    public function test_seeded_default_site_name_is_studio_sample(): void
    {
        // CLI / template の既定は変更しない（Wizard だけが上書きする）
        $this->assertSame('Studio Sample', app(SettingsStore::class)->get('site_name'));
    }

    public function test_updates_site_name_through_settings_store(): void
    {
        $this->artisan('agentcms:site-setting', ['key' => 'site_name', 'value' => 'DOGGO Company'])
            ->assertExitCode(0);

        $this->assertSame('DOGGO Company', app(SettingsStore::class)->get('site_name'));
    }

    public function test_supports_japanese_and_special_characters(): void
    {
        foreach (['サンプル制作所', '株式会社 山田＆Co.', 'Café — Studio "A"'] as $name) {
            $this->artisan('agentcms:site-setting', ['key' => 'site_name', 'value' => $name])
                ->assertExitCode(0);

            $this->assertSame($name, app(SettingsStore::class)->get('site_name'));
        }
    }

    public function test_rejects_keys_that_are_not_in_the_site_schema(): void
    {
        $this->artisan('agentcms:site-setting', ['key' => 'admin_password', 'value' => 'x'])
            ->assertExitCode(1);

        $this->assertNull(app(SettingsStore::class)->get('admin_password'));
    }

    public function test_rejects_values_that_violate_the_schema(): void
    {
        // site.json: site_name は max 60
        $this->artisan('agentcms:site-setting', ['key' => 'site_name', 'value' => str_repeat('a', 61)])
            ->assertExitCode(1);

        $this->assertSame('Studio Sample', app(SettingsStore::class)->get('site_name'));
    }

    public function test_html_in_site_name_is_escaped_on_the_public_site(): void
    {
        $payload = '<script>alert(1)</script>';
        $this->artisan('agentcms:site-setting', ['key' => 'site_name', 'value' => $payload])
            ->assertExitCode(0);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee($payload, false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
