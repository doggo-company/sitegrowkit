<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 画面が「サーバーの時間帯」を知っている（WP 一周 5 周目 2026-09-03 / 効くもの ①）。
 *
 * 線の上を流れる日時は ISO8601（UTC・末尾 Z）である。**それは機械にとって正しい** —
 * API / MCP から読む AI エージェントも同じ値を見る。だから契約は変えない。
 *
 * 代わりに、**人が読む面だけ**をサーバーの時間帯へ直す。そのためには
 * 画面が時間帯を知っている必要がある。ここが無いと `admin-datetime.ts` は
 * 動かしているブラウザの時計に頼るしかなくなり、
 * **見る人によって公開日時が変わる**（海外から見た担当者の画面だけずれる）。
 */
final class AdminTimezoneShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_screens_know_the_server_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);

        $admin = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(
                fn (AssertableInertia $page) => $page->where('timezone', 'Asia/Tokyo'),
            );
    }

    /**
     * 時間帯は**設定から採る**。焼き込むと、UTC で運ぶ案件で嘘になる。
     */
    public function test_it_follows_the_configured_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $admin = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(
                fn (AssertableInertia $page) => $page->where('timezone', 'UTC'),
            );
    }
}
