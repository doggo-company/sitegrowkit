<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRunLauncher;
use AgentCms\Core\Backups\BackupRunState;
use AgentCms\Core\Backups\BackupRunStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 画面からの取得（2026-09-09・OG3 改訂）— 誰が始められて、始めたら何が残るか。
 *
 * 別プロセスの起動は Fake に差し替える（本物は `php artisan` を実際に起こす）。
 * 起動から完了までの一続きは、別プロセスが打つのと同じ artisan をこの process で走らせて見る。
 */
final class BackupStartAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    private User $owner;

    private FakeBackupRunLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = rtrim(sys_get_temp_dir(), '/').'/sgk-backup-start-'.getmypid().'-'.bin2hex(random_bytes(3));

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');

        $this->launcher = new FakeBackupRunLauncher;
        $this->app->instance(BackupRunLauncher::class, $this->launcher);

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->sandbox);

        parent::tearDown();
    }

    public function test_一覧はownerに取得の導線と段階を渡す(): void
    {
        $this->actingAs($this->owner)->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups/Index')
                ->where('canCreate', true)
                ->where('createUnavailableReason', null)
                ->where('run', null)
                ->has('stages', 5)
                // コマンド表示は無くさない（画面から始められない環境の代替）
                ->where('command', 'php artisan agentcms:backup'),
            );
    }

    public function test_ownerは画面から取得を始められる(): void
    {
        $this->actingAs($this->owner)
            ->from('/admin/backups')
            ->post('/admin/backups')
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('success');

        $this->assertCount(1, $this->launcher->launched);

        $run = app(BackupRunStore::class)->find($this->launcher->launched[0]);

        $this->assertSame(BackupRunState::QUEUED, $run?->status);
        $this->assertSame((int) $this->owner->getKey(), $run?->requestedBy);
        // 起動した process の pid を記録する（読む側の生存確認に使う）
        $this->assertSame(getmypid(), $run?->pid);
        // HTTP の中では作らない
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
    }

    public function test_管理者は始められない(): void
    {
        $admin = User::factory()->create(['role' => Role::Administrator->value]);

        $this->actingAs($admin)->post('/admin/backups')->assertForbidden();
        $this->assertSame([], $this->launcher->launched);

        // 導線も出ない
        $this->actingAs($admin)->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->where('run', null));
    }

    public function test_連打しても2つ目は始まらない(): void
    {
        $this->actingAs($this->owner)->post('/admin/backups')->assertRedirect();

        $this->actingAs($this->owner)
            ->from('/admin/backups')
            ->post('/admin/backups')
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('error');

        $this->assertCount(1, $this->launcher->launched);
    }

    public function test_起動できない環境では理由を出して始めない(): void
    {
        $this->launcher->unavailable = 'このサーバーでは PHP の proc_open が無効なため、画面からは取得を始められません';

        $this->actingAs($this->owner)->get('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->where('createUnavailableReason', $this->launcher->unavailable),
            );

        $this->actingAs($this->owner)
            ->from('/admin/backups')
            ->post('/admin/backups')
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('error', $this->launcher->unavailable);

        $this->assertSame([], $this->launcher->launched);
        $this->assertNull(app(BackupRunStore::class)->latest());
    }

    public function test_起動に失敗したら失敗として残り次を始められる(): void
    {
        $this->launcher->failWith = '取得の処理を別プロセスで起動できませんでした';

        $this->actingAs($this->owner)
            ->from('/admin/backups')
            ->post('/admin/backups')
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('error', $this->launcher->failWith);

        $run = app(BackupRunStore::class)->latest();

        $this->assertSame(BackupRunState::FAILED, $run?->status);
        $this->assertSame($this->launcher->failWith, $run?->error);
        // 無限の「処理中」にならず、lock も残らない
        $this->assertFileDoesNotExist(BackupPaths::runLock());

        $this->launcher->failWith = null;
        $this->actingAs($this->owner)->post('/admin/backups')->assertSessionHas('success');
    }

    public function test_進みはownerだけが読める(): void
    {
        $this->actingAs($this->owner)->post('/admin/backups');
        $runId = $this->launcher->launched[0];

        $this->actingAs($this->owner)->getJson("/admin/backups/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('run.run_id', $runId)
            ->assertJsonPath('run.status', 'queued');

        $admin = User::factory()->create(['role' => Role::Administrator->value]);
        $this->actingAs($admin)->getJson("/admin/backups/runs/{$runId}")->assertForbidden();

        $this->actingAs($this->owner)->getJson('/admin/backups/runs/no-such-run')->assertNotFound();
    }

    /**
     * 始める → 別プロセスと同じ artisan が進める → 画面が done と新しい backup を読む。
     */
    public function test_始めてから完了まで一続きで進む(): void
    {
        $this->actingAs($this->owner)->post('/admin/backups');
        $runId = $this->launcher->launched[0];

        $this->artisan('agentcms:backup-run', ['run' => $runId])->assertSuccessful();

        $response = $this->actingAs($this->owner)->getJson("/admin/backups/runs/{$runId}")->assertOk();
        $backupId = (string) $response->json('run.backup_id');

        $this->assertSame('done', $response->json('run.status'));
        $this->assertTrue(app(BackupCatalog::class)->exists($backupId));

        // 一覧に載り、いちばん新しい run として画面に渡る
        $this->actingAs($this->owner)->get('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->has('backups', 1)
                ->where('backups.0.id', $backupId)
                ->where('run.status', 'done')
                ->where('run.backup_id', $backupId),
            );

        // 終わったので次を始められる
        $this->actingAs($this->owner)->post('/admin/backups')->assertSessionHas('success');
    }

    public function test_内部コマンドは知らないrunを走らせない(): void
    {
        $this->artisan('agentcms:backup-run', ['run' => 'nope'])->assertFailed();
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
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

/** 起動を記録するだけ。本物は `php artisan` を実際に起こす */
final class FakeBackupRunLauncher implements BackupRunLauncher
{
    /** @var list<string> */
    public array $launched = [];

    public ?string $unavailable = null;

    public ?string $failWith = null;

    public function unavailableReason(): ?string
    {
        return $this->unavailable;
    }

    public function launch(string $runId): ?int
    {
        if ($this->failWith !== null) {
            throw new BackupFailed($this->failWith);
        }

        $this->launched[] = $runId;

        // 生きている pid を返す（読む側は pid が消えていれば failed に落とす）
        return getmypid() ?: null;
    }
}
