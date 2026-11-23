<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupLock;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\RestoreJournal;
use AgentCms\Core\Backups\RestoreProbe;
use AgentCms\Core\Backups\RestoreRecovery;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * 復元の中断からの回復・取得と復元の排他・session 世代（release R09・2026-09-09）。
 *
 * ここで固定するのは
 *   - rename の後・commit の前に落ちたら **DB もメディアも復元前**、journal は履歴へ
 *   - commit の後に落ちたら **ファイルは戻さない**（DB とずらさない）。journal が残り、
 *     HTTP は 503、取得も復元も始められず、`agentcms:backup-recover` が仕上げる
 *   - 取得と復元は **プロセスをまたいで** 同時に 1 つ。取れなければすぐ止まる
 *   - session の世代は **復元前・復元後のどちらより大きい**（同じ transaction で）
 *
 * 落とし方は `RestoreProbe` を container へ bind して決定的に作る（env や設定では作れない）。
 * プロセスごと消える形（SIGKILL）は `BackupRestoreCrashTest`。
 */
final class BackupRestoreRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = rtrim(sys_get_temp_dir(), '/').'/sgk-restore-recovery-'.getmypid().'-'.bin2hex(random_bytes(3));

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->sandbox);

        parent::tearDown();
    }

    // ── 例外で止まる（commit 前）────────────────────────────

    public function test_renameの後commit前に落ちたらdbもメディアも復元前でjournalは履歴へ(): void
    {
        [$id, $mediaId] = $this->backupThenChange();

        $this->failAt(RestoreProbe::AFTER_MOVE);

        try {
            app(BackupRestorer::class)->restore($id);
            $this->fail('落ちたのに成功扱いになった');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('復元前のまま', $e->getMessage());
        }

        $this->assertBeforeRestore($mediaId);
        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('rolled_back', $this->historyOutcome());
        $this->assertSame([], glob(BackupPaths::root().'/restore-*') ?: []);
        $this->assertSame(0, DB::table(RestoreRecovery::MARKER_TABLE)->count(), 'commit されていないのに印が残っている');
    }

    public function test_印を書いた後commit前に落ちても印は残らず全部復元前(): void
    {
        [$id, $mediaId] = $this->backupThenChange();

        $this->failAt(RestoreProbe::BEFORE_COMMIT);

        $this->assertThrows(fn () => app(BackupRestorer::class)->restore($id), BackupFailed::class);

        $this->assertBeforeRestore($mediaId);
        $this->assertSame(0, DB::table(RestoreRecovery::MARKER_TABLE)->count());
        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('rolled_back', $this->historyOutcome());
    }

    // ── 例外で止まる（commit 後）────────────────────────────

    /**
     * 🔴 commit の後で落ちたら **ファイルを戻してはいけない**。
     *
     * 以前は catch が journal を無条件に逆順で戻していた。cache を捨てるところで
     * 落ちると、DB は復元後なのにメディアだけ復元前へ戻る。
     */
    public function test_commit後に落ちてもファイルは戻さずjournalが残りrecoverが仕上げる(): void
    {
        [$id, $mediaId] = $this->backupThenChange();
        app(SettingsStore::class)->put(['site_name' => '壊れた名前']);
        $this->assertSame('壊れた名前', app(SettingsStore::class)->get('site_name'));

        $this->failAt(RestoreProbe::AFTER_COMMIT);

        try {
            app(BackupRestorer::class)->restore($id);
            $this->fail('落ちたのに成功扱いになった');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('agentcms:backup-recover', $e->getMessage());
        }

        // DB もメディアも復元後のまま（戻していない）
        $this->assertAfterRestore($mediaId);
        $this->assertTrue(RestoreJournal::isPending());
        $this->assertSame(1, DB::table(RestoreRecovery::MARKER_TABLE)->count());

        // 整えるまで HTTP は止まる。取得も復元も始められない
        // 訪問者には整備中とだけ言う。コマンドや置き場所は出さない
        $public = $this->get('/')->assertStatus(503)->assertHeader('Retry-After');
        $this->assertStringNotContainsString('agentcms:backup-recover', (string) $public->getContent());
        $this->assertStringNotContainsString($this->sandbox, (string) $public->getContent());
        $this->assertStringContainsString('整備', (string) $public->getContent());
        $this->getJson('/admin/backups')->assertStatus(503)
            ->assertJsonPath('message', fn (string $m): bool => ! str_contains($m, 'agentcms:backup-recover') && ! str_contains($m, 'storage'));
        $this->assertThrows(fn () => app(BackupBuilder::class)->create(), BackupFailed::class);
        $this->assertThrows(fn () => app(BackupRestorer::class)->restore($id), BackupFailed::class);

        // --status は何も変えない
        $this->artisan('agentcms:backup-recover', ['--status' => true])
            ->expectsOutputToContain('仕上げる')
            ->assertSuccessful();
        $this->assertTrue(RestoreJournal::isPending());

        $this->artisan('agentcms:backup-recover')->assertSuccessful();

        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('completed_by_recovery', $this->historyOutcome());
        $this->assertAfterRestore($mediaId);
        // 後始末（cache）も済んでいる
        $this->assertSame('沼津どうぶつ病院', app(SettingsStore::class)->get('site_name'));
        $this->assertSame([], glob(BackupPaths::root().'/restore-*') ?: []);
        $this->get('/')->assertOk();
    }

    // ── 残った journal の扱い ───────────────────────────────

    public function test_残っていなければrecoverは何もしない(): void
    {
        $this->artisan('agentcms:backup-recover')
            ->expectsOutputToContain('ありません')
            ->assertSuccessful();
    }

    /** rename が起きていない手（from だけ在る）は飛ばし、起きた手だけ戻す。何度やっても同じ */
    public function test_recoverの巻き戻しは実物を見て何度でも同じ結果になる(): void
    {
        $this->image('photo.png', 'original');
        $manifest = app(BackupBuilder::class)->create();

        $workspace = BackupPaths::workspace('restore-manual');
        mkdir($workspace.'/media/image', 0700, true);
        file_put_contents($workspace.'/media/image/photo.png', 'from backup');
        $parked = BackupPaths::disk()->path(BackupPaths::REPLACED).'/manual/media/image';
        mkdir(dirname($parked), 0700, true);

        $journal = RestoreJournal::begin('restore-manual', $manifest->id, 'pre', $workspace, [
            ['root' => 'media/image', 'source' => $workspace.'/media/image', 'target' => $this->imageDir(), 'parked' => $parked, 'target_existed' => true],
        ]);
        // 1 手目は起きた（現物 → 退避）。2 手目は意図だけ残して消えた
        $journal->step($this->imageDir(), $parked);
        rename($this->imageDir(), $parked);
        $journal->step($workspace.'/media/image', $this->imageDir());

        $this->assertFileDoesNotExist($this->imageDir().'/photo.png');

        $this->artisan('agentcms:backup-recover')->assertSuccessful();

        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFalse(RestoreJournal::isPending());
        $this->assertDirectoryDoesNotExist($workspace);

        // もう一度やっても何も起きない
        $this->artisan('agentcms:backup-recover')->assertSuccessful();
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
    }

    public function test_壊れたjournalは黙って無いことにしない(): void
    {
        mkdir(RestoreJournal::directory(), 0700, true);
        file_put_contents(RestoreJournal::path(), '{broken');

        $this->get('/')->assertStatus(503);
        $this->artisan('agentcms:backup-recover')->assertFailed();
        $this->assertTrue(RestoreJournal::isPending());
    }

    // ── 排他 ────────────────────────────────────────────────

    public function test_別プロセスがlockを持っていれば取得も復元もすぐ止まる(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open が無い');
        }

        $manifest = app(BackupBuilder::class)->create();
        BackupPaths::ensureRoot();

        $script = '$h = fopen($argv[1], "c+"); if (! flock($h, LOCK_EX)) { exit(1); } echo "locked\n"; fflush(STDOUT); sleep(30);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', BackupLock::path()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process);
        $this->assertSame("locked\n", fgets($pipes[1]));

        try {
            $started = microtime(true);

            try {
                app(BackupBuilder::class)->create();
                $this->fail('別プロセスが lock を持っているのに取得できた');
            } catch (BackupFailed $e) {
                $this->assertSame(BackupLock::CONTENTION_MESSAGE, $e->getMessage());
            }

            $this->assertThrows(fn () => app(BackupRestorer::class)->restore($manifest->id), BackupFailed::class);
            $this->assertLessThan(10.0, microtime(true) - $started, '止まるまでに時間がかかりすぎている');

            // 待たされただけで、何も動いていない
            $this->assertFalse(RestoreJournal::isPending());
            $this->assertCount(1, glob(BackupPaths::root().'/*.zip') ?: []);
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /** lock 待ちの間に別の復元が中断したら、取得を始めず再確認する。 */
    public function test_排他待ちの間に残ったjournalを取得開始前に再確認する(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        mkdir(RestoreJournal::directory(), 0700, true);
        $script = '$h = fopen($argv[1], "c+"); flock($h, LOCK_EX); echo "locked\\n"; fflush(STDOUT); fgets(STDIN); usleep(300000); file_put_contents($argv[2], "interrupted"); flock($h, LOCK_UN);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', BackupLock::path(), RestoreJournal::path()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->assertSame("locked\n", fgets($pipes[1]));
        $this->assertFalse(RestoreJournal::isPending());
        try {
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            try {
                app(BackupBuilder::class)->create();
                $this->fail('待機中に復元が止まったのに不整合な状態のbackupを取得した');
            } catch (BackupFailed $e) {
                $this->assertSame(RestoreJournal::PENDING_MESSAGE, $e->getMessage());
            }
            $this->assertCount(1, glob(BackupPaths::root().'/*.zip') ?: []);
            $this->assertSame('interrupted', file_get_contents(RestoreJournal::path()));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    public function test_同じプロセスの中では復元が復元前backupの取得へ再入できる(): void
    {
        $lock = app(BackupLock::class);
        $this->assertSame($lock, app(BackupLock::class), 'singleton でなければ再入を数えられない');

        [$id, $mediaId] = $this->backupThenChange();

        $result = app(BackupRestorer::class)->restore($id);

        $this->assertStringStartsWith(BackupPaths::PRE_RESTORE_PREFIX, $result['preRestore']);
        $this->assertAfterRestore($mediaId);
        $this->assertFalse($lock->isHeld(), 'lock が解放されていない');
        $this->assertSame('completed', $this->historyOutcome());
    }

    // ── session 世代 ────────────────────────────────────────

    /**
     * 🔴 世代は復元前の最大と復元後の最大のどちらより大きい。
     *
     * backup の中の世代が 0 で、いまが 1 のとき、「戻してから +1」は 1 になって
     * いまの session がそのまま生き残る。CLI から直接戻しても同じでなければならない。
     */
    public function test_直接戻しても世代は復元前と復元後のどちらより大きい(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value, 'session_epoch' => 5, 'remember_token' => 'keep']);
        $other = User::factory()->create(['session_epoch' => 2]);
        $id = app(BackupBuilder::class)->create()->id;

        DB::table('users')->where('id', $owner->getKey())->update(['session_epoch' => 7]);
        DB::table('users')->where('id', $other->getKey())->update(['session_epoch' => 1]);

        config()->set('session.driver', 'database');
        DB::table('sessions')->insert(['id' => 'live', 'user_id' => $owner->getKey(), 'payload' => 'x', 'last_activity' => time()]);

        app(BackupRestorer::class)->restore($id);

        $epochs = DB::table('users')->pluck('session_epoch')->map(fn ($e): int => (int) $e)->unique()->values()->all();
        $this->assertSame([8], $epochs, '全員が max(復元前 7, 復元後 5) + 1 になっていない');
        $this->assertNull(DB::table('users')->where('id', $owner->getKey())->value('remember_token'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_復元がdbで失敗したら世代も進まない(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value, 'session_epoch' => 3]);
        $id = app(BackupBuilder::class)->create()->id;

        $this->failAt(RestoreProbe::BEFORE_COMMIT);
        $this->assertThrows(fn () => app(BackupRestorer::class)->restore($id), BackupFailed::class);

        $this->assertSame(3, (int) DB::table('users')->where('id', $owner->getKey())->value('session_epoch'));
    }

    // ── helpers ─────────────────────────────────────────────

    private function failAt(string $point): void
    {
        app()->instance(RestoreProbe::class, new class($point) implements RestoreProbe
        {
            public function __construct(private readonly string $point) {}

            public function at(string $point): void
            {
                if ($point === $this->point) {
                    throw new RuntimeException('injected failure at '.$point);
                }
            }
        });
    }

    /** @return array{0: string, 1: int} backup id と media の id */
    private function backupThenChange(): array
    {
        app(SettingsStore::class)->put(['site_name' => '沼津どうぶつ病院']);
        $this->image('photo.png', 'original');
        $this->file('doc.pdf', 'pdf bytes');
        $mediaId = $this->mediaRow('media/photo.png');

        $id = app(BackupBuilder::class)->create()->id;

        file_put_contents($this->imageDir().'/photo.png', 'overwritten');
        $this->image('later.png', 'later');
        $this->file('later.pdf', 'later');
        DB::table('media')->where('id', $mediaId)->update(['name' => 'renamed']);

        return [$id, $mediaId];
    }

    private function assertBeforeRestore(int $mediaId): void
    {
        $this->assertSame('renamed', DB::table('media')->where('id', $mediaId)->value('name'));
        $this->assertSame('overwritten', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileExists($this->imageDir().'/later.png');
        $this->assertFileExists($this->fileDir().'/later.pdf');
        $this->assertSame([], glob(BackupPaths::disk()->path(BackupPaths::REPLACED).'/*/media/*/*') ?: [], '退避先に取り残しがある');
    }

    private function assertAfterRestore(int $mediaId): void
    {
        $this->assertSame('photo', DB::table('media')->where('id', $mediaId)->value('name'));
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileDoesNotExist($this->imageDir().'/later.png');
        $this->assertFileDoesNotExist($this->fileDir().'/later.pdf');
        // 消していない。退避してある
        $this->assertCount(1, glob(BackupPaths::disk()->path(BackupPaths::REPLACED).'/*/media/image/later.png') ?: []);
    }

    private function historyOutcome(): ?string
    {
        $files = glob(RestoreJournal::historyDirectory().'/*.json') ?: [];

        if (count($files) !== 1) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($files[0]), true);

        return is_array($decoded) ? (string) ($decoded['outcome'] ?? '') : null;
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

    private function mediaRow(string $path): int
    {
        return (int) DB::table('media')->insertGetId([
            'name' => 'photo', 'path' => $path, 'mime' => 'image/png', 'size' => 8,
            'width' => 1, 'height' => 1, 'kind' => 'image', 'visibility' => 'public', 'disk' => 'public',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
