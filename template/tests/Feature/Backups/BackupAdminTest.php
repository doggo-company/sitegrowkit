<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Application\Backups\DeleteBackup;
use AgentCms\Core\Application\Backups\RestoreBackup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * backup の管理画面と権限（Phase 9.11 / STAGE 24D）。
 *
 * ここで固定するのは **誰が何をできるか**である。
 * 復元と持ち出しは Owner だけ — ほかの権限と分けた理由がここで効く。
 */
final class BackupAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // 本物の storage を触らない（`BackupTest` と同じ理由）
        $sandbox = storage_path('framework/testing/backups-admin-'.getmypid());

        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root)) {
            self::removeTree($root);
        }

        parent::tearDown();
    }

    public function test_一覧は取得コマンドも渡す(): void
    {
        app(BackupBuilder::class)->create();

        $this->actingAs($this->owner)->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups/Index')
                ->has('backups', 1)
                // 「どうやって取るのか分からない」を残さない（OG3）
                ->where('command', 'php artisan agentcms:backup')
                ->where('canRestore', true),
            );
    }

    public function test_検証は何も変えない(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->actingAs($this->owner)->getJson("/admin/backups/{$manifest->id}/verify")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertTrue(app(BackupCatalog::class)->exists($manifest->id));
    }

    public function test_復元は2段階でsessionが切れる(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        // 古いbackupのepoch + 1が現在のsessionと一致しても、必ず失効させる。
        DB::table('users')->where('id', $this->owner->getKey())->update(['session_epoch' => 1]);
        $this->owner->refresh();
        $before = (int) DB::table('users')->where('id', $this->owner->getKey())->value('session_epoch');

        $this->actingAs($this->owner)->post("/admin/backups/{$manifest->id}/restore")->assertRedirect();
        $this->assertSame($before, (int) DB::table('users')->where('id', $this->owner->getKey())->value('session_epoch'));

        $token = (string) app(RestoreBackup::class)
            ->execute($manifest->id, $this->context())
            ->confirmation?->token;

        $this->actingAs($this->owner)
            ->post("/admin/backups/{$manifest->id}/restore", ['confirmation_token' => $token])
            ->assertRedirect('/login');

        // 戻す前の権限のまま操作できる状態を作らない（OG12）
        $this->assertGreaterThan(
            $before,
            (int) DB::table('users')->where('id', $this->owner->getKey())->value('session_epoch'),
        );
    }

    public function test_削除は2段階(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->actingAs($this->owner)->post("/admin/backups/{$manifest->id}/delete")->assertRedirect();
        $this->assertTrue(app(BackupCatalog::class)->exists($manifest->id));

        $token = (string) app(DeleteBackup::class)
            ->execute($manifest->id, $this->context())
            ->confirmation?->token;

        $this->actingAs($this->owner)
            ->post("/admin/backups/{$manifest->id}/delete", ['confirmation_token' => $token])
            ->assertRedirect();

        $this->assertFalse(app(BackupCatalog::class)->exists($manifest->id));
    }

    public function test_持ち出せるのはownerだけ(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        $admin = User::factory()->create(['role' => Role::Administrator->value]);

        // 中には利用者のパスワード hash と問い合わせの本文が入っている
        $this->actingAs($admin)->get("/admin/backups/{$manifest->id}/download")->assertForbidden();

        $this->actingAs($this->owner)->get("/admin/backups/{$manifest->id}/download")
            ->assertOk()
            ->assertDownload($manifest->id.'.zip');
    }

    public function test_管理者は復元できない(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        $admin = User::factory()->create(['role' => Role::Administrator->value]);

        // 一覧は見えるが、復元の導線は出ない
        $this->actingAs($admin)->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRestore', false)->where('canDelete', true));

        // 画面で隠すだけにしない。経路も塞ぐ
        $this->actingAs($admin)->post("/admin/backups/{$manifest->id}/restore")->assertForbidden();
    }

    public function test_編集者は開けない(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);

        $this->actingAs($editor)->get('/admin/backups')->assertOk();
        $this->actingAs($editor)->post('/admin/backups/nope/restore')->assertForbidden();
    }

    public function test_未認証では開けない(): void
    {
        $this->get('/admin/backups')->assertRedirect('/login');
    }

    public function test_おかしなidは404(): void
    {
        // route の制約でそもそも当たらない（`..` でディレクトリを抜け出せない）
        $this->actingAs($this->owner)->get('/admin/backups/../../etc/passwd/download')->assertNotFound();
    }

    /**
     * route の全数を固定する（2026-09-09・OG3 改訂）。
     *
     * 取得は `POST admin/backups` から始められるが、**HTTP の中では取らない** —
     * 別プロセスを起動して記録を残すだけで返る（`BackupStartAdminTest`）。
     * 進みは `GET admin/backups/runs/{run}`。それ以外に取得の口を増やしていないことを見る。
     */
    public function test_取得のrouteは起動と進みの2本だけ(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'admin/backups'),
        ));

        $actual = array_map(
            static fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $routes,
        );

        sort($actual);

        $this->assertSame([
            'GET admin/backups',
            'GET admin/backups/runs/{run}',
            'GET admin/backups/{id}/download',
            'GET admin/backups/{id}/verify',
            'POST admin/backups',
            'POST admin/backups/{id}/delete',
            'POST admin/backups/{id}/restore',
        ], $actual);
    }

    public function test_aiへ公開するのは参照だけ(): void
    {
        $ids = OperationCatalog::ids();

        $this->assertContains('backup.list', $ids);
        $this->assertContains('backup.verify', $ids);
        // サイト全体を過去へ戻す操作を AI へ渡さない
        $this->assertNotContains('backup.restore', $ids);
        $this->assertNotContains('backup.delete', $ids);
        $this->assertNotContains('backup.download', $ids);
    }

    public function test_画面はブラウザのconfirmもv_htmlも使わない(): void
    {
        $source = self::withoutComments(
            (string) file_get_contents(resource_path('js/pages/admin/backups/Index.vue')),
        );

        $this->assertDoesNotMatchRegularExpression('/(^|[^A-Za-z])confirm\s*\(/m', $source);
        $this->assertStringNotContainsString('v-html', $source);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private static function withoutComments(string $source): string
    {
        return (string) preg_replace(
            ['#/\*[\s\S]*?\*/#', '#(^|[^:])//[^\n]*#m', '#<!--[\s\S]*?-->#'],
            ['', '$1', ''],
            $source,
        );
    }

    private static function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
