<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupPaths;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 自動の backup と、別の置き場所への保管（offsite）。
 *
 * これまでの backup は **人が打ったときだけ** 取れて、**同じサーバーの中にしか** 置けなかった。
 * サーバーごと壊れたら backup も一緒に消える。ここで固定するのは次の 4 つである。
 *
 *   - 時刻を設定すれば毎日自動で取る（設定しなければ今までどおり取らない）
 *   - 取ったものを別の置き場所へ送り、送れなかったら失敗で終わる（黙って成功にしない）
 *   - 別の置き場所では世代を残し、古いものから捨てる
 *   - 手元の backup が全部消えても、別の置き場所から 1 コマンドで戻せる。
 *     途中で中身が変わっていたら戻さない
 *
 * 別の置き場所は、テストでは「別のディレクトリを根に持つ disk」で作る。
 * S3 互換（R2 / B2 / MinIO）も同じ disk の差し替えで入る。
 */
final class OffsiteBackupTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'backup-offsite-test';

    private const PREFIX = 'site-a';

    private string $sandbox;

    private string $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = storage_path('framework/testing/offsite-'.getmypid());
        $this->elsewhere = storage_path('framework/testing/offsite-elsewhere-'.getmypid());

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');

        // 「別の置き場所」。throw は Laravel の既定どおり false — 失敗が戻り値でしか分からない形で確かめる
        config()->set('filesystems.disks.'.self::DISK, [
            'driver' => 'local',
            'root' => $this->elsewhere,
            'throw' => false,
            'report' => false,
        ]);

        config()->set('agentcms.backup.offsite.disk', self::DISK);
        config()->set('agentcms.backup.offsite.prefix', self::PREFIX);
        config()->set('agentcms.backup.offsite.keep', 3);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->sandbox);

        if (is_file($this->elsewhere)) {
            @unlink($this->elsewhere);
        }

        self::removeTree($this->elsewhere);

        parent::tearDown();
    }

    // ── 自動で取る ────────────────────────────────────────────

    public function test_時刻を決めれば毎日その時刻に自動で取る(): void
    {
        config()->set('agentcms.backup.schedule', '03:40');

        $events = $this->backupEvents();

        $this->assertCount(1, $events);
        $this->assertSame('40 3 * * *', $events[0]->expression);
    }

    public function test_時刻を決めなければ自動では取らない(): void
    {
        config()->set('agentcms.backup.schedule', '');

        $this->assertSame([], $this->backupEvents());
    }

    public function test_読めない時刻は登録せず状態の表示で分かる(): void
    {
        config()->set('agentcms.backup.schedule', '25:00');

        $this->assertSame([], $this->backupEvents());

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('時刻の設定が読めません')
            ->assertExitCode(1);
    }

    // ── 別の置き場所へ送る ─────────────────────────────────────

    public function test_自動の取得は別の置き場所へ送る(): void
    {
        $this->entry('送られる記事');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $local = $this->localArchives();
        $this->assertCount(1, $local);

        $id = basename($local[0], '.zip');
        $remote = $this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip';

        $this->assertFileExists($remote);
        $this->assertSame(hash_file('sha256', $local[0]), hash_file('sha256', $remote));
        $this->assertSame(hash_file('sha256', $local[0]), trim((string) file_get_contents($remote.'.sha256')));

        // 送り先は手元の backup の置き場所の外である
        $this->assertStringStartsNotWith(BackupPaths::root(), $remote);
    }

    public function test_送れなかったら失敗で終わり手元のbackupは残る(): void
    {
        // 置き場所の根が「ファイル」なので、ディレクトリを作れない
        @mkdir(dirname($this->elsewhere), 0777, true);
        file_put_contents($this->elsewhere, 'not a directory');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('外の置き場所へ送れませんでした')
            ->assertExitCode(1);

        $this->assertCount(1, $this->localArchives());
    }

    public function test_外の置き場所が未設定なら同じサーバーにしか無いと知らせる(): void
    {
        config()->set('agentcms.backup.offsite.disk', '');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('このサーバーの中にしかありません')
            ->assertExitCode(0);

        $this->assertCount(1, $this->localArchives());
    }

    public function test_外の置き場所が手元と同じ場所なら送らない(): void
    {
        // 「外」のつもりで手元の backup の下を指していた
        config()->set('filesystems.disks.'.self::DISK.'.root', BackupPaths::root().'/copy');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('手元の backup と同じ場所です')
            ->assertExitCode(1);
    }

    public function test_外の置き場所がwebから見える場所なら送らない(): void
    {
        config()->set('filesystems.disks.'.self::DISK.'.root', public_path('offsite-test-'.getmypid()));

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('web から見える場所の中です')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist(public_path('offsite-test-'.getmypid()));
    }

    public function test_送れなかったことはログにも残る(): void
    {
        Log::spy();

        @mkdir(dirname($this->elsewhere), 0777, true);
        file_put_contents($this->elsewhere, 'not a directory');

        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message): bool => str_contains($message, '外の置き場所へ送れませんでした'),
        )->once();
    }

    public function test_外の置き場所は決めた世代だけ残す(): void
    {
        config()->set('agentcms.backup.offsite.keep', 2);

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('agentcms:backup-auto')->assertExitCode(0);
        }

        $remote = glob($this->elsewhere.'/'.self::PREFIX.'/*.zip') ?: [];
        $sums = glob($this->elsewhere.'/'.self::PREFIX.'/*.zip.sha256') ?: [];
        sort($remote);

        $this->assertCount(2, $remote);
        $this->assertCount(2, $sums);

        // 残るのは新しい 2 つ
        $local = array_map(static fn (string $p): string => basename($p), $this->localArchives());
        sort($local);
        $this->assertSame(array_slice($local, -2), array_map(static fn (string $p): string => basename($p), $remote));
    }

    // ── 別の置き場所から戻す ───────────────────────────────────

    public function test_手元のbackupが全部消えても外から1コマンドで戻せる(): void
    {
        $this->entry('戻ってくる記事');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        // サーバーが壊れた: 手元の backup も記事も無い
        self::removeTree(BackupPaths::root());
        DB::table('post')->delete();

        $this->artisan('agentcms:backup-restore', ['--from-offsite' => true, '--latest' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('post', ['title' => '戻ってくる記事']);
    }

    public function test_外の置き場所の中身が変わっていたら戻さない(): void
    {
        $this->entry('最初の記事');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $id = basename($this->localArchives()[0], '.zip');
        self::removeTree(BackupPaths::root());

        $this->entry('あとから書いた記事');

        // 置き場所で 1 byte 書き換えられた
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip', 'x', FILE_APPEND);

        $this->artisan('agentcms:backup-restore', ['id' => $id, '--from-offsite' => true, '--force' => true])
            ->expectsOutputToContain('送ったときと違います')
            ->assertExitCode(1);

        // 何も戻していない・壊れたものを手元に置いていない
        $this->assertDatabaseHas('post', ['title' => 'あとから書いた記事']);
        $this->assertFileDoesNotExist(BackupPaths::archive($id));
    }

    public function test_おかしなidは外から取らない(): void
    {
        $this->artisan('agentcms:backup-restore', ['id' => '../../etc/passwd', '--from-offsite' => true, '--force' => true])
            ->assertExitCode(1);
    }

    public function test_確かめずには戻さない(): void
    {
        $this->entry('残る記事');
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);
        $this->entry('戻すと消える記事');

        $this->artisan('agentcms:backup-restore', ['--latest' => true])
            ->expectsConfirmation('この backup へ戻しますか？', 'no')
            ->assertExitCode(1);

        $this->assertDatabaseHas('post', ['title' => '戻すと消える記事']);
    }

    // ── helpers ──────────────────────────────────────────────

    /** @return list<Event> */
    private function backupEvents(): array
    {
        // 設定を変えたあとに組み直す（Schedule は一度作られると使い回される）
        $this->app->forgetInstance(Schedule::class);

        $events = $this->app->make(Schedule::class)->events();

        return array_values(array_filter(
            $events,
            static fn (Event $event): bool => str_contains((string) $event->command, 'agentcms:backup-auto'),
        ));
    }

    /** @return list<string> */
    private function localArchives(): array
    {
        return array_values(array_filter(
            glob(BackupPaths::root().'/*.zip') ?: [],
            static fn (string $p): bool => ! str_starts_with(basename($p), BackupPaths::PRE_RESTORE_PREFIX),
        ));
    }

    private function entry(string $title): int
    {
        return (int) DB::table('post')->insertGetId([
            'title' => $title, 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'draft', 'lock_version' => 0,
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
