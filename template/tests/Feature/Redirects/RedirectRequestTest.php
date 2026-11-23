<?php

declare(strict_types=1);

namespace Tests\Feature\Redirects;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Redirects\ListNotFound;
use AgentCms\Core\Application\Redirects\ResolveNotFound;
use AgentCms\Core\Redirects\NotFoundLog;
use AgentCms\Core\Redirects\RedirectMatchType;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectRule;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 404 から実際に転送するところ（Phase 9.10 / STAGE 23C・SPEC §C・§F）。
 *
 * 決め方そのものは 23B（`RedirectStorageTest`）が見ている。
 * ここで固定するのは **要求が来たときに何が起きるか**である。
 */
final class RedirectRequestTest extends TestCase
{
    use RefreshDatabase;

    // ── 転送 ────────────────────────────────────────────────

    public function test_404になるurlは転送される(): void
    {
        $this->rule('/old-page', '/');

        $this->get('/old-page')->assertRedirect('/')->assertStatus(301);
    }

    public function test_ルールが無ければこれまでどおり404(): void
    {
        $this->get('/nope')->assertNotFound();
    }

    public function test_status_codeはルールのとおり(): void
    {
        $this->rule('/temp', '/', status: 302);

        $this->get('/temp')->assertStatus(302);
    }

    public function test_queryを引き継いで転送する(): void
    {
        $this->rule('/old-page', '/');

        $this->get('/old-page?utm_source=x')->assertRedirect('/?utm_source=x');
    }

    public function test_ヒット数と最終日時が記録される(): void
    {
        $this->rule('/old-page', '/');

        $this->get('/old-page');
        $this->get('/old-page');

        $rule = RedirectRule::query()->firstOrFail();

        $this->assertSame(2, $rule->hit_count);
        $this->assertNotNull($rule->last_hit_at);
    }

    public function test_無効なルールは転送しない(): void
    {
        $this->rule('/old-page', '/', enabled: false);

        $this->get('/old-page')->assertNotFound();
    }

    public function test_get以外は転送しない(): void
    {
        $this->rule('/old-page', '/');

        // POST を 301 で転送すると method の意味が変わる
        $this->post('/old-page')->assertNotFound();
        $this->put('/old-page')->assertNotFound();
    }

    public function test_管理画面のパスは転送しない(): void
    {
        // 保存経路は断るので、直接入れて「要求時にも効かない」ことを見る
        $this->rule('/admin/menus', '/');

        $this->get('/admin/menus')->assertRedirect('/login');
    }

    public function test_apiには転送を返さない(): void
    {
        $this->rule('/old-page', '/');

        // API 利用者に HTML の転送を返さない
        $this->getJson('/old-page')->assertNotFound();
    }

    public function test_輪になっていたら404のまま(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/a');

        // ブラウザに「リダイレクトが多すぎます」を出させない
        $this->get('/a')->assertNotFound();
    }

    public function test_外部への転送(): void
    {
        $this->rule('/moved', 'https://example.com/new');

        $this->get('/moved')->assertRedirect('https://example.com/new');
    }

    // ── 404 の記録 ──────────────────────────────────────────

    public function test_404は記録される(): void
    {
        $this->get('/missing?ref=1');

        $log = NotFoundLog::query()->firstOrFail();

        $this->assertSame('/missing', $log->path);
        $this->assertSame(1, $log->hit_count);
        $this->assertSame('ref=1', $log->last_query);
        $this->assertNotNull($log->first_seen_at);
    }

    public function test_同じurlは1行にまとまる(): void
    {
        $this->get('/missing');
        $this->get('/missing/');
        $this->get('/missing?x=1');

        $this->assertSame(1, NotFoundLog::query()->count());
        $this->assertSame(3, (int) NotFoundLog::query()->value('hit_count'));
    }

    public function test_転送できた要求は記録しない(): void
    {
        $this->rule('/old-page', '/');

        $this->get('/old-page');

        // それは 404 ではない
        $this->assertSame(0, NotFoundLog::query()->count());
    }

    public function test_管理画面の打ち間違いは記録しない(): void
    {
        $this->get('/admin/nope');
        $this->get('/api/v1/nope');

        $this->assertSame(0, NotFoundLog::query()->count());
    }

    public function test_生のipは保存されない(): void
    {
        $this->get('/missing');

        $log = NotFoundLog::query()->firstOrFail();

        $this->assertNotNull($log->last_ip_hash);
        $this->assertSame(64, strlen($log->last_ip_hash));
        // 生の IP がそのまま入っていない
        $this->assertStringNotContainsString('127.0.0.1', (string) $log->last_ip_hash);
        // 塩が効いている（APP_KEY 抜きの hash とは一致しない）
        $this->assertNotSame(hash('sha256', '127.0.0.1'), $log->last_ip_hash);
    }

    public function test_記録を切れる(): void
    {
        config()->set('agentcms.not_found.enabled', false);

        $this->get('/missing')->assertNotFound();

        $this->assertSame(0, NotFoundLog::query()->count());
    }

    public function test_行数の上限を超えない(): void
    {
        config()->set('agentcms.not_found.max_rows', 3);

        for ($i = 0; $i < 6; $i++) {
            $this->get("/missing-{$i}");
        }

        // bot は無限に path を作れる。表の大きさを予測可能にする
        $this->assertLessThanOrEqual(3, NotFoundLog::query()->count());
    }

    public function test_よく踏まれた記録は残る(): void
    {
        config()->set('agentcms.not_found.max_rows', 2);

        // よく踏まれるもの
        for ($i = 0; $i < 5; $i++) {
            $this->get('/popular');
        }

        // 探索されただけのもの
        for ($i = 0; $i < 5; $i++) {
            $this->get("/probe-{$i}");
        }

        $this->assertTrue(
            NotFoundLog::query()->where('path', '/popular')->exists(),
            'よく踏まれている記録が捨てられている',
        );
    }

    // ── 記録からルールを作る ────────────────────────────────

    public function test_記録からルールを作れる(): void
    {
        $this->get('/gone');
        $id = (int) NotFoundLog::query()->value('id');

        $result = app(ResolveNotFound::class)->execute($id, '/', $this->context());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $rule = RedirectRule::query()->firstOrFail();

        $this->assertSame('/gone', $rule->source_path);
        // どうやってできたルールかを残す
        $this->assertSame(RedirectRule::REASON_NOT_FOUND, $rule->created_reason);
        $this->assertNotNull(NotFoundLog::query()->find($id)?->resolved_at);

        // 実際に転送されるようになる
        $this->get('/gone')->assertRedirect('/');
    }

    public function test_作れなければ印も付かない(): void
    {
        $this->get('/gone');
        $id = (int) NotFoundLog::query()->value('id');

        // 危険な転送先は保存できない
        $result = app(ResolveNotFound::class)->execute($id, 'javascript:alert(1)', $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(0, RedirectRule::query()->count());
        // 直したことになっている記録を残さない
        $this->assertNull(NotFoundLog::query()->find($id)?->resolved_at);
    }

    public function test_一覧は未解決を先に出す(): void
    {
        $this->get('/open');
        $this->get('/fixed');
        NotFoundLog::query()->where('path', '/fixed')->update(['resolved_at' => now()]);

        $result = app(ListNotFound::class)->execute($this->context());

        $this->assertSame(1, $result->meta['total']);
        $this->assertSame('/open', $result->data['logs'][0]['path']);

        $all = app(ListNotFound::class)->execute($this->context(), includeResolved: true);
        $this->assertSame(2, $all->meta['total']);
    }

    public function test_記録の一覧はipを出さない(): void
    {
        $this->get('/missing');

        $row = app(ListNotFound::class)->execute($this->context())->data['logs'][0];

        // 個人を追う材料を画面へ渡さない（在ることだけ分かれば足りる）
        $this->assertArrayNotHasKey('last_ip_hash', $row);
        $this->assertTrue($row['has_ip']);
    }

    // ── 保持期間 ────────────────────────────────────────────

    public function test_pruneは個人情報を先に消す(): void
    {
        $this->get('/old-missing');
        NotFoundLog::query()->update(['last_seen_at' => now()->subDays(60)]);

        $this->artisan('agentcms:redirect-prune')->assertSuccessful();

        $log = NotFoundLog::query()->firstOrFail();

        // 記録そのものは残し、個人情報だけ消す
        $this->assertNull($log->last_ip_hash);
        $this->assertNull($log->last_user_agent);
        $this->assertSame('/old-missing', $log->path);
    }

    public function test_pruneはdry_runでは変えない(): void
    {
        $this->get('/old-missing');
        NotFoundLog::query()->update(['last_seen_at' => now()->subDays(60)]);

        $this->artisan('agentcms:redirect-prune --dry-run')->assertSuccessful();

        $this->assertNotNull(NotFoundLog::query()->value('last_ip_hash'));
    }

    public function test_pruneは0を指定すると何もしない(): void
    {
        config()->set('agentcms.not_found.retention.anonymize_after_days', 0);
        config()->set('agentcms.not_found.retention.delete_after_days', 0);
        config()->set('agentcms.not_found.retention.delete_resolved_after_days', 0);

        $this->get('/old-missing');
        NotFoundLog::query()->update(['last_seen_at' => now()->subDays(1000)]);

        $this->artisan('agentcms:redirect-prune')->assertSuccessful();

        // 既存の記録を不用意に消さない
        $this->assertSame(1, NotFoundLog::query()->count());
        $this->assertNotNull(NotFoundLog::query()->value('last_ip_hash'));
    }

    // ── 公開面 ──────────────────────────────────────────────

    public function test_apiは読みだけ(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);
        $this->rule('/old-page', '/');

        $this->actingAs($owner)->getJson('/api/v1/redirects')
            ->assertOk()
            ->assertJsonPath('data.0.source_path', '/old-page');

        $this->actingAs($owner)->getJson('/api/v1/redirects/simulate?url=/old-page')
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.target', '/');

        // 試しただけでヒット数は増えない
        $this->assertSame(0, (int) RedirectRule::query()->value('hit_count'));
    }

    public function test_apiは未認証では返さない(): void
    {
        $this->getJson('/api/v1/redirects')->assertUnauthorized();
        $this->getJson('/api/v1/redirects/simulate?url=/a')->assertUnauthorized();
    }

    public function test_書き込みのapiは無い(): void
    {
        // 管理画面の route は別（`RedirectAdminTest` が見る）。ここは api/v1 だけ
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/redirect'),
        ));

        $actual = array_map(
            static fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $routes,
        );

        sort($actual);

        $this->assertSame([
            'GET api/v1/redirects',
            'GET api/v1/redirects/simulate',
            'GET api/v1/redirects/{id}',
        ], $actual);
    }

    public function test_aiへ公開するのは参照だけ(): void
    {
        $ids = OperationCatalog::ids();

        $this->assertContains('redirect.list', $ids);
        $this->assertContains('redirect.simulate', $ids);
        $this->assertNotContains('redirect.save', $ids);
        $this->assertNotContains('redirect.delete', $ids);
        // 404 の記録は個人に近づくので出さない
        $this->assertNotContains('not_found.list', $ids);
        $this->assertNotContains('not_found.resolve', $ids);
    }

    // ── helpers ────────────────────────────────────────────

    private function context(): OperationContext
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        return OperationContext::admin((int) $owner->getKey(), (string) $owner->name);
    }

    private function rule(
        string $source,
        string $target,
        RedirectMatchType $type = RedirectMatchType::Exact,
        int $status = 301,
        bool $enabled = true,
    ): void {
        DB::table('redirect_rules')->insert([
            'match_type' => $type->value,
            'source_path' => $source,
            'source_hash' => RedirectPath::hash($type, $source),
            'target_url' => $target,
            'status_code' => $status,
            'is_enabled' => $enabled,
            'keep_query' => true,
            'created_reason' => 'manual',
            'hit_count' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
