<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8.5-C.1 / C.2: `agentcms dev` は毎回 seeder を実行するため、
 * demo seeder が利用者の設定値を上書きしてはいけない。
 * 新規プロジェクトでは従来どおり demo 値で始まること。
 *
 * missing の判定は **key が無い / 値が null** のみ。
 * 空文字は「意図的に消した値」として保持する。
 */
final class DemoSeederSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** サイト設定の demo default（fresh project の期待値） */
    private const DEMO_DEFAULTS = [
        'site_name' => 'Studio Sample',
        'tagline' => '小さなチームのための、確かなウェブ制作',
        'seo_description' => 'Studio Sample は Web サイト制作・ブランディング・グラフィックデザインを手がけるデザインスタジオです。',
        'contact_email' => 'admin@example.test',
        'tel' => '03-0000-0000',
        'address' => '東京都渋谷区0-0-0',
        'x_url' => 'https://x.com/example',
        'instagram_url' => 'https://www.instagram.com/example/',
    ];

    private function settings(): SettingsStore
    {
        return app(SettingsStore::class);
    }

    public function test_fresh_project_gets_every_demo_default(): void
    {
        $this->seed(DemoContentSeeder::class);

        foreach (self::DEMO_DEFAULTS as $key => $expected) {
            $this->assertSame($expected, $this->settings()->get($key), "設定 [{$key}] の初期値");
        }
    }

    /**
     * @return list<array{string, string}>
     */
    public static function customisableSettings(): array
    {
        return [
            ['site_name', 'DOGGO Company'],
            ['tagline', '犬と暮らす人のためのデザイン'],
            ['seo_description', '独自のサイト説明文です。'],
            ['contact_email', 'hello@doggo-company.test'],
            ['tel', '050-0000-0000'],
            ['address', '東京都調布市0-0-0'],
            ['x_url', 'https://x.com/doggo'],
            ['instagram_url', 'https://www.instagram.com/doggo/'],
        ];
    }

    #[DataProvider('customisableSettings')]
    public function test_custom_value_survives_reseeding(string $key, string $custom): void
    {
        $this->seed(DemoContentSeeder::class);
        $this->settings()->put([$key => $custom]);

        // `agentcms dev` の再実行に相当
        $this->seed(DemoContentSeeder::class);

        $this->assertSame($custom, $this->settings()->get($key));
    }

    public function test_empty_string_is_preserved_as_user_intent(): void
    {
        $this->seed(DemoContentSeeder::class);
        // 利用者が「消した」状態（falsey だが未設定ではない）
        $this->settings()->put(['tagline' => '', 'x_url' => '']);

        $this->seed(DemoContentSeeder::class);

        $this->assertSame('', $this->settings()->get('tagline'));
        $this->assertSame('', $this->settings()->get('x_url'));
    }

    public function test_only_missing_keys_are_seeded_in_a_partial_state(): void
    {
        // 一部だけ値がある状態を作る（全体を all-or-nothing で判定しないこと）
        $this->settings()->put([
            'site_name' => 'DOGGO Company',
            'tagline' => '犬と暮らす人のためのデザイン',
            'seo_description' => null,
            'contact_email' => '',
        ]);

        $this->seed(DemoContentSeeder::class);

        $this->assertSame('DOGGO Company', $this->settings()->get('site_name'));
        $this->assertSame('犬と暮らす人のためのデザイン', $this->settings()->get('tagline'));
        // null は未設定として demo default を投入する
        $this->assertSame(self::DEMO_DEFAULTS['seo_description'], $this->settings()->get('seo_description'));
        // 空文字は保持する
        $this->assertSame('', $this->settings()->get('contact_email'));
        // 一度も触れていない項目には demo default が入る
        $this->assertSame(self::DEMO_DEFAULTS['tel'], $this->settings()->get('tel'));
    }

    public function test_reseeding_is_idempotent(): void
    {
        $this->seed(DemoContentSeeder::class);
        $first = $this->settings()->all();

        $this->seed(DemoContentSeeder::class);
        $this->seed(DemoContentSeeder::class);

        $this->assertSame($first, $this->settings()->all());
    }
}
