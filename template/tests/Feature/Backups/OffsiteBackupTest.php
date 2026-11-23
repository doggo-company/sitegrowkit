<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_整理はこのサイトの場所の中だけ(): void
    {
        config()->set('agentcms.backup.offsite.keep', 1);

        // 同じ置き場所を使う隣のサイト
        $neighbour = $this->elsewhere.'/site-b';
        @mkdir($neighbour, 0777, true);
        file_put_contents($neighbour.'/20200101-000000-000-aaaaaa.zip', 'b');
        file_put_contents($neighbour.'/20200101-000000-000-aaaaaa.zip.sha256', str_repeat('0', 64));

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('agentcms:backup-auto')->assertExitCode(0);
        }

        $this->assertCount(1, glob($this->elsewhere.'/'.self::PREFIX.'/*.zip') ?: []);
        $this->assertFileExists($neighbour.'/20200101-000000-000-aaaaaa.zip');
        $this->assertFileExists($neighbour.'/20200101-000000-000-aaaaaa.zip.sha256');
    }

    public function test_外の整理は復元前の印のものを消さない(): void
    {
        config()->set('agentcms.backup.offsite.keep', 1);

        $pre = BackupPaths::PRE_RESTORE_PREFIX.'20200101-000000-000-aaaaaa';
        @mkdir($this->elsewhere.'/'.self::PREFIX, 0777, true);
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$pre.'.zip', 'pre');
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$pre.'.zip.sha256', str_repeat('0', 64)."\n");

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('agentcms:backup-auto')->assertExitCode(0);
        }

        $this->assertFileExists($this->elsewhere.'/'.self::PREFIX.'/'.$pre.'.zip');
        // 通常の世代は 1 つだけ残る
        $normal = array_filter(
            glob($this->elsewhere.'/'.self::PREFIX.'/*.zip') ?: [],
            static fn (string $p): bool => ! str_starts_with(basename($p), BackupPaths::PRE_RESTORE_PREFIX),
        );
        $this->assertCount(1, $normal);
    }

    public function test_送り直しが途中で落ちても前の組は残る(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $id = basename($this->localArchives()[0], '.zip');
        $sum = $this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip.sha256';
        $this->assertFileExists($sum);

        // 外の組が手元と違う（以前に別の中身で送った・壊れた）
        file_put_contents($sum, str_repeat('f', 64)."\n");

        // 送り直しの途中で落ちる置き場所（zip の書き込みで止まる）
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        Storage::extend('dies-mid-upload', static function () use ($real): Filesystem {
            $disk = \Mockery::mock($real)->makePartial();
            $disk->shouldReceive('writeStream')->andThrow(new \RuntimeException('connection reset'));

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, ['driver' => 'dies-mid-upload']);

        $zip = $this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip';
        $before = hash_file('sha256', $zip);

        $this->artisan('agentcms:backup-offsite', ['id' => $id, '--force' => true])->assertExitCode(1);

        // 前からある組（zip と checksum）はそのまま。途中の一時ファイルは残さない
        $this->assertSame($before, hash_file('sha256', $zip));
        $this->assertSame(str_repeat('f', 64), trim((string) file_get_contents($sum)));
        $this->assertFileDoesNotExist($zip.'.part');
    }

    public function test_s3互換でchecksumを置けなくてもzipは消さない(): void
    {
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        Storage::extend('s3', static function () use ($real): Filesystem {
            $disk = \Mockery::mock($real)->makePartial();
            $disk->shouldReceive('put')->andThrow(new \RuntimeException('503 SlowDown'));

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, ['driver' => 's3']);

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('checksum を置けません')
            ->assertExitCode(1);

        // zip は残り、checksum が無いので「途中のもの」— 戻す候補にはしないが、黙って消えもしない
        $this->assertCount(1, glob($this->elsewhere.'/'.self::PREFIX.'/*.zip') ?: []);
        $this->assertSame([], glob($this->elsewhere.'/'.self::PREFIX.'/*.sha256') ?: []);
    }

    public function test_差し替えに失敗したら前の組のまま残る(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $id = basename($this->localArchives()[0], '.zip');
        $zip = $this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip';
        $sum = $zip.'.sha256';
        file_put_contents($sum, str_repeat('f', 64)."\n");
        $before = hash_file('sha256', $zip);

        // 一時ファイルまでは置けるが、本来の名前へ差し替えられない（local ドライバのまま）
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        // 閉包は Storage の中で呼ばれる（$this は Storage になる）ので、比べる場所は値で渡す
        $root = $this->elsewhere;
        $this->app['filesystem']->extend('local', static function ($app, array $config) use ($real, $root) {
            if (($config['root'] ?? null) !== $root) {
                return $app['filesystem']->createLocalDriver($config);
            }

            $disk = \Mockery::mock($real)->makePartial();
            $disk->shouldReceive('move')->andThrow(new \RuntimeException('rename failed'));

            return $disk;
        });

        $this->artisan('agentcms:backup-offsite', ['id' => $id, '--force' => true])
            ->expectsOutputToContain('差し替えられません')
            ->assertExitCode(1);

        $this->assertSame($before, hash_file('sha256', $zip));
        $this->assertSame(str_repeat('f', 64), trim((string) file_get_contents($sum)));
        $this->assertFileDoesNotExist($zip.'.part');
    }

    public function test_s3互換などには一時的な名前を使わず直接置く(): void
    {
        $written = [];
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        Storage::extend('s3', static function () use ($real, &$written): Filesystem {
            $disk = \Mockery::mock($real)->makePartial();
            $disk->shouldReceive('writeStream')->andReturnUsing(static function (string $path, $stream) use ($real, &$written): bool {
                $written[] = $path;

                return $real->writeStream($path, $stream);
            });
            $disk->shouldNotReceive('move');

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, ['driver' => 's3']);

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $this->assertCount(1, $written);
        $this->assertStringEndsWith('.zip', $written[0]);
        $this->assertCount(1, glob($this->elsewhere.'/'.self::PREFIX.'/*.zip.sha256') ?: []);
    }

    public function test_同じ中身が外にあれば送り直さず健全な世代を残す(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $id = basename($this->localArchives()[0], '.zip');
        $sum = $this->elsewhere.'/'.self::PREFIX.'/'.$id.'.zip.sha256';

        // 送り直すと途中で落ちる置き場所。同じ中身なら書きに行かないので落ちない
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        Storage::extend('dies-mid-upload-2', static function () use ($real): Filesystem {
            $disk = \Mockery::mock($real)->makePartial();
            $disk->shouldReceive('writeStream')->andThrow(new \RuntimeException('connection reset'));

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, ['driver' => 'dies-mid-upload-2']);

        $this->artisan('agentcms:backup-offsite', ['id' => $id])
            ->expectsOutputToContain('送りませんでした')
            ->assertExitCode(0);

        $this->assertSame(hash_file('sha256', $this->localArchives()[0]), trim((string) file_get_contents($sum)));

        // 外の zip を疑うときは --force で必ず送り直す（ここでは書き込みが落ちる置き場所なので失敗で終わる）
        $this->artisan('agentcms:backup-offsite', ['id' => $id, '--force' => true])->assertExitCode(1);
    }

    public function test_作り直したデータベースにも外から戻せてメディアも戻る(): void
    {
        $this->entry('作り直しても戻る記事');
        $kind = MediaKind::Image;
        $media = Storage::disk($kind->disk());
        $file = $kind->directory().'/offsite-restore-test.txt';
        $media->put($file, 'image bytes');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        // サーバーごと失った: 手元の backup・メディア・中身が無い。新しい DB（マイグレーションだけ済んだ空の表）
        self::removeTree(BackupPaths::root());
        $media->delete($file);
        Schema::withoutForeignKeyConstraints(static function (): void {
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->delete();
                }
            }
        });
        $this->assertDatabaseMissing('post', ['title' => '作り直しても戻る記事']);

        $this->artisan('agentcms:backup-restore', ['--from-offsite' => true, '--latest' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('post', ['title' => '作り直しても戻る記事']);
        $this->assertSame('image bytes', $media->get($file));
    }

    public function test_送っている途中で止まったものは戻す候補にも世代にも数えない(): void
    {
        $this->entry('健全な回の記事');
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        // 次の回が送っている途中で止まった: zip の途中までだけがあり、checksum が無い
        $partial = '29991231-235959-999-ffffff';
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$partial.'.zip', 'PK…途中');

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->doesntExpectOutputToContain($partial)
            ->assertExitCode(0);

        self::removeTree(BackupPaths::root());
        DB::table('post')->delete();

        $this->artisan('agentcms:backup-restore', ['--from-offsite' => true, '--latest' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('post', ['title' => '健全な回の記事']);
    }

    public function test_途中で止まった古いものは次の整理で捨てる(): void
    {
        $stale = '20000101-000000-000-eeeeee';
        $leftover = '20000102-000000-000-dddddd';
        @mkdir($this->elsewhere.'/'.self::PREFIX, 0777, true);
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$stale.'.zip', 'PK…途中');
        file_put_contents($this->elsewhere.'/'.self::PREFIX.'/'.$leftover.'.zip.part', 'PK…途中');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->elsewhere.'/'.self::PREFIX.'/'.$stale.'.zip');
        $this->assertFileDoesNotExist($this->elsewhere.'/'.self::PREFIX.'/'.$leftover.'.zip.part');
        // 置き終えたものには一時ファイルが残らない
        $this->assertSame([], glob($this->elsewhere.'/'.self::PREFIX.'/*.part') ?: []);
    }

    public function test_公開アドレスがlocalhostのままで場所を決めていなければ送らない(): void
    {
        config()->set('agentcms.backup.offsite.prefix', '');
        config()->set('app.url', 'http://localhost');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('AGENTCMS_BACKUP_OFFSITE_PREFIX')
            ->assertExitCode(1);
    }

    public function test_同じprivate領域の中やそこへのリンクには送らない(): void
    {
        // storage/app/private の中（手元の backup の隣）
        config()->set('filesystems.disks.'.self::DISK.'.root', $this->sandbox.'/offsite');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('手元の backup と同じ場所です')
            ->assertExitCode(1);

        // 外に見えるリンクが、実は手元を指している
        @mkdir($this->sandbox, 0777, true);
        $link = $this->elsewhere.'-link';
        @unlink($link);
        symlink($this->sandbox, $link);

        try {
            config()->set('filesystems.disks.'.self::DISK.'.root', $link.'/offsite');

            $this->artisan('agentcms:backup-auto')
                ->expectsOutputToContain('手元の backup と同じ場所です')
                ->assertExitCode(1);
        } finally {
            @unlink($link);
        }

        // 相対パスは、どこを指すか実行場所で変わる
        config()->set('filesystems.disks.'.self::DISK.'.root', 'offsite-relative');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('絶対パス')
            ->assertExitCode(1);
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
