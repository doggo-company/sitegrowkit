<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Support\SiteBrandImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Media\Models\Media;
use Tests\TestCase;

/** 設定したロゴが同梱テーマと標準公開ページの双方へ届き、非公開画像を漏らさない。 */
final class SiteBrandImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ロゴとアイコンが両方の公開レイアウトへ反映される(): void
    {
        $logo = $this->image('company-logo.png');
        $icon = $this->image('company-icon.png');
        app(SettingsStore::class)->put(['site_name' => '会社 "<script>', 'site_logo_id' => $logo->getKey(), 'site_icon_id' => $icon->getKey()]);

        DB::table('news')->insert(['title' => '確認', 'slug' => 'brand-test', 'body' => '<p>本文</p>', 'status' => 'published', 'published_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);

        foreach (['/', '/news/brand-test'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee('class="site-brand__image"', false)->assertSee('company-logo.png', false)
                ->assertSee('alt="会社 &quot;&lt;script&gt;"', false)->assertSee('company-icon.png', false)
                ->assertDontSee('alt="会社 "<script>', false);
        }
    }

    public function test_非公開や添付ファイルを設定値から直接指定しても表示しない(): void
    {
        $private = $this->image('private-logo.png');
        $private->forceFill(['visibility' => 'private'])->save();
        $file = $this->image('attachment.png');
        $file->forceFill(['kind' => 'file'])->save();
        app(SettingsStore::class)->put(['site_logo_id' => $private->getKey(), 'site_icon_id' => $file->getKey()]);
        $this->get('/')->assertOk()->assertDontSee('private-logo.png', false)->assertDontSee('attachment.png', false)->assertDontSee('class="site-brand__image"', false);
    }

    public function test_未設定または削除済みならサイト名へ戻る(): void
    {
        $this->assertSame(['logo' => null, 'icon' => null], app(SiteBrandImages::class)->resolve(['site_logo_id' => 99999]));
        app(SettingsStore::class)->put(['site_name' => 'ロゴなしの会社']);
        $this->get('/')->assertOk()->assertSee('ロゴなしの会社')->assertDontSee('class="site-brand__image"', false);
    }

    private function image(string $path): Media
    {
        return Media::query()->forceCreate(['name' => 'ロゴ', 'path' => 'media/'.$path, 'mime' => 'image/png', 'size' => 10, 'width' => 480, 'height' => 96, 'kind' => 'image', 'visibility' => 'public']);
    }
}
