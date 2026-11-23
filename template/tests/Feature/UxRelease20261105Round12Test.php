<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Http\Controllers\Admin\ReadinessAdminController;
use App\Support\Industry\IndustryProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開前の UX 一周 12 周目（2026-11-05・保護猫カフェ・rc16）の回帰。
 *
 * - R12-1: お仕事の候補に、選ぶと何が替わるかの 1 行を付ける
 * - R12-3（数えなかったもの）: 所在地の「〇〇」が全ページのフッターに出たのに、「このページを直す」が
 *   〇〇の無いページへ連れて行った
 */
final class UxRelease20261105Round12Test extends TestCase
{
    use RefreshDatabase;

    public function test_お仕事の候補に何が替わるかを付ける(): void
    {
        $options = array_column(IndustryProfiles::options(), 'hint', 'key');

        $this->assertStringContainsString('ご用件: 席のご予約', $options['cafe']);
        $this->assertStringContainsString('ご用件: ご予約', $options['trimming']);
        $this->assertStringContainsString('お仕事の名前', $options['other']);
    }

    public function test_サイト設定にある原稿待ちの言葉はサイト設定へ連れて行く(): void
    {
        app(SettingsStore::class)->put(['address' => '東京都〇〇区〇〇1-2-3']);

        $fix = (new \ReflectionMethod(ReadinessAdminController::class, 'fixFor'))
            ->invoke(null, ['level' => 'blocker', 'where' => '/service', 'what' => '原稿待ちの言葉が公開面に出ている: 「〇〇」']);

        $this->assertSame('/admin/site', $fix['href'] ?? null);
        $this->assertStringContainsString('所在地', (string) ($fix['label'] ?? ''));
    }
}
