<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\RestoreJournal;
use AgentCms\Core\Backups\RestoreProbe;
use AgentCms\Core\Backups\RestoreRecovery;
use AgentCms\Core\Media\MediaKind;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;
use Throwable;

/**
 * 復元の**途中でプロセスごと消える**（SIGKILL）と、そこからの回復（release R09・2026-09-09）。
 *
 * 例外では unwind が走るので `catch` の巻き戻しで隠れる。ここでは `pcntl_fork()` した
 * 子が復元を進め、`RestoreProbe` の節目で **自分に SIGKILL** を送る。子は何も報告できない。
 * 親は残ったもの（journal・DB の印・実物）だけから整える — 本番と同じ条件である。
 *
 * DB は **ファイルの SQLite**。`:memory:` は fork で複製されて親に commit が見えない。
 * `RefreshDatabase` は使わない（transaction で包まれると commit の有無を作れない）。
 */
final class BackupRestoreCrashTest extends TestCase
{
    private string $sandbox = '';

    private string $connection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl / posix が無い環境（Windows）では、プロセスごと消える形は作れない');
        }

        $this->sandbox = rtrim(sys_get_temp_dir(), '/').'/sgk-restore-crash-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->sandbox, 0700, true);

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');

        // 実MySQLは専用の使い捨てDBだけを許可し、それ以外はファイルSQLiteで確認する。
        $this->connection = (string) config('database.default');
        if ($this->connection === 'mysql') {
            $this->assertSame('sgk_backup_qa', config('database.connections.mysql.database'), '強制終了試験は専用DBに限定する');
            DB::purge($this->connection);
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

            return;
        }
        $this->assertSame('sqlite', $this->connection);
        // ファイルの SQLite（親子で同じ DB を見る）
        config()->set('database.connections.sqlite.database', $this->sandbox.'/db.sqlite');
        touch($this->sandbox.'/db.sqlite');
        DB::purge($this->connection);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::purge($this->connection);
        self::removeTree($this->sandbox);

        parent::tearDown();
    }

    /** rename の直後（commit 前）に消える → DB は復元前・メディアだけ入れ替わっている → recover が戻す */
    public function test_rename直後にsigkillされてもrecoverで復元前へ戻る(): void
    {
        [$id, $mediaId, $owner] = $this->backupThenChange();

        $this->restoreInChildKilledAt($id, RestoreProbe::AFTER_MOVE);

        // 子は何も報告できない。残ったものだけがある
        $this->assertTrue(RestoreJournal::isPending());
        $this->assertFalse(RestoreRecovery::committed((string) RestoreJournal::pending()?->restoreId()));
        // DB は復元前（transaction は閉じられなかった）
        $this->assertSame('renamed', DB::table('media')->where('id', $mediaId)->value('name'));
        $this->assertSame(7, (int) DB::table('users')->where('id', $owner)->value('session_epoch'));
        // メディアは 1 手目だけ動いた（現物が退避されている）
        $this->assertFileDoesNotExist($this->imageDir().'/photo.png');

        // 整えるまで HTTP は 503
        $this->get('/')->assertStatus(503);

        $this->artisan('agentcms:backup-recover')->assertSuccessful();

        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('overwritten', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileExists($this->imageDir().'/later.png');
        $this->assertFileExists($this->fileDir().'/later.pdf');
        $this->assertSame('renamed', DB::table('media')->where('id', $mediaId)->value('name'));
        $this->assertSame([], glob($this->sandbox.'/backups/restore-*') ?: []);
        $this->get('/')->assertOk();
    }

    /** commit の直後（cache を捨てる前）に消える → DB もメディアも復元後 → recover は戻さず仕上げる */
    public function test_commit直後にsigkillされてもrecoverはファイルを戻さず仕上げる(): void
    {
        [$id, $mediaId, $owner] = $this->backupThenChange();

        $this->restoreInChildKilledAt($id, RestoreProbe::AFTER_COMMIT);

        $this->assertTrue(RestoreJournal::isPending());
        $this->assertTrue(RestoreRecovery::committed((string) RestoreJournal::pending()?->restoreId()));
        $this->get('/')->assertStatus(503);

        $this->artisan('agentcms:backup-recover')->assertSuccessful();

        $this->assertFalse(RestoreJournal::isPending());
        // DB は復元後。session の世代は復元前（7）と復元後（5）のどちらより大きい
        $this->assertSame('photo', DB::table('media')->where('id', $mediaId)->value('name'));
        $this->assertSame(8, (int) DB::table('users')->where('id', $owner)->value('session_epoch'));
        // メディアも復元後のまま（戻していない）。退避したものは消していない
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileDoesNotExist($this->imageDir().'/later.png');
        $this->assertCount(1, glob($this->sandbox.'/backups/.replaced/*/media/image/later.png') ?: []);
        $this->get('/')->assertOk();
    }

    // ── helpers ─────────────────────────────────────────────

    /**
     * fork した子で復元を進め、節目で SIGKILL する。親は子の終了を待って DB へつなぎ直す。
     */
    private function restoreInChildKilledAt(string $id, string $point): void
    {
        $errorFile = $this->sandbox.'/child-error.txt';

        // 接続中のMySQLソケットを親子で共有しない。
        DB::disconnect($this->connection);
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('fork できない');
        }

        if ($pid === 0) {
            // ── 子 ──────────────────────────────────────────
            try {
                DB::purge($this->connection);

                app()->instance(RestoreProbe::class, new class($point) implements RestoreProbe
                {
                    public function __construct(private readonly string $point) {}

                    public function at(string $point): void
                    {
                        if ($point === $this->point) {
                            posix_kill((int) getmypid(), SIGKILL);
                        }
                    }
                });

                app(BackupRestorer::class)->restore($id);
                file_put_contents($errorFile, '観測点に到達せず復元が完了した');
            } catch (Throwable $e) {
                file_put_contents($errorFile, $e::class.': '.$e->getMessage());
            }

            // ここへ来たら失敗。PHPUnit の中へ戻らずに消える
            posix_kill((int) getmypid(), SIGKILL);
        }

        // ── 親 ──────────────────────────────────────────
        pcntl_waitpid($pid, $status);
        DB::purge($this->connection);

        $this->assertTrue(pcntl_wifsignaled($status), '子が SIGKILL で消えていない');
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $this->assertFileDoesNotExist($errorFile, is_file($errorFile) ? (string) file_get_contents($errorFile) : '');
    }

    /** @return array{0: string, 1: int, 2: int} backup id / media id / owner id */
    private function backupThenChange(): array
    {
        $owner = User::factory()->create(['role' => Role::Owner->value, 'session_epoch' => 5]);
        $this->image('photo.png', 'original');
        $this->file('doc.pdf', 'pdf bytes');
        $mediaId = (int) DB::table('media')->insertGetId([
            'name' => 'photo', 'path' => 'media/photo.png', 'mime' => 'image/png', 'size' => 8,
            'width' => 1, 'height' => 1, 'kind' => 'image', 'visibility' => 'public', 'disk' => 'public',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = app(BackupBuilder::class)->create()->id;

        file_put_contents($this->imageDir().'/photo.png', 'overwritten');
        $this->image('later.png', 'later');
        $this->file('later.pdf', 'later');
        DB::table('media')->where('id', $mediaId)->update(['name' => 'renamed']);
        DB::table('users')->where('id', $owner->getKey())->update(['session_epoch' => 7]);

        return [$id, $mediaId, (int) $owner->getKey()];
    }

    private function imageDir(): string
    {
        return Storage::disk(MediaKind::Image->disk())->path(MediaKind::Image->directory());
    }

    private function fileDir(): string
    {
        return Storage::disk(MediaKind::File->disk())->path(MediaKind::File->directory());
    }

    private function image(string $relative, string $contents): void
    {
        Storage::disk(MediaKind::Image->disk())->put(MediaKind::Image->directory().'/'.$relative, $contents);
    }

    private function file(string $relative, string $contents): void
    {
        Storage::disk(MediaKind::File->disk())->put(MediaKind::File->directory().'/'.$relative, $contents);
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
