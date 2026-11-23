<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use App\Support\OperationCatalog;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * Phase 9.1B / STEP 15: Admin と MCP が **同じ Application Service** を通ること。
 *
 * 「管理画面でできることは AI でも安全にできる」「AI が管理画面より強くならない」を
 * 検証・認可・業務結果・破壊的挙動・監査の 5 点で突き合わせる。
 */
final class AdminMcpParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'parity-agent');
    }

    private function catalog(): OperationCatalog
    {
        return app(OperationCatalog::class);
    }

    public function test_validation_is_identical_for_admin_and_agent(): void
    {
        // Admin: 必須項目が無ければフォームエラー
        $this->actingAs(User::factory()->create())
            ->post('/admin/news', ['body' => '本文'])
            ->assertSessionHasErrors('title');

        // Agent: 同じ入力で同じフィールドの validation_error
        $result = $this->catalog()->invoke('content.create', [
            'type' => 'news',
            'data' => ['body' => '本文'],
        ], $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('title', $result->errors);
    }

    public function test_business_result_is_identical_for_admin_and_agent(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/news', [
            'title' => '管理画面から', 'body' => '本文', 'status' => 'draft',
        ])->assertRedirect('/admin/news');

        $result = $this->catalog()->invoke('content.create', [
            'type' => 'news',
            'data' => ['title' => 'AI から', 'body' => '本文', 'status' => 'draft'],
        ], $this->agent());

        $fromAdmin = News::query()->where('title', '管理画面から')->firstOrFail();
        $fromAgent = News::query()->where('title', 'AI から')->firstOrFail();

        $this->assertTrue($result->isSuccess());
        // 導出されるフィールドの扱いが経路で変わらない
        $this->assertSame($fromAdmin->status, $fromAgent->status);
        $this->assertNotSame('', $fromAdmin->slug);
        $this->assertNotSame('', $fromAgent->slug);
    }

    public function test_destructive_behaviour_is_identical_for_admin_and_agent(): void
    {
        $user = User::factory()->create();
        $adminTarget = (int) News::query()->orderBy('id')->firstOrFail()->getKey();
        $agentTarget = (int) News::query()->orderByDesc('id')->firstOrFail()->getKey();

        // Admin: token 無しでは消えない
        $this->actingAs($user)->delete("/admin/news/{$adminTarget}")->assertRedirect();
        $this->assertDatabaseHas('news', ['id' => $adminTarget]);

        // Agent: token 無しでは消えず、確認要求が返る
        $first = $this->catalog()->invoke(
            'content.delete',
            ['type' => 'news', 'id' => $agentTarget],
            $this->agent(),
        );

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertDatabaseHas('news', ['id' => $agentTarget]);

        /*
         * Agent: 確認後はゴミ箱へ移る（管理画面と同じ ConfirmationGate）。
         *
         * 9.8 / STAGE 21C から `content.delete` は物理削除ではない。ここで見たいのは
         * **管理画面と AI で結果が同じか**なので、結果の中身が変わっても比較の形は同じである。
         */
        $second = $this->catalog()->invoke(
            'content.delete',
            ['type' => 'news', 'id' => $agentTarget],
            $this->agent()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertTrue($second->isSuccess());
        $this->assertSame('trashed', $second->data['state']);

        // 行は残り、通常の読み取りからは消える
        $this->assertDatabaseHas('news', ['id' => $agentTarget]);
        $this->assertNull(News::query()->find($agentTarget));
    }

    public function test_agent_is_not_more_powerful_than_admin(): void
    {
        // 管理画面に無い操作は AI にも無い（allowlist が上限）
        foreach (['db.wipe', 'user.create', 'shell.run', 'file.read'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden));
        }

        // 管理画面にはある破壊的操作でも、AI へは出していないものがある
        $this->assertFalse(OperationCatalog::allows('media.delete'));
    }

    public function test_audit_records_which_interface_performed_the_change(): void
    {
        $user = User::factory()->create();
        $id = (int) News::query()->firstOrFail()->getKey();

        $this->actingAs($user)->put("/admin/news/{$id}", [
            'title' => '管理画面が更新', 'slug' => 'admin-updated', 'body' => '本文', 'status' => 'draft',
        ])->assertRedirect('/admin/news');

        $this->catalog()->invoke('content.update', [
            'type' => 'news', 'id' => $id, 'data' => ['title' => 'AI が更新'],
        ], $this->agent());

        $interfaces = DB::table('audit_logs')
            ->where('operation', 'content.update')
            ->orderBy('id')
            ->pluck('interface')
            ->all();

        $this->assertSame(['admin', 'mcp'], $interfaces);
    }
}
