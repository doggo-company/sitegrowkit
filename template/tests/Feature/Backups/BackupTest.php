<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupManifest;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\DatabaseDump;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;
use ZipArchive;

/**
 * backup の取得と復元（Phase 9.11 / STAGE 24B・24C）。
 *
 * ここで固定するのは **戻せること**と、**戻してはいけないものが入らないこと**である。
 */
final class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 使い捨ての置き場所（本物の storage を汚さない）
        $sandbox = storage_path('framework/testing/backups-'.getmypid());

        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');
    }

    /**
     * 🔴 **本物の storage を触らない**（WP 一周 6 周目 2026-09-03）。
     *
     * メディアの置き場所を実物（`public` disk の `media/`）に合わせた結果、
     * 後始末がそのまま**開発機に上がっている画像を消す**ようになった。
     * 実際に 1 度消している（確認用 install の `media/` が 1480 → 3 件）。
     *
     * だから **disk の根を使い捨てへ向ける**。`local` は前からそうしていた。
     * こうしておけば、後始末は使い捨てのほうだけを消せばよい。
     */
    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root)) {
            self::removeTree($root);
        }

        parent::tearDown();
    }

    // ── 取得 ────────────────────────────────────────────────

    public function test_取得できて中身が揃っている(): void
    {
        $this->entry('backup される記事');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertFileExists(BackupPaths::archive($manifest->id));
        $this->assertSame(BackupBuilder::FORMAT, $manifest->format);
        $this->assertGreaterThan(0, $manifest->tables['post'] ?? 0);
        $this->assertNotSame('', $manifest->checksums['database.jsonl'] ?? '');

        $names = $this->entries($manifest->id);

        $this->assertContains('manifest.json', $names);
        $this->assertContains('database.jsonl', $names);
    }

    public function test_戻してはいけない表は入らない(): void
    {
        DB::table('sessions')->insert([
            'id' => 'abc', 'payload' => 'x', 'last_activity' => time(),
        ]);

        $manifest = app(BackupBuilder::class)->create();

        // 戻すと他人のログイン状態が復活する
        foreach (DatabaseDump::EXCLUDED_TABLES as $table) {
            $this->assertArrayNotHasKey($table, $manifest->tables, $table);
        }

        $this->assertStringNotContainsString('"table":"sessions"', $this->dumpOf($manifest->id));
    }

    public function test_秘密が混ざっていたら成果物にしない(): void
    {
        // メディアの中に鍵が紛れている状況を作る
        $this->media('leaked.key', 'secret');

        $this->assertThrows(
            fn () => app(BackupBuilder::class)->create(),
            BackupFailed::class,
        );

        // **半端な成果物を残さない**
        $this->assertSame([], glob(BackupPaths::root().'/*.zip') ?: []);
    }

    public function test_メディアも入る(): void
    {
        $this->media('photo.txt', 'image bytes');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(1, $manifest->media['files']);
        $this->assertContains('media/image/photo.txt', $this->entries($manifest->id));
    }

    public function test_manifestは何を入れなかったかを書く(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        // 復元した人が「何が戻っていないか」を読めるようにする
        $this->assertContains('table:sessions', $manifest->excluded);
        $this->assertContains('.env / .env.*', $manifest->excluded);
        $this->assertContains('.agentcms/local/', $manifest->excluded);
    }

    public function test_backupはpublicの下に置かれない(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        $path = BackupPaths::archive($manifest->id);

        // **URL を推測されただけで全データが持ち去られる場所に置かない**
        $this->assertStringNotContainsString('/public/', $path);
        $this->assertStringNotContainsString(public_path(), $path);
        $this->assertStringContainsString('backups', $path);
    }

    // ── 世代 ────────────────────────────────────────────────

    public function test_世代を整理する(): void
    {
        config()->set('agentcms.backup.keep', 2);

        for ($i = 0; $i < 4; $i++) {
            app(BackupBuilder::class)->create();
            usleep(1100);
        }

        app(BackupCatalog::class)->prune();

        $this->assertCount(2, app(BackupCatalog::class)->all());
    }

    public function test_復元前backupは整理で消さない(): void
    {
        config()->set('agentcms.backup.keep', 1);

        $pre = app(BackupBuilder::class)->create(BackupPaths::PRE_RESTORE_PREFIX);
        app(BackupBuilder::class)->create();
        app(BackupBuilder::class)->create();

        app(BackupCatalog::class)->prune();

        // 事故のあとに「戻す前の状態」が消えていた、を起こさない
        $this->assertTrue(app(BackupCatalog::class)->exists($pre->id));
    }

    public function test_一覧は新しい順(): void
    {
        $first = app(BackupBuilder::class)->create()->id;
        usleep(1100);
        $second = app(BackupBuilder::class)->create()->id;

        $ids = array_map(static fn (BackupManifest $m): string => $m->id, app(BackupCatalog::class)->all());

        $this->assertSame([$second, $first], $ids);
    }

    /**
     * 🔴 **種類が混ざっても、時刻の順である**（WP 一周 8 周目 2026-09-04 / 効くもの ②）。
     *
     * 上の試験は手で取ったものを 2 つ並べるだけなので、**接頭辞が同じ**ものしか見ていない。
     * 実物では `pre-restore-…` が混ざる。並べ替えは id の文字列比較だったので、
     * ASCII で `p` > `2` — **`pre-restore-*` が時刻に関係なく必ず上に来ていた**。
     *
     * 実測（買った人と同じ install）: 一度復元したあとに取り直した最新の backup が、
     * 9 時間前の自動 backup 6 件の下、**7 番目**に並んでいた。
     *
     * 一番上を押せば最新に戻ると思う。実際は 9 時間前に戻る。しかも戻す直前に
     * いまの状態が `pre-restore` として保存されるので、**間違いに気づいたときには
     * 一覧がさらに 1 段ずれている**。
     */
    public function test_一覧は種類が混ざっても新しい順(): void
    {
        $old = app(BackupBuilder::class)->create(BackupPaths::PRE_RESTORE_PREFIX)->id;
        usleep(1100);
        $newer = app(BackupBuilder::class)->create()->id;
        usleep(1100);
        $newest = app(BackupBuilder::class)->create(BackupPaths::PRE_RESTORE_PREFIX)->id;

        $ids = array_map(static fn (BackupManifest $m): string => $m->id, app(BackupCatalog::class)->all());

        $this->assertSame([$newest, $newer, $old], $ids);
    }

    /** 画面が「どちらの種類か」を読めることは変えない（並びで区別させない） */
    public function test_一覧は種類の印を持ち続ける(): void
    {
        app(BackupBuilder::class)->create(BackupPaths::PRE_RESTORE_PREFIX);
        usleep(1100);
        app(BackupBuilder::class)->create();

        $flags = array_map(
            static fn (BackupManifest $m): bool => $m->isPreRestore(),
            app(BackupCatalog::class)->all(),
        );

        $this->assertSame([false, true], $flags);
    }

    public function test_おかしなidは受け付けない(): void
    {
        // ここを緩めるとディレクトリを抜け出せる
        foreach (['../etc/passwd', 'a/b', '', str_repeat('a', 200)] as $id) {
            $this->assertFalse(BackupPaths::isValidId($id), $id);
            $this->assertNull(app(BackupCatalog::class)->read($id), $id);
        }
    }

    // ── 検証 ────────────────────────────────────────────────

    public function test_検証は問題が無ければ通る(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertTrue($result['ok'], implode(' / ', $result['problems']));
    }

    public function test_中身が変わっていたら検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($manifest->id));
        $zip->addFromString('database.jsonl', "{\"table\":\"post\",\"columns\":[\"id\"]}\n");
        $zip->close();

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('checksum', implode(' ', $result['problems']));
    }

    public function test_dbの形が違えば検証で止まる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        // backup のあとに migration が 1 つ増えた状況
        DB::table('migrations')->insert(['migration' => '9999_99_99_999999_something_new', 'batch' => 99]);

        $result = app(BackupRestorer::class)->verify($manifest->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DB の形が違います', implode(' ', $result['problems']));
    }

    public function test_無いbackupは検証で止まる(): void
    {
        $result = app(BackupRestorer::class)->verify('nope-000000-000000');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['manifest']);
    }

    // ── 復元 ────────────────────────────────────────────────

    public function test_復元すると取得時点に戻る(): void
    {
        $kept = $this->entry('取得時にあった記事');
        $manifest = app(BackupBuilder::class)->create();

        // そのあとで壊す
        DB::table('post')->delete();
        $this->entry('あとから足した記事');

        $result = app(BackupRestorer::class)->restore($manifest->id);

        $this->assertSame(['取得時にあった記事'], DB::table('post')->pluck('title')->all());
        $this->assertSame($kept, (int) DB::table('post')->value('id'), 'id まで戻っていない');
        $this->assertGreaterThan(0, $result['restored']['post'] ?? 0);
    }

    public function test_復元すると復元前backupが自動で取られる(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        $result = app(BackupRestorer::class)->restore($manifest->id);

        // これが最後の綱である
        $this->assertStringStartsWith(BackupPaths::PRE_RESTORE_PREFIX, $result['preRestore']);
        $this->assertTrue(app(BackupCatalog::class)->exists($result['preRestore']));
    }

    public function test_dbの形が違うbackupは復元できない(): void
    {
        $this->entry('のこる記事');
        $manifest = app(BackupBuilder::class)->create();

        DB::table('migrations')->insert(['migration' => '9999_99_99_999999_something_new', 'batch' => 99]);

        $this->assertThrows(
            fn () => app(BackupRestorer::class)->restore($manifest->id),
            BackupFailed::class,
        );

        // 止まったのだから、中身は 1 つも変わっていない
        $this->assertSame(1, DB::table('post')->count());
    }

    public function test_差し替えられたbackupは復元しない(): void
    {
        $manifest = app(BackupBuilder::class)->create();

        // 許していない表への挿入を仕込む
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($manifest->id));
        $dump = (string) $zip->getFromName('database.jsonl');
        $dump .= json_encode(['table' => 'sessions', 'columns' => ['id', 'payload', 'last_activity']])."\n";
        $dump .= json_encode(['row' => ['evil', 'x', 1]])."\n";
        $zip->addFromString('database.jsonl', $dump);
        $zip->close();

        // checksum が合わないので、そもそも復元まで行かない
        $this->assertThrows(
            fn () => app(BackupRestorer::class)->restore($manifest->id),
            BackupFailed::class,
        );

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_メディアも入れ替わる(): void
    {
        $this->media('kept.txt', 'original');

        $manifest = app(BackupBuilder::class)->create();

        // そのあとで消して、別のものを置く
        @unlink($this->mediaDir().'/kept.txt');
        $this->media('later.txt', 'added later');

        app(BackupRestorer::class)->restore($manifest->id);

        $this->assertFileExists($this->mediaDir().'/kept.txt');
        $this->assertSame('original', file_get_contents($this->mediaDir().'/kept.txt'));
        // 取得時に無かったものは戻らない（消したはずの画像が復活しない）
        $this->assertFileDoesNotExist($this->mediaDir().'/later.txt');
    }

    // ── コマンド ────────────────────────────────────────────

    public function test_コマンドで取得できる(): void
    {
        $this->artisan('agentcms:backup')->assertSuccessful();

        $this->assertCount(1, app(BackupCatalog::class)->all());
    }

    public function test_dry_runは何も作らない(): void
    {
        $this->artisan('agentcms:backup --dry-run')->assertSuccessful();

        $this->assertSame([], app(BackupCatalog::class)->all());
    }

    // ── helpers ────────────────────────────────────────────

    private function entry(string $title): int
    {
        return (int) DB::table('post')->insertGetId([
            'title' => $title, 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'draft', 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 🔴 **置き場所は製品に聞く**（WP 一周 6 周目 2026-09-03）。
     *
     * ここは以前 `storage/app/public/uploads` へ直に置いていた。
     * **製品が 1 度も書かない場所**である。「写す場所」と「置く場所」の
     * 両方が同じ思い込みだったので、**メディアが 0 件でも緑だった**。
     */
    private function media(string $name, string $contents): void
    {
        $dir = $this->mediaDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir.'/'.$name, $contents);
    }

    /** 製品が画像を置く場所（絶対パス） */
    private function mediaDir(): string
    {
        return Storage::disk(MediaKind::Image->disk())
            ->path(MediaKind::Image->directory());
    }

    /** @return list<string> */
    private function entries(string $id): array
    {
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($id));

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    private function dumpOf(string $id): string
    {
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($id));
        $dump = (string) $zip->getFromName('database.jsonl');
        $zip->close();

        return $dump;
    }

    /** shell を使わずに消す（Windows でも動く） */
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
