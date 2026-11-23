<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Role;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UX 一周 第 11 周（税理士事務所の所長の目・業種の入口から・2026-09-28）で見つけたものの回帰。
 */
final class UxWalkRound11Test extends TestCase
{
    use RefreshDatabase;

    private function publishColumn(): void
    {
        $this->seed(MenuSeeder::class);
        $owner = User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'zeirishi'])->assertRedirect();
        $this->app?->forgetScopedInstances();
        $this->actingAs($owner)->post('/admin/content-types/post/quick-start', ['stay' => 1])->assertRedirect();
        $this->app?->forgetScopedInstances();
        $this->actingAs($owner)
            ->post('/admin/post', ['title' => '年末調整の書類のご案内', 'body' => '本文', 'status' => 'published'])
            ->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
    }

    /** 記事の一覧・詳細の題の帯を、ページと同じ帯（page-title）にする。一覧だけ帯の高さと題の位置が違った */
    public function test_記事の一覧と詳細はページと同じ題の帯(): void
    {
        $this->publishColumn();

        $list = (string) $this->get('/column')->assertOk()->getContent();
        $this->assertStringContainsString('<section class="page-title">', $list);
        $this->assertStringContainsString('<h1>お知らせ・コラム</h1>', $list);
        $this->assertStringNotContainsString('page-hero', $list);

        $slug = (string) DB::table('post')->value('slug');
        $show = (string) $this->get('/column/'.$slug)->assertOk()->getContent();
        $this->assertStringContainsString('<section class="page-title">', $show);
        $this->assertStringContainsString('年末調整の書類のご案内</h1>', $show);
        $this->assertStringContainsString('class="page-title-sub"', $show);
        // 「一覧へ戻る」は店の枠のボタン（店の色の枠線）。黒い枠の丸いボタンだけ浮いていた
        $this->assertStringContainsString('class="btn btn-outline" href="/column">お知らせ・コラム一覧へ戻る</a>', $show);
    }
}
