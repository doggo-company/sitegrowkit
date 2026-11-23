<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\AutoBackupStatus;
use AgentCms\Core\Backups\OffsiteBackups;
use App\Models\User;
use App\Support\Role;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * 夜中の自動 backup が失敗したことに、人が気づけるか。
 *
 * `schedule:run` は中で走ったコマンドが失敗しても **exit 0（DONE）** で終わり、
 * 理由は `storage/logs/laravel.log` にしか出ない。cron も見張りも「成功」と読む。
 * ここで固定するのは次のことである。
 *
 *   - 最後の自動 backup が失敗したら、状態の表示（`agentcms:backup-offsite --list`）が exit 1 で言う
 *   - 管理画面のバックアップ画面とサイドバーに出る
 *   - 通知先を決めていればメールで知らせる（決めていなければ何も送らない）
 *   - 次に成功したら印は消える
 *   - cron が止まって何日も走っていないことも、失敗と同じように言う
 *   - 理由に置き場所の鍵が混ざっても、状態・画面・メール・ログに鍵を出さない
 *   - 自動 backup を使わない人には、何も増えない
 */
final class AutoBackupAlertTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'backup-offsite-alert-test';

    private const PREFIX = 'site-a';

    // 秘密の検査に掛からないよう、見本の鍵は分けて書く（本物ではない）
    private const FAKE_KEY = 'AKIA'.'ALERTTESTKEY7Q2Z';

    private const FAKE_SIGNING = 'wJalrX'.'UtnFEMI-alert-test-signing-9f3k';

    private string $sandbox;

    private string $elsewhere;

    private string $broken;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = storage_path('framework/testing/offsite-alert-'.getmypid());
        $this->elsewhere = storage_path('framework/testing/offsite-alert-elsewhere-'.getmypid());
        // ディレクトリであるべき場所にファイルがある（置き場所が壊れた・外れた）
        $this->broken = storage_path('framework/testing/offsite-alert-broken-'.getmypid());

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');
        config()->set('filesystems.disks.'.self::DISK, [
            'driver' => 'local',
            'root' => $this->elsewhere,
            'throw' => false,
            'report' => false,
        ]);

        config()->set('agentcms.backup.schedule', '03:40');
        config()->set('agentcms.backup.offsite.disk', self::DISK);
        config()->set('agentcms.backup.offsite.prefix', self::PREFIX);
        config()->set('agentcms.backup.offsite.keep', 3);

        @mkdir(dirname($this->broken), 0777, true);
        file_put_contents($this->broken, 'not a directory');

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->sandbox);
        self::removeTree($this->elsewhere);
        @unlink($this->broken);

        parent::tearDown();
    }

    // ── 状態の表示 ─────────────────────────────────────────────

    public function test_送り出しに失敗したら状態の表示がexit1で知らせる(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        // 置き場所は戻った（翌朝に人が見る時点では、一覧は読める）
        $this->repairOffsite();

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('最後の自動バックアップの送り出しが失敗')
            ->assertExitCode(1);
    }

    public function test_毎日走っていなければ止まっていると知らせる(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        // cron が止まった。何も失敗していないが、何も取れていない
        $this->travel(31)->hours();

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('自動バックアップが止まっています')
            ->assertExitCode(1);

        $this->asOwner('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->where('autoBackup.attention', true)
                ->where('nav.backupAlert', 1));
    }

    public function test_途中で止まった回も知らせる(): void
    {
        // 取得の途中でプロセスが落ちた（メモリ切れ・強制終了）。終わった記録が書かれない
        app(AutoBackupStatus::class)->started();

        $this->travel(AutoBackupStatus::RUNNING_TOO_LONG_HOURS + 1)->hours();

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('途中で止まったまま')
            ->assertExitCode(1);

        $this->asOwner('/admin/pages')
            ->assertInertia(fn (Assert $page) => $page->where('nav.backupAlert', 1));
    }

    public function test_毎晩途中で落ちていても前回の成功が古ければ止まっていると知らせる(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);
        $this->travel(AutoBackupStatus::STALE_AFTER_HOURS + 1)->hours();

        // 今夜の回は始まったまま（メモリ切れで落ちた直後・まだ 4 時間たっていない）
        app(AutoBackupStatus::class)->started();

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('自動バックアップが止まっています')
            ->assertExitCode(1);
    }

    public function test_記録が読めなければ見てほしいと出す(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        file_put_contents(app(AutoBackupStatus::class)->path(), '{壊れた');

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('記録が読めません')
            ->assertExitCode(1);
    }

    public function test_結果の記録は他の人に読めない(): void
    {
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $path = app(AutoBackupStatus::class)->path();

        // cron と web が同じグループなら互いに読み書きでき、他人（other）には何も許さない
        $this->assertSame(0, fileperms($path) & 0007);
        $this->assertSame(0, fileperms(dirname($path)) & 0007);
        $this->assertSame(0660, fileperms($path) & 0777);
        // 中のファイルがディレクトリのグループになる（cron と web が別のユーザーでも読み合える）
        $this->assertSame(02000, fileperms(dirname($path)) & 02000);
    }

    public function test_backupを作れなくても失敗として残る(): void
    {
        // 手元の backup の置き場所が壊れた（ディレクトリであるべき所がファイル）
        @mkdir($this->sandbox, 0777, true);
        file_put_contents($this->sandbox.'/backups', 'not a directory');

        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('backup を作れませんでした')
            ->assertExitCode(1);

        // 手元の置き場所が壊れているとバックアップ画面の一覧は読めないので、別の画面の印で見る
        $this->asOwner('/admin/pages')
            ->assertInertia(fn (Assert $page) => $page->where('nav.backupAlert', 1));
    }

    public function test_時刻を決めたのに一度も走っていなければ止まっていると知らせる(): void
    {
        // 時刻は入れたが、サーバーの cron を置き忘れた。何も失敗しないし、何も取れない
        $this->asOwner('/admin/pages')
            ->assertInertia(fn (Assert $page) => $page->where('nav.backupAlert', 0));

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('記録はまだありません')
            ->assertExitCode(0);

        $this->travel(AutoBackupStatus::STALE_AFTER_HOURS + 1)->hours();

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('一度も取れていません')
            ->assertExitCode(1);

        $this->asOwner('/admin/pages')
            ->assertInertia(fn (Assert $page) => $page->where('nav.backupAlert', 1));
    }

    public function test_結果を記録できなければそれ自体を知らせる(): void
    {
        Mail::fake();
        config()->set('agentcms.backup.alert_mail', 'ops@example.org');

        // 記録の置き場所が書けない（ディレクトリであるべき所がファイル）
        @mkdir($this->sandbox, 0777, true);
        file_put_contents($this->sandbox.'/backup-status', 'not a directory');

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('記録できませんでした')
            ->assertExitCode(1);

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('記録できません')
            ->assertExitCode(1);

        // 記録に残せなくても、知らせのメールは届く
        $this->assertCount(1, Mail::sent(Mailable::class, static fn (Mailable $m): bool => $m->hasTo('ops@example.org')));
    }

    public function test_外の古い世代を片付けられないときは送り出しの失敗と言わない(): void
    {
        config()->set('agentcms.backup.offsite.keep', 1);
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        // 書けるが消せない鍵（削除の権限が無い）
        $real = Storage::build(['driver' => 'local', 'root' => $this->elsewhere, 'throw' => true]);
        Storage::extend('write-only-offsite', static function () use ($real): Filesystem {
            $disk = Mockery::mock($real)->makePartial();
            $disk->shouldReceive('delete')->andThrow(new RuntimeException('AccessDenied'));

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, ['driver' => 'write-only-offsite']);

        $this->artisan('agentcms:backup-auto')
            ->expectsOutputToContain('外の古い世代を片付けられませんでした')
            ->assertExitCode(1);

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->expectsOutputToContain('外へ送れましたが')
            ->doesntExpectOutputToContain('送り出しが失敗')
            ->assertExitCode(1);

        // 送れてはいる（2 世代とも外にある）
        $this->assertCount(2, glob($this->elsewhere.'/'.self::PREFIX.'/*.zip.sha256') ?: []);
    }

    // ── 管理画面 ─────────────────────────────────────────────

    public function test_送り出しの失敗は管理画面とサイドバーに出る(): void
    {
        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $this->asOwner('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups/Index')
                ->where('autoBackup.state', 'failed')
                ->where('autoBackup.attention', true)
                ->where('nav.backupAlert', 1));

        // 他の画面を開いていても、サイドバーの印は出る
        $this->asOwner('/admin/pages')
            ->assertInertia(fn (Assert $page) => $page->where('nav.backupAlert', 1));
    }

    public function test_次に送れたら印は消える(): void
    {
        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $this->repairOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->doesntExpectOutputToContain('失敗')
            ->assertExitCode(0);

        $this->asOwner('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->where('autoBackup.state', 'ok')
                ->where('autoBackup.attention', false)
                ->where('nav.backupAlert', 0));
    }

    // ── メール ───────────────────────────────────────────────

    public function test_通知先を決めていれば失敗をメールで知らせる(): void
    {
        Mail::fake();
        config()->set('agentcms.backup.alert_mail', 'ops@example.org');

        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $sent = Mail::sent(Mailable::class, static fn (Mailable $m): bool => $m->hasTo('ops@example.org'));
        $this->assertCount(1, $sent);
    }

    public function test_成功したときはメールを送らない(): void
    {
        Mail::fake();
        config()->set('agentcms.backup.alert_mail', 'ops@example.org');

        $this->artisan('agentcms:backup-auto')->assertExitCode(0);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_通知先を決めていなければメールを送らない(): void
    {
        Mail::fake();

        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_知らせのメールが送れなくても失敗は残る(): void
    {
        config()->set('agentcms.backup.alert_mail', 'ops@example.org');
        // つながらない SMTP（送信が例外になる）
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.port', 1);
        config()->set('mail.mailers.smtp.timeout', 2);

        $this->breakOffsite();
        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $this->asOwner('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page->where('autoBackup.state', 'failed'));
    }

    // ── 秘密 ────────────────────────────────────────────────

    public function test_理由に鍵が混ざっても状態と画面とメールとログに出さない(): void
    {
        Mail::fake();
        Log::spy();
        config()->set('agentcms.backup.alert_mail', 'ops@example.org');

        // S3 互換の置き場所が、拒んだ理由に鍵を含めて返してきた（SDK の例外文はそのまま積まれる）
        // 拒んだ理由の文（閉包は Storage の中で呼ばれるので、鍵は値で渡す）
        $refusal = 'Error executing "PutObject"; 403 Forbidden key='.self::FAKE_KEY.' <AWSAccessKeyId>'.self::FAKE_KEY.'</AWSAccessKeyId>'
            .'<StringToSign>'.self::FAKE_SIGNING.'</StringToSign> secret: '.self::FAKE_SIGNING;

        Storage::extend('leaky-offsite', static function () use ($refusal): Filesystem {
            $disk = Mockery::mock(Filesystem::class);
            $disk->shouldReceive('writeStream')->andThrow(new RuntimeException($refusal));
            $disk->shouldReceive('files')->andReturn([]);
            $disk->shouldIgnoreMissing();

            return $disk;
        });
        config()->set('filesystems.disks.'.self::DISK, [
            'driver' => 'leaky-offsite',
            'key' => self::FAKE_KEY,
            'secret' => self::FAKE_SIGNING,
            'bucket' => 'b',
        ]);

        $this->artisan('agentcms:backup-auto')->assertExitCode(1);

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->doesntExpectOutputToContain(self::FAKE_KEY)
            ->doesntExpectOutputToContain(self::FAKE_SIGNING)
            ->assertExitCode(1);

        // 画面へ渡すもの
        $props = json_encode($this->asOwner('/admin/backups')->viewData('page'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($props);
        $this->assertStringContainsString('外の置き場所へ送れませんでした', $props);
        $this->assertStringNotContainsString(self::FAKE_KEY, $props);
        $this->assertStringNotContainsString(self::FAKE_SIGNING, $props);

        // 理由は残っている（伏せ字で）
        $this->assertStringContainsString('[伏せ字]', (string) file_get_contents(app(AutoBackupStatus::class)->path()));

        // 置いたファイル（状態など）
        foreach ($this->filesUnder($this->sandbox) as $file) {
            $body = (string) file_get_contents($file);
            $this->assertStringNotContainsString(self::FAKE_KEY, $body, $file);
            $this->assertStringNotContainsString(self::FAKE_SIGNING, $body, $file);
        }

        // メール
        $sent = Mail::sent(Mailable::class, static fn (): bool => true);
        $this->assertCount(1, $sent);
        $mail = $sent->first();
        $this->assertInstanceOf(Mailable::class, $mail);
        $rendered = $mail->render();
        $this->assertStringContainsString('外の置き場所へ送れませんでした', $rendered);
        $this->assertStringNotContainsString(self::FAKE_KEY, $rendered);
        $this->assertStringNotContainsString(self::FAKE_SIGNING, $rendered);

        // ログ
        Log::shouldHaveReceived('error')->withArgs(static function (string $message, array $context = []): bool {
            $all = $message.json_encode($context);

            return str_contains($all, '送れませんでした');
        });
        Log::shouldNotHaveReceived('error', [Mockery::on(static fn ($m): bool => is_string($m) && (str_contains($m, self::FAKE_KEY) || str_contains($m, self::FAKE_SIGNING))), Mockery::any()]);
        Log::shouldNotHaveReceived('error', [Mockery::on(static fn ($m): bool => is_string($m) && (str_contains($m, self::FAKE_KEY) || str_contains($m, self::FAKE_SIGNING)))]);
    }

    public function test_設定に無い鍵でも署名の欄と署名つき_ur_lは伏せる(): void
    {
        $text = app(OffsiteBackups::class)->redact(
            '403 <AWSAccessKeyId>AKIAOTHERKEY0000</AWSAccessKeyId><StringToSign>AWS4-HMAC-SHA256 x</StringToSign>'
            .' https://b.example/x.zip?X-Amz-Credential=AKIAOTHERKEY0000%2F2026&X-Amz-Signature=deadbeef01',
        );

        $this->assertStringNotContainsString('AKIAOTHERKEY0000', $text);
        $this->assertStringNotContainsString('deadbeef01', $text);
        $this->assertStringNotContainsString('AWS4-HMAC-SHA256 x', $text);
        $this->assertStringContainsString('403', $text);
    }

    // ── 使わない人 ────────────────────────────────────────────

    public function test_自動backupを使わない人には何も増えない(): void
    {
        config()->set('agentcms.backup.schedule', '');
        config()->set('agentcms.backup.offsite.disk', '');

        $this->asOwner('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page
                ->where('autoBackup.state', 'off')
                ->where('autoBackup.attention', false)
                ->where('nav.backupAlert', 0));

        // 画面を開いただけで結果の記録は作らない（`.runs` は前からある画面の進みの印）
        $this->assertDirectoryDoesNotExist($this->sandbox.'/backup-status');

        $this->artisan('agentcms:backup-offsite', ['--list' => true])
            ->doesntExpectOutputToContain('最後の自動バックアップ')
            ->doesntExpectOutputToContain('止まっています')
            ->assertExitCode(1); // 外の置き場所が未設定なのは前から exit 1（変えていない）
    }

    // ── helpers ──────────────────────────────────────────────

    private function asOwner(string $uri): TestResponse
    {
        return $this->actingAs($this->owner)->get($uri)->assertOk();
    }

    private function breakOffsite(): void
    {
        config()->set('filesystems.disks.'.self::DISK.'.root', $this->broken);
    }

    private function repairOffsite(): void
    {
        config()->set('filesystems.disks.'.self::DISK.'.root', $this->elsewhere);
    }

    /** @return list<string> */
    private function filesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() !== 'zip') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

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
