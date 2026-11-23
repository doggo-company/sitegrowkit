<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Media\MediaKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;
use ZipArchive;

/**
 * backup の完全性（2026-09-09・リリース前レビュー R09）。
 *
 * `BackupIntegrityReviewTest` の 3 件（空 root・欠落 zip・参照欠落）が再現した欠陥を、
 * **どの経路で守っているか**をここで固定する:
 *
 *   - manifest（format 2）はメディア全ファイルの sha256 と root の一覧を持つ
 *   - verify は 欠落 / 改ざん / 目録に無い項目 / 安全でない名前 / format 1 を全部 fail closed にする
 *   - restore は DB とメディアを同じ transaction で入れ替え、途中で失敗したら両方とも元のまま
 *
 * 置き場所は使い捨て（/tmp）。本物の storage は触らない。
 */
final class BackupIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = rtrim(sys_get_temp_dir(), '/').'/sgk-backup-integrity-'.getmypid().'-'.bin2hex(random_bytes(3));

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');
    }

    protected function tearDown(): void
    {
        @chmod($this->sandbox, 0755);
        self::removeTree($this->sandbox);

        parent::tearDown();
    }

    // ── manifest ────────────────────────────────────────────

    public function test_目録はメディア全ファイルのchecksumとrootを持つ(): void
    {
        $this->image('photo.png', 'original');
        $this->image('variants/photo/webp.webp', 'webp bytes');
        $this->file('doc.pdf', 'pdf bytes');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(BackupBuilder::FORMAT, $manifest->format);
        $this->assertSame(['media/image', 'media/file'], $manifest->media['roots']);
        $this->assertSame(hash('sha256', 'original'), $manifest->checksums['media/image/photo.png'] ?? null);
        $this->assertSame(hash('sha256', 'webp bytes'), $manifest->checksums['media/image/variants/photo/webp.webp'] ?? null);
        $this->assertSame(hash('sha256', 'pdf bytes'), $manifest->checksums['media/file/doc.pdf'] ?? null);
        $this->assertArrayHasKey('database.jsonl', $manifest->checksums);
    }

    /** メディアが 1 件も無くても root は列挙する（復元が「空にする」を知るため） */
    public function test_メディアが無くてもrootは列挙する(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(['media/image', 'media/file'], $manifest->media['roots']);
        $this->assertSame(['database.jsonl'], array_keys($manifest->checksums));
    }

    // ── 検証 ────────────────────────────────────────────────

    public function test_メディアが改ざんされていれば検証で止まる(): void
    {
        $this->image('photo.png', 'original');
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteArchive($manifest->id, static function (ZipArchive $zip): void {
            $zip->addFromString('media/image/photo.png', 'tampered');
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('checksum', implode(' ', $result['problems']));
        $this->assertStringContainsString('photo.png', implode(' ', $result['problems']));
    }

    public function test_目録に無いファイルが足されていれば検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteArchive($manifest->id, static function (ZipArchive $zip): void {
            $zip->addFromString('media/image/evil.php', '<?php');
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('目録に無い', implode(' ', $result['problems']));
    }

    public function test_安全でない名前の項目があれば検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteArchive($manifest->id, static function (ZipArchive $zip): void {
            $zip->addFromString('../escaped.txt', 'x');
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('安全でない', implode(' ', $result['problems']));

        $this->assertThrows(
            fn () => app(BackupRestorer::class)->restore($manifest->id),
            BackupFailed::class,
        );
        $this->assertFileDoesNotExist(BackupPaths::root().'/escaped.txt');
    }

    /** 目録の中の名前も信用しない（目録が `..` を指していれば、そこを展開させない） */
    public function test_目録の中の安全でない名前も検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteManifest($manifest->id, static function (array $raw): array {
            $raw['checksums']['media/image/../../escaped.txt'] = hash('sha256', 'x');

            return $raw;
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('安全でない', implode(' ', $result['problems']));
    }

    public function test_知らない置き場所を指す目録は検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteManifest($manifest->id, static function (array $raw): array {
            $raw['media']['roots'] = ['media/somewhere'];

            return $raw;
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('知らない場所', implode(' ', $result['problems']));
    }

    /**
     * 🔴 **format 1 は「復元できる」と言わない。**
     *
     * メディアの一覧も checksum も無いので、欠けていても見つけられない。
     * 検証不能なものを復元可能と呼ばず、取り直しを案内する。
     */
    public function test_形式1のbackupは復元可能と言わない(): void
    {
        $this->image('photo.png', 'original');
        $manifest = app(BackupBuilder::class)->create();

        $this->rewriteManifest($manifest->id, static function (array $raw): array {
            $raw['format'] = 1;
            $raw['checksums'] = ['database.jsonl' => $raw['checksums']['database.jsonl']];
            unset($raw['media']['roots']);

            return $raw;
        });

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('形式（1）', implode(' ', $result['problems']));
        $this->assertStringContainsString('取り直して', implode(' ', $result['problems']));

        $this->image('later.png', 'later');

        $this->assertThrows(
            fn () => app(BackupRestorer::class)->restore($manifest->id),
            BackupFailed::class,
        );

        // 止まったのだから何も動いていない
        $this->assertFileExists($this->imageDir().'/later.png');
        $this->assertSame([], glob(BackupPaths::root().'/restore-*') ?: []);
    }

    // ── 取得時の参照整合 ────────────────────────────────────

    public function test_変換後の参照先が無ければ取得は失敗する(): void
    {
        $this->image('photo.png', 'original');
        $id = $this->mediaRow('media/photo.png');
        DB::table('media_variants')->insert([
            'media_id' => $id, 'format' => 'webp', 'disk' => 'public',
            'path' => 'media/variants/photo/webp.webp', 'mime' => 'image/webp',
            'size' => 1, 'width' => 1, 'height' => 1, 'created_at' => now(),
        ]);

        try {
            app(BackupBuilder::class)->create();
            $this->fail('参照先の無い backup が作れてしまった');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('webp.webp', $e->getMessage());
            $this->assertStringContainsString('取り直して', $e->getMessage());
        }

        // 半端な成果物を残さない
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
        $this->assertSame([], glob(BackupPaths::root().'/*-*-*') ?: []);
    }

    // ── 復元 ────────────────────────────────────────────────

    public function test_空のrootを戻すと後から足したものが消える(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $this->image('later.png', 'later');
        $this->file('later.pdf', 'later');

        app(BackupRestorer::class)->restore($manifest->id);

        $this->assertFileDoesNotExist($this->imageDir().'/later.png');
        $this->assertFileDoesNotExist($this->fileDir().'/later.pdf');
        $this->assertDirectoryExists($this->imageDir());
        $this->assertDirectoryExists($this->fileDir());

        // 消したのではなく退避してある（手で戻せる）
        $parked = glob(BackupPaths::disk()->path(BackupPaths::REPLACED).'/*/media/image/later.png') ?: [];
        $this->assertCount(1, $parked);
    }

    public function test_正常に往復する(): void
    {
        $this->image('photo.png', 'original');
        $this->image('variants/photo/webp.webp', 'webp bytes');
        $this->file('doc.pdf', 'pdf bytes');
        $mediaId = $this->mediaRow('media/photo.png');

        $manifest = app(BackupBuilder::class)->create();

        // そのあとで壊す
        file_put_contents($this->imageDir().'/photo.png', 'overwritten');
        @unlink($this->fileDir().'/doc.pdf');
        $this->image('later.png', 'later');
        DB::table('media')->where('id', $mediaId)->delete();

        $result = app(BackupRestorer::class)->restore($manifest->id);

        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertSame('webp bytes', file_get_contents($this->imageDir().'/variants/photo/webp.webp'));
        $this->assertSame('pdf bytes', file_get_contents($this->fileDir().'/doc.pdf'));
        $this->assertFileDoesNotExist($this->imageDir().'/later.png');
        $this->assertSame(1, DB::table('media')->where('id', $mediaId)->count());
        $this->assertGreaterThan(0, $result['restored']['media'] ?? 0);

        // 作業ディレクトリは残さない
        $this->assertSame([], glob(BackupPaths::root().'/restore-*') ?: []);
    }

    /**
     * 🔴 **途中で rename が失敗しても、元の DB と全メディアが残る。**
     *
     * root は 2 つ（画像・ファイル）。1 つ目を入れ替えた後で 2 つ目が失敗する状況を、
     * ファイル root の親を書けなくして作る。DB は transaction で戻り、
     * 動かした画像は逆順の rename で元へ戻る。
     */
    public function test_入れ替えの途中で失敗しても元のdbと全メディアが残る(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root では書込禁止を作れないため、この失敗注入は行えない');
        }

        $this->image('photo.png', 'original');
        $this->file('doc.pdf', 'pdf bytes');
        $mediaId = $this->mediaRow('media/photo.png');

        $manifest = app(BackupBuilder::class)->create();

        // そのあとの状態（これが全部残らなければならない）
        $this->image('later.png', 'later');
        $this->file('later.pdf', 'later');
        DB::table('media')->where('id', $mediaId)->update(['name' => 'renamed']);

        // ファイル root（sandbox/files）の親を書けなくする → 2 つ目の rename が失敗する
        chmod($this->sandbox, 0555);
        clearstatcache();

        if (is_writable($this->sandbox)) {
            $this->markTestSkipped('この環境では書込禁止が効かないため、失敗注入を行えない');
        }

        try {
            app(BackupRestorer::class)->restore($manifest->id);
            $this->fail('入れ替えに失敗したのに復元が成功扱いになった');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('復元前のまま', $e->getMessage());
            $this->assertStringNotContainsString($this->sandbox, $e->getMessage(), '絶対パスが理由に混ざっている');
        } finally {
            chmod($this->sandbox, 0755);
        }

        // DB は元のまま（transaction が戻った）
        $this->assertSame('renamed', DB::table('media')->where('id', $mediaId)->value('name'));

        // メディアは全部元の場所にある（1 つ目の入れ替えが戻された）
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileExists($this->imageDir().'/later.png');
        $this->assertSame('pdf bytes', file_get_contents($this->fileDir().'/doc.pdf'));
        $this->assertFileExists($this->fileDir().'/later.pdf');

        // 退避先に取り残しが無い
        $this->assertSame([], glob(BackupPaths::disk()->path(BackupPaths::REPLACED).'/*/media/image/*') ?: []);
    }

    /** 空の種別も含めて、取得時の置き場所を欠かしてはならない。 */
    public function test_missing_or_duplicate_roots_are_not_reported_as_restorable(): void
    {
        foreach ([['media/image'], ['media/image', 'media/file', 'media/image']] as $roots) {
            $manifest = app(BackupBuilder::class)->create();
            $this->rewriteManifest($manifest->id, static function (array $raw) use ($roots): array {
                $raw['media']['roots'] = $roots;

                return $raw;
            });
            $this->assertFalse(app(BackupRestorer::class)->verify($manifest->id)['ok']);
        }
    }

    /** symlink 属性は同じ bytes/hash でも通常のファイルとして展開しない。 */
    public function test_symbolic_link_entries_are_rejected_before_extraction(): void
    {
        $this->image('link.png', 'target');
        $manifest = app(BackupBuilder::class)->create();
        $this->rewriteArchive($manifest->id, static function (ZipArchive $zip): void {
            $zip->setExternalAttributesName('media/image/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $this->assertFalse(app(BackupRestorer::class)->verify($manifest->id)['ok']);
    }

    /** 保存先を変えた古い行を黙って対象外にせず、完全な取得ではないと伝える。 */
    public function test_media_outside_the_captured_roots_prevents_a_successful_backup(): void
    {
        $this->mediaRow('legacy/photo.png');
        Storage::disk('public')->put('legacy/photo.png', 'original');
        $this->expectException(BackupFailed::class);
        app(BackupBuilder::class)->create();
    }

    /** DB 制約違反の詳細には利用者の値が含まれるため、画面へ返さない。 */
    public function test_database_restore_errors_do_not_expose_rows_or_sql(): void
    {
        $first = User::factory()->create(['email' => 'restore-private-canary@example.test']);
        User::factory()->create(['email' => 'restore-other@example.test']);
        $manifest = app(BackupBuilder::class)->create();
        $this->rewriteArchive($manifest->id, static function (ZipArchive $zip) use ($first): void {
            $lines = explode("\n", trim((string) $zip->getFromName('database.jsonl')));
            $table = '';
            $columns = [];
            foreach ($lines as &$line) {
                $record = json_decode($line, true);
                if (isset($record['table'])) {
                    $table = $record['table'];
                    $columns = $record['columns'];
                } elseif ($table === 'users' && isset($record['row'])) {
                    $record['row'][array_search('email', $columns, true)] = $first->email;
                    $line = json_encode($record);
                }
            }
            unset($line);
            $dump = implode("\n", $lines)."\n";
            $zip->addFromString('database.jsonl', $dump);
            $raw = json_decode((string) $zip->getFromName('manifest.json'), true);
            $raw['checksums']['database.jsonl'] = hash('sha256', $dump);
            $zip->addFromString('manifest.json', json_encode($raw));
        });
        try {
            app(BackupRestorer::class)->restore($manifest->id);
            $this->fail('重複したメールアドレスの復元が通った');
        } catch (BackupFailed $e) {
            $this->assertStringNotContainsString('restore-private-canary', $e->getMessage());
            $this->assertStringNotContainsString('insert into', $e->getMessage());
        }
        $this->assertSame(2, User::query()->count());
    }

    // ── helpers ────────────────────────────────────────────

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

    /** @param  callable(ZipArchive): void  $edit */
    private function rewriteArchive(string $id, callable $edit): void
    {
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($id));
        $edit($zip);
        $zip->close();
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $edit */
    private function rewriteManifest(string $id, callable $edit): void
    {
        $this->rewriteArchive($id, static function (ZipArchive $zip) use ($edit): void {
            /** @var array<string, mixed> $raw */
            $raw = json_decode((string) $zip->getFromName('manifest.json'), true);
            $zip->addFromString('manifest.json', (string) json_encode($edit($raw), JSON_UNESCAPED_SLASHES));
        });
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
