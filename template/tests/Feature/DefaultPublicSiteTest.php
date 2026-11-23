<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Works\Models\Work;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 8 F-5: 導入直後の default 公開サイトが
 * 架空 client ブランド（Green Leaf Design）ではなく generic な sample identity で
 * 表示されることの regression。import 経路の検証は PublicPagesTest
 * （TemplateContentSeeder）が引き続き担当する。
 */
final class DefaultPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    public function test_default_site_uses_generic_sample_identity(): void
    {
        $home = $this->get('/')->assertOk();

        $home->assertSee('Studio Sample');
        $home->assertDontSee('Green Leaf Design');
        // sample disclosure（footer）
        $home->assertSee('サンプルサイト');

        $this->get('/about')->assertOk()->assertDontSee('Green Leaf Design');
        $this->get('/service')->assertOk()->assertDontSee('Green Leaf Design');
        $this->get('/works')->assertOk()->assertDontSee('Green Leaf Design');
        $this->get('/contact')->assertOk()->assertDontSee('Green Leaf Design');
    }

    public function test_default_site_has_no_placeholder_logo_image(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('imported/images/logo.png', $content);
        $this->assertStringContainsString('logo-text', $content);
    }

    public function test_seeded_demo_content_is_displayed(): void
    {
        $this->get('/')
            ->assertSee('コーポレートサイトを公開しました')
            ->assertSee('菓子ブランド ECサイト構築');
    }

    public function test_no_hardcoded_client_brand_in_shipped_views(): void
    {
        $files = array_merge(
            [resource_path('views/layouts/imported.blade.php')],
            glob(resource_path('views/public/*.blade.php')) ?: [],
            glob(base_path('modules/Works/views/*.blade.php')) ?: [],
            glob(base_path('modules/Contact/views/*.blade.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                'Green Leaf',
                (string) file_get_contents($file),
                basename($file).' に架空 client ブランドがハードコードされています',
            );
        }
    }

    public function test_default_demo_seeds_four_works(): void
    {
        $this->assertSame(4, Work::query()->count());
    }

    public function test_demo_media_definitions_cover_all_seeded_works(): void
    {
        // Phase 8 F-7: works の seed と demo media 定義が 1:1 でないと
        // mainImage が null の Work が出荷デフォルトに混入する
        $workSlugs = Work::query()->pluck('slug')->all();
        $mediaSlugs = array_column(DemoContentSeeder::DEMO_MEDIA, 'slug');

        $this->assertEqualsCanonicalizing($workSlugs, $mediaSlugs);
    }

    public function test_work_without_main_image_renders_no_broken_img_tag(): void
    {
        // 画像未設定は合法な CMS state — broken <img src=""> を出さないこと(F-7 Fix B)
        Work::query()->create([
            'title' => '画像なしの実績',
            'slug' => 'no-image-work',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        foreach (['/', '/works'] as $path) {
            $content = (string) $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('画像なしの実績', $content, $path);
            $this->assertStringNotContainsString('src=""', $content, $path);
            $this->assertStringNotContainsString("src=''", $content, $path);
        }
    }

    public function test_demo_work_image_is_not_flat_color(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $method = new ReflectionMethod(DemoContentSeeder::class, 'renderDemoImage');
        $image = $method->invoke(new DemoContentSeeder, [214, 138, 108]);

        // 四隅と中央のピクセルが単色でないこと（F-5C: 「未完成 placeholder」回避）
        $points = [[10, 10], [1190, 10], [10, 890], [1190, 890], [600, 450]];
        $colors = array_map(
            fn (array $p): int => imagecolorat($image, $p[0], $p[1]),
            $points,
        );
        imagedestroy($image);

        $this->assertGreaterThan(2, count(array_unique($colors)));
    }
}
