<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use App\Models\User;
use App\Support\Industry\IndustryProfiles;
use App\Support\Industry\IndustrySetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 公開前の UX 一周 8 周目（2026-11-05・花屋の店主・スマホの二度タップ・rc12）の回帰。
 *
 * - M2（残り）: 花屋が「作家・ものづくり」か「その他」しか選べなかった
 * - R7-3（残り）: ダッシュボードで予約した記事が「公開」と出た（判定に時間帯の無い文字を使っていた）
 *
 * R8-2・R8-4（二度押しが替わった画面の別のボタンに当たる）は `ghost-click.test.ts` と
 * `tests/e2e/ux-round8.spec.ts`、R8-3 は `Forms/PublicFormTest`、R8-1 はセットアップ画面。
 */
final class UxRelease20261105Round8Test extends TestCase
{
    use RefreshDatabase;

    public function test_お花雑貨などのお店を選べて注文の言葉になる(): void
    {
        $profile = IndustryProfiles::get('shop');
        $this->assertNotNull($profile);
        $this->assertSame('お花・雑貨などのお店', $profile['label']);

        $words = (string) json_encode(IndustrySetup::contactFields($profile), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ご注文のご相談', $words);
        $this->assertStringNotContainsString('会社名', $words);
    }

    public function test_ダッシュボードの最近の記事は時間帯つきの公開日時も渡す(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        // ダッシュボードが並べる最初の記事の型（試験の環境ではニュース、作ったサイトでは投稿）。型の作法どおりに作る
        $type = Schema::hasTable('news') ? 'news' : 'post';
        $entry = app(ContentTypeResolver::class)->newModel($type);
        $entry->forceFill([
            'title' => '母の日のご予約', 'slug' => 'mothers-day', 'body' => '<p>予約受付</p>',
            'status' => 'published', 'published_at' => now()->addWeek(),
        ])->save();
        $id = (int) $entry->getKey();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'recent.items',
                fn ($entries) => collect($entries)->contains(
                    fn ($e) => (int) $e['id'] === $id && is_string($e['published_at_iso'] ?? null) && str_contains($e['published_at_iso'], 'T'),
                ),
            ));
    }
}
