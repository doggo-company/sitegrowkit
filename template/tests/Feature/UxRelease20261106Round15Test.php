<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use App\Http\Controllers\Admin\ReadinessAdminController;
use App\Support\InboxRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開前の UX 一周 15 周目（2026-11-06・rc19 の採点 round15-grade.md）の回帰。
 *
 * - G2: フォームの通知先の欄が空なのに、ダッシュボードは宛先を言っていた（どちらが本当か分からない）
 * - G3: 公開前チェックの「〇〇」の行（見本のページの中の言葉）に押す所が無かった
 */
final class UxRelease20261106Round15Test extends TestCase
{
    use RefreshDatabase;

    public function test_見本のページの原稿待ちの言葉は見本の行へ連れて行く(): void
    {
        $fix = static fn (string $where): ?array => (new \ReflectionMethod(ReadinessAdminController::class, 'fixFor'))
            ->invoke(null, ['level' => 'blocker', 'where' => $where, 'what' => '原稿待ちの言葉が公開面に出ている: 「〇〇」']);

        $this->assertSame('/admin/pages#sample-home', $fix('/')['href'] ?? null);
        $this->assertSame('/admin/pages#sample-service', $fix('/service')['href'] ?? null);
        // 見本でも、ページの一覧にも無い場所でも、押す所は無くさない
        $this->assertSame('/admin/pages', $fix('/somewhere-else')['href'] ?? null);
    }

    public function test_通知先の欄が空なら空のときに届く先だと言う(): void
    {
        Form::query()->forceCreate(['key' => 'contact', 'name' => 'お問い合わせ', 'notify_to' => null, 'status' => 'published']);

        $problem = app(InboxRecipients::class)->problems()[0] ?? null;

        $this->assertNotNull($problem);
        $this->assertTrue($problem['fallback']);
    }
}
