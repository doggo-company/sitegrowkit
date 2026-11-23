<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\RequestCorrelation;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Http\AssignRequestId;
use App\Application\Users\ReactivateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * 監査の相関 ID（STAGE #18A / Correction 2-3）。
 *
 * 以前は `OperationContext::$requestId` という **入れ物だけ**があり、
 * すべての factory が null を入れていた。テストも「別の試行として記録される」を
 * `id` と `result` の違いで見ていただけで、相関 ID そのものは検証していなかった。
 *
 * ここで確かめるのは 3 つ。
 *
 * | 何を | なぜ |
 * | --- | --- |
 * | 1 リクエスト内は同じ non-null な ID | 1 回の試行で複数の記録が出ても束ねられる |
 * | 別リクエストは別の ID | 確認待ちと適用が混ざらない |
 * | 受信ヘッダーを採用しない | 他人の試行を騙れないようにする |
 */
final class RequestCorrelationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1 リクエストで 2 つ記録が出たとき、両方が **同じ** non-null の ID になる。
     *
     * 通常の管理画面は 1 リクエスト 1 操作なので、そのまま叩くと
     * 「1 行しか無いのに一意だった」という無意味な合格になる。
     * ここでは **2 操作を同じリクエストで走らせる route** を用意して、
     * 束ねられていることを実際に確かめる。
     */
    public function test_同一request内の複数auditが同じ非nullのidになる(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create(['is_active' => false]);

        $this->twoOperationRoute();

        $this->actingAs($owner)->get('/__test__/two-operations/'.$target->getKey())->assertOk();

        $rows = DB::table('audit_logs')->orderBy('id')->get();

        // 前提そのものを検証する（1 行しか出ていないなら以降の assert は無意味）
        $this->assertCount(2, $rows, '同一リクエストで 2 つの記録が出ていない');
        $this->assertSame(
            ['settings.update', 'user.reactivate'],
            $rows->pluck('operation')->sort()->values()->all(),
        );

        $ids = $rows->pluck('request_id');

        $this->assertNotContains(null, $ids->all(), 'request_id が null のままの記録がある');
        $this->assertCount(1, $ids->unique()->all(), '同じリクエストなのに ID が分かれている');

        // 2 回目のリクエストでは別の ID になる（同じ route でも試行が違う）
        $first = (string) $ids->first();
        DB::table('audit_logs')->delete();

        $other = User::factory()->editor()->create(['is_active' => false]);
        $this->actingAs($owner)->get('/__test__/two-operations/'.$other->getKey())->assertOk();

        $this->assertNotSame($first, (string) DB::table('audit_logs')->value('request_id'));
    }

    /** 1 リクエストで 2 つの Application Service を走らせる検証用 route */
    private function twoOperationRoute(): void
    {
        Route::middleware(['web', 'auth'])->get('/__test__/two-operations/{id}', function (int $id) {
            $context = OperationContext::admin((int) auth()->id(), 'tester');

            app(ReactivateUser::class)->execute($id, $context);
            app(UpdateSettings::class)->execute(['site_name' => '相関テスト'], $context, partial: true);

            return response('ok');
        });
    }

    public function test_別のrequestは別のidになる(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create();
        $url = '/admin/users/'.$target->getKey().'/deactivate';

        // 1 回目 = 確認待ち、2 回目 = 適用。**別の試行**なので ID は分かれる
        $this->actingAs($owner)->post($url);
        $token = $this->flashedToken();
        $this->actingAs($owner)->post($url, ['confirmation_token' => $token]);

        $rows = DB::table('audit_logs')->where('operation', 'user.deactivate')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->request_id);
        $this->assertNotNull($rows[1]->request_id);
        $this->assertNotSame(
            $rows[0]->request_id,
            $rows[1]->request_id,
            '確認待ちと適用が同じ試行として記録されている',
        );
    }

    /** 外から渡された ID を信用しない（他人の試行を騙らせない） */
    public function test_受信したx_request_idヘッダーを採用しない(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create(['is_active' => false]);
        $spoofed = 'ATTACKER-SUPPLIED-REQUEST-ID';

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Request-Id' => $spoofed])
            ->post('/admin/users/'.$target->getKey().'/reactivate');

        $recorded = DB::table('audit_logs')->pluck('request_id')->unique()->values();

        $this->assertNotContains($spoofed, $recorded->all(), '受信ヘッダーがそのまま記録された');
        $this->assertNotSame($spoofed, $response->headers->get(AssignRequestId::HEADER));
        $this->assertNotNull($response->headers->get(AssignRequestId::HEADER), '応答へ ID を返していない');
    }

    /** middleware を通らない経路（CLI / MCP）でも ID は付く */
    public function test_cli経路でも相関idが付く(): void
    {
        $context = OperationContext::cli('agentcms:test');

        $this->assertNotNull($context->requestId);
        $this->assertSame(app(RequestCorrelation::class)->id(), $context->requestId);
    }

    /** 確認トークンを載せ替えても相関 ID は保たれる */
    public function test_確認トークンの付け替えで相関idを失わない(): void
    {
        $context = OperationContext::admin(1, 'tester');
        $withToken = $context->withConfirmationToken('deadbeef');

        $this->assertNotNull($context->requestId);
        $this->assertSame($context->requestId, $withToken->requestId);
        $this->assertSame('deadbeef', $withToken->confirmationToken);
    }

    private function flashedToken(): string
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $token = $flash['confirmation']['token'] ?? null;

        $this->assertIsString($token, '確認トークンが返っていない');

        return $token;
    }
}
