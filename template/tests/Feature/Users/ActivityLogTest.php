<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Audit\AuditLog;
use App\Application\Activity\ExportActivity;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE WP-BASELINE / STAGE #18A — 行動履歴（SPEC §2-9〜13・15）。
 */
final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /** SPEC §2-9 — 既存 mutation の監査と actor_id が維持されている */
    public function test_既存mutationのauditとactorが維持される(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/admin/news', [
            'title' => '監査テスト', 'slug' => 'audit-test', 'body' => '<p>x</p>', 'status' => 'draft',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'content.create',
            'result' => 'success',
            'interface' => 'admin',
            'actor_id' => $owner->getKey(),
        ]);
    }

    /** SPEC §2-8 — 認証イベントが記録され、未知のメールアドレスは残らない */
    public function test_認証イベントが記録されメールアドレスは残らない(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'auth.login', 'result' => 'success', 'actor_id' => $user->getKey(),
        ]);
        $this->assertNotNull($user->refresh()->last_login_at);

        $this->post('/logout');
        $this->assertDatabaseHas('audit_logs', ['operation' => 'auth.logout']);

        // 存在しないメールアドレスでの失敗
        $this->post('/login', ['email' => 'ghost@example.test', 'password' => 'wrong']);

        $failed = DB::table('audit_logs')->where('operation', 'auth.login.failed')->firstOrFail();
        $this->assertSame('forbidden', $failed->result);
        $this->assertNull($failed->actor_id);
        $this->assertStringNotContainsString('ghost@example.test', (string) $failed->metadata);
        $this->assertStringContainsString('identifierHash', (string) $failed->metadata);
        // 認証イベントには接続元が残る
        $this->assertNotNull($failed->ip_address);
    }

    /** SPEC §2-10 — 危険なキーは metadata に保存されない */
    public function test_metadataへ機微な値を渡しても保存されない(): void
    {
        $owner = User::factory()->create();

        app(AuditRecorder::class)->record(
            'content.update',
            OperationClassification::Write,
            OperationContext::admin((int) $owner->getKey(), 'tester'),
            Result::success(),
            metadata: [
                'password' => 'p@ssw0rd', 'token' => 'secret-token', 'api_key' => 'k',
                'body' => '本文まるごと', 'message' => '問い合わせ本文', 'cookie' => 'x',
                'authorization' => 'Bearer x', 'credential' => 'c', 'secret' => 's',
                'fields' => ['title', 'body'],
            ],
        );

        $metadata = (string) DB::table('audit_logs')->where('operation', 'content.update')->value('metadata');

        foreach (['p@ssw0rd', 'secret-token', '本文まるごと', '問い合わせ本文', 'Bearer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $metadata);
        }

        // 変更されたフィールド名の一覧は残る（監査の役に立つ情報）
        $this->assertStringContainsString('title', $metadata);
    }

    /** SPEC §2-11 — 絞り込み・ページング・ユーザー別境界・権限拒否 */
    public function test_履歴の絞り込みと権限境界(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->administrator()->create();

        $this->seedLogs($owner, 'content.create', 3);
        $this->seedLogs($other, 'content.delete', 2);

        // 全体履歴
        $all = $this->actingAs($owner)->get('/admin/activity');
        $all->assertOk();
        $this->assertSame(5, $all->viewData('page')['props']['meta']['total']);

        // operation で絞る
        $filtered = $this->actingAs($owner)->get('/admin/activity?operation=content.delete');
        $this->assertSame(2, $filtered->viewData('page')['props']['meta']['total']);

        // 結果で絞る（成功のみ seed している）
        $none = $this->actingAs($owner)->get('/admin/activity?result=forbidden');
        $this->assertSame(0, $none->viewData('page')['props']['meta']['total']);

        // ユーザー別は actor が固定される
        $mine = $this->actingAs($owner)->get('/admin/users/'.$other->getKey().'/activity');
        $this->assertSame(2, $mine->viewData('page')['props']['meta']['total']);

        // 期間の境界（未来日で絞ると 0 件）
        $future = $this->actingAs($owner)->get('/admin/activity?from='.now()->addDay()->toDateString());
        $this->assertSame(0, $future->viewData('page')['props']['meta']['total']);

        // ページング
        $paged = $this->actingAs($owner)->get('/admin/activity?page=2');
        $this->assertSame(2, $paged->viewData('page')['props']['meta']['page']);

        // 権限が無ければ開けない
        foreach ([Role::Editor, Role::Viewer] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get('/admin/activity')->assertForbidden();
            $this->actingAs($user)->get('/admin/activity/export')->assertForbidden();
        }
    }

    /** IP と User-Agent はオーナーにだけ返る */
    public function test_接続元はownerにだけ返る(): void
    {
        $owner = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        $this->seedLogs($owner, 'auth.login', 1);

        $ownerView = $this->actingAs($owner)->get('/admin/activity');
        $this->assertTrue($ownerView->viewData('page')['props']['meta']['includesNetworkContext']);

        $adminView = $this->actingAs($administrator)->get('/admin/activity');
        $this->assertFalse($adminView->viewData('page')['props']['meta']['includesNetworkContext']);
        $this->assertArrayNotHasKey('ipAddress', $adminView->viewData('page')['props']['entries'][0]);
    }

    /** SPEC §2-12 — CSV は絞り込みを反映し、UTF-8 と数式注入対策を持つ */
    public function test_csv書き出し(): void
    {
        $owner = User::factory()->create();
        $this->seedLogs($owner, 'content.create', 2);
        $this->seedLogs($owner, 'content.delete', 1);

        $response = $this->actingAs($owner)->get('/admin/activity/export?operation=content.delete');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM が無い');
        $this->assertStringContainsString('日時', $csv);
        $this->assertStringContainsString('コンテンツを削除', $csv);
        $this->assertStringNotContainsString('コンテンツを作成', $csv, '絞り込みが CSV に反映されていない');

        // 数式として解釈されうる値は必ず文字列として開かせる
        $this->assertSame("'=1+1", ExportActivity::neutralize('=1+1'));
        $this->assertSame("'+SUM(A1)", ExportActivity::neutralize('+SUM(A1)'));
        $this->assertSame("'@x", ExportActivity::neutralize('@x'));
        $this->assertSame('通常の値', ExportActivity::neutralize('通常の値'));
    }

    /** SPEC §2-13 — XSS payload は文字列として保持され、HTML にならない */
    public function test_xss_payloadが実行されない(): void
    {
        $owner = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->seedLogs($owner, 'content.create', 1);

        $response = $this->actingAs($owner)->get('/admin/activity');
        $response->assertOk();

        $entry = $response->viewData('page')['props']['entries'][0];
        $this->assertSame('<script>alert(1)</script>', $entry['actorLabel'], '入力は書き換えずに保持する');

        // HTML には **エスケープされた形**でしか現れない（生の script タグは無い）
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** 実行者が無効化されても当時の表示名は履歴に残る */
    public function test_無効化された実行者のラベルが残る(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create(['name' => '退職した編集者']);
        $this->seedLogs($target, 'content.create', 1);

        $target->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($owner)->get('/admin/users/'.$target->getKey().'/activity');
        $response->assertOk();

        $this->assertSame('退職した編集者', $response->viewData('page')['props']['entries'][0]['actorLabel']);
    }

    /** SPEC §2-15 — 保持期間（匿名化と削除）が境界日で働く */
    public function test_保持期間の匿名化と削除(): void
    {
        config([
            'agentcms.audit.retention.anonymize_after_days' => 90,
            'agentcms.audit.retention.delete_after_days' => 365,
        ]);

        $rows = [
            'fresh' => now()->subDays(10),
            'anonymize' => now()->subDays(91),
            'delete' => now()->subDays(366),
        ];

        foreach ($rows as $key => $when) {
            DB::table('audit_logs')->insert([
                'actor_type' => 'user', 'actor_id' => null, 'actor_label' => $key,
                'interface' => 'admin', 'operation' => 'auth.login', 'classification' => 'external_effect',
                'result' => 'success', 'metadata' => '{}', 'ip_address' => '203.0.113.1',
                'user_agent' => 'test-agent', 'created_at' => $when,
            ]);
        }

        Artisan::call('agentcms:audit-prune');

        $this->assertDatabaseMissing('audit_logs', ['actor_label' => 'delete']);

        $anonymized = DB::table('audit_logs')->where('actor_label', 'anonymize')->firstOrFail();
        $this->assertNull($anonymized->ip_address);
        $this->assertNull($anonymized->user_agent);
        $this->assertNotNull($anonymized->anonymized_at);

        $fresh = DB::table('audit_logs')->where('actor_label', 'fresh')->firstOrFail();
        $this->assertSame('203.0.113.1', $fresh->ip_address, '期限内の行は触らない');

        // 0 以下にすると何もしない（既存ログを不用意に消さない）
        config(['agentcms.audit.retention.delete_after_days' => 0]);
        Artisan::call('agentcms:audit-prune');
        $this->assertDatabaseHas('audit_logs', ['actor_label' => 'fresh']);
    }

    /** 監査ログは追記専用（read model から書き換えられない） */
    public function test_監査ログは追記専用(): void
    {
        $this->seedLogs(User::factory()->create(), 'content.create', 1);

        $log = AuditLog::query()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    private function seedLogs(User $actor, string $operation, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('audit_logs')->insert([
                'actor_type' => 'user',
                'actor_id' => $actor->getKey(),
                'actor_label' => (string) $actor->name,
                'interface' => 'admin',
                'operation' => $operation,
                'classification' => 'write',
                'resource_type' => 'news',
                'resource_id' => (string) ($i + 1),
                'result' => 'success',
                'metadata' => '{}',
                'ip_address' => '203.0.113.5',
                'user_agent' => 'seed',
                'created_at' => now()->subMinutes($i),
            ]);
        }
    }
}
