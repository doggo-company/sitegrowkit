<?php

declare(strict_types=1);

namespace Tests\Feature\Redirects;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Redirects\DeleteRedirect;
use AgentCms\Core\Redirects\NotFoundLog;
use AgentCms\Core\Redirects\RedirectRule;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 転送ルールと 404 の記録の管理画面（Phase 9.10 / STAGE 23D・SPEC §G）。
 *
 * 見た目そのものは Playwright が見る。ここで固定するのは
 * 「どの状態のときに何が渡り、何が渡らないか」と、権限の境界である。
 */
final class RedirectAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // 転送の画面は作りの画面（制作者だけ・2026-09-28）。名前は既存の呼び出しに合わせて残している
        $this->owner = User::factory()->create(['role' => Role::Developer->value]);
    }

    public function test_一覧は転送の種類も渡す(): void
    {
        $this->create('/old', '/new');

        $this->actingAs($this->owner)->get('/admin/redirects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/redirects/Index')
                ->has('rules', 1)
                ->where('rules.0.source_path', '/old')
                // 301 / 308 はブラウザが覚える。画面で注意を出すのに要る
                ->where('statuses.0.sticky', true)
                ->where('canWrite', true),
            );
    }

    public function test_追加できる(): void
    {
        $this->actingAs($this->owner)->post('/admin/redirects', [
            'source_path' => '/old/', 'target_url' => '/new',
            'match_type' => 'exact', 'status_code' => 301,
        ])->assertRedirect();

        // 正規化されて入る
        $this->assertSame('/old', RedirectRule::query()->value('source_path'));
    }

    public function test_危険な転送先は追加できない(): void
    {
        $this->actingAs($this->owner)->post('/admin/redirects', [
            'source_path' => '/old', 'target_url' => '//example.com',
            'match_type' => 'exact', 'status_code' => 301,
        ])->assertSessionHasErrors('target_url');

        $this->assertSame(0, RedirectRule::query()->count());
    }

    public function test_古い版では直せない(): void
    {
        $this->create('/old', '/new');
        $id = (int) RedirectRule::query()->value('id');

        $this->actingAs($this->owner)->patch("/admin/redirects/{$id}", [
            'source_path' => '/old', 'target_url' => '/first',
            'match_type' => 'exact', 'status_code' => 301, 'lock_version' => 0,
        ])->assertRedirect();

        $this->actingAs($this->owner)->patch("/admin/redirects/{$id}", [
            'source_path' => '/old', 'target_url' => '/second',
            'match_type' => 'exact', 'status_code' => 301, 'lock_version' => 0,
        ])->assertStatus(409);

        $this->assertSame('/first', RedirectRule::query()->value('target_url'));
    }

    public function test_削除は2段階(): void
    {
        $this->create('/old', '/new');
        $id = (int) RedirectRule::query()->value('id');

        $this->actingAs($this->owner)->post("/admin/redirects/{$id}/delete")->assertRedirect();
        $this->assertSame(1, RedirectRule::query()->count());

        $token = (string) app(DeleteRedirect::class)
            ->execute($id, OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name))
            ->confirmation?->token;

        $this->actingAs($this->owner)
            ->post("/admin/redirects/{$id}/delete", ['confirmation_token' => $token])
            ->assertRedirect();

        $this->assertSame(0, RedirectRule::query()->count());
    }

    public function test_シミュレータは踏まずに答える(): void
    {
        $this->create('/old', '/new');

        $this->actingAs($this->owner)->getJson('/admin/redirects/simulate?url=/old')
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('target', '/new');

        // 試しただけでヒット数は増えない
        $this->assertSame(0, (int) RedirectRule::query()->value('hit_count'));
    }

    public function test_404の一覧は未対応だけを出す(): void
    {
        $this->get('/gone');

        $this->actingAs($this->owner)->get('/admin/not-found')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/redirects/NotFound')
                ->has('logs', 1)
                ->where('logs.0.path', '/gone')
                // 個人を追う材料は画面へ渡さない
                ->missing('logs.0.last_ip_hash')
                ->where('logs.0.has_ip', true),
            );
    }

    public function test_記録から1操作でルールを作れる(): void
    {
        $this->get('/gone');
        $id = (int) NotFoundLog::query()->value('id');

        $this->actingAs($this->owner)
            ->post("/admin/not-found/{$id}/resolve", ['target_url' => '/new', 'status_code' => 301])
            ->assertRedirect();

        $this->assertSame('/gone', RedirectRule::query()->value('source_path'));
        $this->assertNotNull(NotFoundLog::query()->find($id)?->resolved_at);
    }

    // ── 権限 ────────────────────────────────────────────────

    /** 転送は作りの画面なので、オーナーと管理者は開けない（2026-09-28 まで管理者も編集できた） */
    public function test_オーナーと管理者は開けない(): void
    {
        foreach ([Role::Owner, Role::Administrator] as $role) {
            $user = User::factory()->create(['role' => $role->value]);

            $this->actingAs($user)->get('/admin/redirects')->assertForbidden();
            $this->actingAs($user)->get('/admin/not-found')->assertForbidden();
            $this->actingAs($user)->post('/admin/redirects', [
                'source_path' => '/old', 'target_url' => '/new',
                'match_type' => 'exact', 'status_code' => 301,
            ])->assertForbidden();
        }

        $this->assertSame(0, RedirectRule::query()->count());
    }

    public function test_編集者は開けない(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);

        // 404 の記録は個人に近づくので、閲覧そのものを SettingsManage にしてある
        $this->actingAs($editor)->get('/admin/not-found')->assertForbidden();

        $this->actingAs($editor)->post('/admin/redirects', [
            'source_path' => '/old', 'target_url' => '/new',
            'match_type' => 'exact', 'status_code' => 301,
        ])->assertForbidden();
    }

    public function test_未認証では開けない(): void
    {
        $this->get('/admin/redirects')->assertRedirect('/login');
        $this->get('/admin/not-found')->assertRedirect('/login');
    }

    // ── 画面の作法 ──────────────────────────────────────────

    public function test_管理画面のrouteと動詞(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'admin/redirect')
                || str_starts_with($route->uri(), 'admin/not-found'),
        ));

        $actual = array_map(
            static fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $routes,
        );

        sort($actual);

        $this->assertSame([
            'GET admin/not-found',
            'GET admin/redirects',
            'GET admin/redirects/simulate',
            'PATCH admin/redirects/{id}',
            'POST admin/not-found/{id}/resolve',
            'POST admin/redirects',
            'POST admin/redirects/{id}/delete',
        ], $actual);

        foreach ($routes as $route) {
            $this->assertNotContains('DELETE', $route->methods());
        }
    }

    public function test_画面はブラウザのconfirmもv_htmlも使わない(): void
    {
        foreach ([
            'js/pages/admin/redirects/Index.vue',
            'js/pages/admin/redirects/NotFound.vue',
        ] as $rel) {
            $source = self::withoutComments((string) file_get_contents(resource_path($rel)));

            $this->assertDoesNotMatchRegularExpression('/(^|[^A-Za-z])confirm\s*\(/m', $source, $rel);
            $this->assertStringNotContainsString('v-html', $source, $rel);
        }
    }

    public function test_301の注意とシミュレータが画面にある(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/admin/redirects/Index.vue'));

        // 踏まずに確かめられること
        $this->assertStringContainsString('redirect-simulate', $source);
        // 301 は戻しにくいことを伝えること
        $this->assertStringContainsString('redirect-sticky-warning', $source);
    }

    // ── helpers ────────────────────────────────────────────

    private function create(string $source, string $target): void
    {
        $this->actingAs($this->owner)->post('/admin/redirects', [
            'source_path' => $source, 'target_url' => $target,
            'match_type' => 'exact', 'status_code' => 301,
        ])->assertRedirect();
    }

    private static function withoutComments(string $source): string
    {
        return (string) preg_replace(
            ['#/\*[\s\S]*?\*/#', '#(^|[^:])//[^\n]*#m', '#<!--[\s\S]*?-->#'],
            ['', '$1', ''],
            $source,
        );
    }
}
