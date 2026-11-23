<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\SocialLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Media\Models\Media;
use Tests\TestCase;

/**
 * UX 一周 第 4 周（カフェの店主の目・2026-09-28）で見つけたものの回帰。
 */
final class UxWalkRound4Test extends TestCase
{
    use RefreshDatabase;

    private function media(string $alt): Media
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->post('/admin/media', ['file' => UploadedFile::fake()->image('cup.png', 400, 300), 'alt' => $alt])
            ->assertSessionHasNoErrors();

        return Media::query()->latest('id')->firstOrFail();
    }

    private function render(array $props): string
    {
        return view('components.document.image', [
            'nodeId' => 'n', 'classes' => '', 'style' => '', 'props' => $props, 'context' => null,
        ])->render();
    }

    /** 🔴 これが本体。画像ブロックに書いた代替テキストが公開面に出る */
    public function test_画像ブロックの代替テキストが公開面に出る(): void
    {
        $media = $this->media('ブレンドコーヒー');

        $html = $this->render(['mediaId' => $media->getKey(), 'alt' => '湯気の立つブレンドコーヒー']);

        $this->assertStringContainsString('alt="湯気の立つブレンドコーヒー"', $html);
    }

    public function test_装飾目的なら読み上げから外す(): void
    {
        $media = $this->media('ブレンドコーヒー');

        $html = $this->render(['mediaId' => $media->getKey(), 'alt' => '', 'decorative' => true]);

        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringNotContainsString('ブレンドコーヒー', $html);
    }

    public function test_代替テキストが空ならメディアの説明を使う(): void
    {
        $media = $this->media('ブレンドコーヒー');

        $this->assertStringContainsString('alt="ブレンドコーヒー"', $this->render(['mediaId' => $media->getKey(), 'alt' => '']));
    }

    public function test_sns_はアカウント名だけでもリンクにする(): void
    {
        $this->assertSame(
            [
                ['label' => 'X', 'href' => 'https://x.com/komorebi'],
                ['label' => 'Instagram', 'href' => 'https://www.instagram.com/komorebi_cafe'],
            ],
            SocialLinks::from(['x_url' => '@komorebi', 'instagram_url' => 'komorebi_cafe']),
        );
    }

    public function test_sns_の欄に壊れた値があればリンクを出さない(): void
    {
        $this->assertSame([], SocialLinks::from(['x_url' => 'こもれび', 'instagram_url' => 'javascript:alert(1)']));
    }

    public function test_サイト設定の_sns_が公開面のフッターに出る(): void
    {
        app(SettingsStore::class)->put(['instagram_url' => 'https://www.instagram.com/komorebi_cafe/']);

        $this->get('/_search')->assertOk()->assertSee('href="https://www.instagram.com/komorebi_cafe/"', false);
    }
}
