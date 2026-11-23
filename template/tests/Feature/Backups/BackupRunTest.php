<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupProgress;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\BackupRunner;
use AgentCms\Core\Backups\BackupRunState;
use AgentCms\Core\Backups\BackupRunStore;
use AgentCms\Core\Backups\BackupStage;
use AgentCms\Core\Backups\DatabaseDump;
use AgentCms\Core\Backups\ProcessBackupRunLauncher;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 画面から始めた取得の「状態」と「別プロセス側の実行」（2026-09-09・OG3 改訂）。
 *
 * ここで固定するのは
 *   - 進みは**実測**（段階の順序と、数えた数）だけが記録されること
 *   - `done` は zip と manifest と「戻せる」を確かめた後にしか付かないこと
 *   - 止まった run は「処理中」のまま残らないこと（起動待ち超過・heartbeat 途絶・process 消失）
 *   - 同時に 1 つしか始められないこと
 */
final class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // 使い捨ての置き場所は /tmp 配下（tests/ や storage/ に残さない）
        $this->sandbox = rtrim(sys_get_temp_dir(), '/').'/sgk-backup-run-'.getmypid().'-'.bin2hex(random_bytes(3));

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->sandbox);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_lock取得直後の状態未保存の隙間でも二重起動させない(): void
    {
        $store = app(BackupRunStore::class);
        $this->assertNull($store->acquire('run-first'));
        // 呼び出し側がまだ queued を保存していないタイミングでも、二人目は始められない。
        $blocked = (new BackupRunStore)->acquire('run-second');
        $this->assertSame('run-first', $blocked?->runId);
        $this->assertTrue($blocked?->isActive());
    }

    public function test_遅れて来た_pi_dや進捗で完了を起動待ちに戻さない(): void
    {
        $store = app(BackupRunStore::class);
        $queued = BackupRunState::queued('run-finished', 1);
        $store->save($queued);
        $store->save($queued->done('backup-finished'));
        $store->recordPidIfQueued('run-finished', 999);
        $store->save($queued->withPid(999));
        $store->save($queued->running(BackupStage::Database));
        $this->assertSame(BackupRunState::DONE, $store->find('run-finished')?->status);
        $this->assertSame('backup-finished', $store->find('run-finished')?->backupId);
    }

    // ── 進みは実測だけ ──────────────────────────────────────

    public function test_builderは段階を順に報告し数は実測(): void
    {
        $this->media('a.jpg', 'x');
        $this->media('b.jpg', 'y');

        $recorder = new RecordingProgress;
        app(BackupBuilder::class)->create('', $recorder);

        $this->assertSame(
            [BackupStage::Database, BackupStage::Media, BackupStage::SecretScan, BackupStage::Archive],
            $recorder->stages,
        );

        // DB は「表 n / 全体」— 全体は実際の表の数
        $database = array_values(array_filter($recorder->advances, fn (array $a) => $a[0] === BackupStage::Database));
        $this->assertNotEmpty($database);
        $this->assertSame(count(app(DatabaseDump::class)->tables()), $database[0][2]);

        // メディアは写した数だけ。総数は数えていないので null（架空の総数を作らない）
        $media = array_values(array_filter($recorder->advances, fn (array $a) => $a[0] === BackupStage::Media));
        $this->assertSame([BackupStage::Media, 2, null], end($media));
    }

    // ── 実行と結末 ──────────────────────────────────────────

    public function test_runnerは最後まで進めてdoneにする(): void
    {
        $store = app(BackupRunStore::class);
        $store->save(BackupRunState::queued('run-a', 1));

        $state = app(BackupRunner::class)->run('run-a', getmypid() ?: null);

        $this->assertSame(BackupRunState::DONE, $state->status);
        $this->assertSame(BackupStage::Verify->value, $state->stage);
        $this->assertNotNull($state->backupId);
        $this->assertFileExists(BackupPaths::archive((string) $state->backupId));
        $this->assertNotNull(app(BackupCatalog::class)->read((string) $state->backupId));

        // 状態はファイルに残る（開き直しても読める）
        $this->assertSame(BackupRunState::DONE, $store->find('run-a')?->status);
    }

    public function test_秘密が混ざればfailedになり成果物を残さない(): void
    {
        $this->media('leaked.key', 'secret');

        $store = app(BackupRunStore::class);
        $store->save(BackupRunState::queued('run-b', 1));

        $state = app(BackupRunner::class)->run('run-b');

        $this->assertSame(BackupRunState::FAILED, $state->status);
        $this->assertStringContainsString('秘密情報', (string) $state->error);
        $this->assertNull($state->backupId);
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
    }

    /**
     * 「作れた」だけでは成功にしない。zip が無ければ done にならない。
     *
     * verify 段階に入った瞬間に zip を消す store を差し込み、
     * `BackupRunner::assertUsable` が本当にファイルを確かめていることを見る。
     */
    public function test_zipが無ければdoneにしない(): void
    {
        $store = new class extends BackupRunStore
        {
            public function save(BackupRunState $state): void
            {
                if ($state->stage === BackupStage::Verify->value && $state->status === BackupRunState::RUNNING) {
                    foreach (glob(BackupPaths::root().'/*.zip') ?: [] as $zip) {
                        unlink($zip);
                    }
                }

                parent::save($state);
            }
        };

        $store->save(BackupRunState::queued('run-c', 1));

        $runner = new BackupRunner(
            app(BackupBuilder::class),
            app(BackupCatalog::class),
            app(BackupRestorer::class),
            $store,
        );

        $state = $runner->run('run-c');

        $this->assertSame(BackupRunState::FAILED, $state->status);
        $this->assertStringContainsString('見つかりません', (string) $state->error);
    }

    public function test_終わったrunはもう一度走らせても何もしない(): void
    {
        $store = app(BackupRunStore::class);
        $store->save(BackupRunState::queued('run-d', 1)->failed('x'));

        $state = app(BackupRunner::class)->run('run-d');

        $this->assertSame(BackupRunState::FAILED, $state->status);
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
    }

    // ── 止まったものを「処理中」のままにしない ──────────────

    public function test_起動待ちが長すぎればfailedに落とす(): void
    {
        config()->set('agentcms.backup.run_start_timeout', 120);
        $store = app(BackupRunStore::class);

        Carbon::setTestNow('2026-09-09 10:00:00');
        $this->assertNull($store->acquire('run-e'));
        $store->save(BackupRunState::queued('run-e', 1));

        Carbon::setTestNow('2026-09-09 10:01:00');
        $this->assertSame(BackupRunState::QUEUED, $store->current('run-e')?->status);

        Carbon::setTestNow('2026-09-09 10:03:00');
        $state = $store->current('run-e');

        $this->assertSame(BackupRunState::FAILED, $state?->status);
        $this->assertStringContainsString('始まりませんでした', (string) $state?->error);
        // 落とした時点で lock も外れる（次の取得が始められる）
        $this->assertFileDoesNotExist(BackupPaths::runLock());
        $this->assertNull($store->acquire('run-e2'));
    }

    public function test_heartbeatが途絶えればfailedに落とす(): void
    {
        config()->set('agentcms.backup.run_stale_after', 600);
        $store = app(BackupRunStore::class);

        Carbon::setTestNow('2026-09-09 10:00:00');
        $store->save(BackupRunState::queued('run-f', 1)->running(BackupStage::Media, 12));

        Carbon::setTestNow('2026-09-09 10:05:00');
        $this->assertSame(BackupRunState::RUNNING, $store->current('run-f')?->status);

        Carbon::setTestNow('2026-09-09 10:11:00');
        $state = $store->current('run-f');

        $this->assertSame(BackupRunState::FAILED, $state?->status);
        $this->assertStringContainsString('応答がありません', (string) $state?->error);
    }

    public function test_processが消えていればすぐfailedに落とす(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('posix が無い環境では pid で判定しない');
        }

        $store = app(BackupRunStore::class);
        // 存在しない pid（上限近く）。heartbeat は新しいのに process が居ない
        $store->save(BackupRunState::queued('run-g', 1)->running(BackupStage::Database, 1, 10)->withPid(4194000));

        $state = $store->current('run-g');

        $this->assertSame(BackupRunState::FAILED, $state?->status);
        $this->assertStringContainsString('プロセスが終了', (string) $state?->error);
    }

    // ── 同時に 1 つ ─────────────────────────────────────────

    public function test_走っている間は2つ目を始められない(): void
    {
        $store = app(BackupRunStore::class);

        $this->assertNull($store->acquire('run-h'));
        $store->save(BackupRunState::queued('run-h', 1)->running(BackupStage::Database));

        $blocking = $store->acquire('run-i');

        $this->assertSame('run-h', $blocking?->runId);
        $this->assertNull($store->find('run-i'));

        // 終われば次を始められる
        $store->save($store->find('run-h')->done('20260909-100000-000-abcdef'));
        $this->assertNull($store->acquire('run-i'));
    }

    public function test_他人のlockは外さない(): void
    {
        $store = app(BackupRunStore::class);
        $this->assertNull($store->acquire('run-j'));

        $store->release('run-k');

        $this->assertFileExists(BackupPaths::runLock());
    }

    public function test_おかしなidは受け付けない(): void
    {
        $store = app(BackupRunStore::class);

        $this->assertNull($store->find('../etc/passwd'));
        $this->assertFalse(BackupRunStore::isValidRunId('../x'));
    }

    // ── 起動できない環境 ─────────────────────────────────────

    public function test_proc_openが塞がれていれば理由を返す(): void
    {
        $launcher = new ProcessBackupRunLauncher(disabledFunctions: 'exec, proc_open ,shell_exec');

        $this->assertStringContainsString('proc_open', (string) $launcher->unavailableReason());
    }

    public function test_この環境では起動できる(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open が無い');
        }

        $this->assertNull((new ProcessBackupRunLauncher(disabledFunctions: ''))->unavailableReason());
    }

    // ── helpers ──────────────────────────────────────────────

    private function media(string $name, string $contents): void
    {
        Storage::disk(MediaKind::Image->disk())->put(MediaKind::Image->directory().'/'.$name, $contents);
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

final class RecordingProgress implements BackupProgress
{
    /** @var list<BackupStage> */
    public array $stages = [];

    /** @var list<array{0: BackupStage, 1: int, 2: int|null}> */
    public array $advances = [];

    public function stage(BackupStage $stage): void
    {
        $this->stages[] = $stage;
    }

    public function advance(BackupStage $stage, int $done, ?int $total = null): void
    {
        $this->advances[] = [$stage, $done, $total];
    }
}
