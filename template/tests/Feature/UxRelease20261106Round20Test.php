<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\Reading\TitleReading;
use AgentCms\Core\Redirects\RedirectRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開前の UX 一周 20 周目（2026-11-06・rc25 の採点 round20-grade.md）の回帰。
 *
 * - R1: 置き換え表（定番のページ名 → 英語）が題名の一部に当たり、地名・名字が消えた（吉祥寺高橋トリミングサロン → trimming）
 * - R2: カタカナ語が、英語になったり音になったりした（トリミング → trimming・ネイルサロン → neirusaron）
 * - 公開中のページの URL を変えたら、前の URL から新しい URL へ 301
 */
final class UxRelease20261106Round20Test extends TestCase
{
    use RefreshDatabase;

    public function test_置き換え表は題名がその語だけのとき(): void
    {
        $this->assertSame('kichijouji-takahashi-trimming-salon', TitleReading::slug('吉祥寺高橋トリミングサロン'));
        $this->assertSame('price', TitleReading::slug('料金表'));
        $this->assertSame('ryoukin-no-meyasu', TitleReading::slug('料金のめやす'));
        // 定番名＋ありふれた付け足しは定番名の英語（21 周目 Y1。20 周目は access-houhou だった）
        $this->assertSame('access', TitleReading::slug('アクセス方法'));
    }

    public function test_カタカナ語は英語の表記にそろえる_表に無いものは音(): void
    {
        $this->assertSame('nail-salon', TitleReading::slug('ネイルサロン'));
        $this->assertSame('pet-hotel', TitleReading::slug('ペットホテル'));
        $this->assertSame('trimming', TitleReading::slug('トリミング'));
        $this->assertSame('toy-poodle-no-cut', TitleReading::slug('トイプードルのカット'));
        // 翻訳はしない（パンを bread にしない）。英語の元の語が無い語は音
        $this->assertSame('yokohama-motomachi-sei-pan-koubou', TitleReading::slug('横浜元町製パン工房'));
    }

    public function test_公開中のページのurlを変えたら前のurlから301(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金のめやす', 'slug' => 'ryoukin'])->assertRedirect();
        $page = Page::query()->where('slug', 'ryoukin')->firstOrFail();
        Page::query()->whereKey($page->getKey())->update(['status' => PageStatus::Published->value, 'published_at' => now()->subMinute()]);

        $this->actingAs($admin)->patch("/admin/pages/{$page->getKey()}", ['title' => '料金のめやす', 'slug' => 'price'])->assertRedirect();
        $this->get('/ryoukin')->assertStatus(301)->assertRedirect('/price');

        // もう一度変えても、転送は 2 段にならない（/ryoukin → /fees に付け替わる）
        $this->actingAs($admin)->patch("/admin/pages/{$page->getKey()}", ['title' => '料金のめやす', 'slug' => 'fees'])->assertRedirect();
        $this->get('/ryoukin')->assertStatus(301)->assertRedirect('/fees');
        $this->get('/price')->assertStatus(301)->assertRedirect('/fees');

        // 元の URL に戻したら、自分自身へ転送しない
        $this->actingAs($admin)->patch("/admin/pages/{$page->getKey()}", ['title' => '料金のめやす', 'slug' => 'ryoukin'])->assertRedirect();
        $this->assertFalse(RedirectRule::query()->where('source_path', '/ryoukin')->exists());
    }

    public function test_下書きのページはurlを変えても転送を作らない(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($admin)->post('/admin/pages', ['title' => '下書き', 'slug' => 'draft-a'])->assertRedirect();
        $page = Page::query()->where('slug', 'draft-a')->firstOrFail();

        $this->actingAs($admin)->patch("/admin/pages/{$page->getKey()}", ['title' => '下書き', 'slug' => 'draft-b'])->assertRedirect();

        $this->assertSame(0, RedirectRule::query()->count());
    }
}
