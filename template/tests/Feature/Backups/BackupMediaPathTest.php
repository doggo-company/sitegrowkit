<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * backup が写すのは、**製品が本当に書いている場所**である
 * （WP 一周 6 周目 2026-09-03 / 効くもの ①）。
 *
 * ## 何が起きていたか
 *
 * 画面はこう言う — 「投稿・設定・**メディア**をまとめて保存します」。
 * 実測（画像を 1 枚上げてから `agentcms:backup`）:
 *
 * ```
 * backup を作りました: …（5 KB / 75 行 / メディア 0 件）
 * ```
 *
 * zip の中身は `database.jsonl` と `manifest.json` **だけ**。
 * `manifest.json` の `media` は `{"files": 0, "bytes": 0}`。
 *
 * `BackupBuilder::MEDIA` が見ていたのは `public/uploads` と `private/media`。
 * 製品が書くのは `public/media`（画像）と `local/files`（ファイル）である。
 * **どちらの案件でも、いつ取っても、メディアは 0 件**だった。
 *
 * `BackupRestorer` も同じ 2 か所へ戻すので、**戻しても画像は 1 枚も戻らない**。
 * DB の `media` 行だけが残るため、**戻した先では画像が全部 404 になる**。
 *
 * ## なぜ既存の試験が緑だったか
 *
 * 🔴 `BackupTest::test_メディアも入る` は
 * `storage/app/public/uploads` へ自分でファイルを置いてから確かめていた —
 * **製品が 1 度も書かない場所**である。
 * 「写す場所」と「置く場所」の**両方が同じ思い込み**なので、ずっと緑だった。
 *
 * だからここは **`MediaKind` に置き場所を聞いてから**置く。
 * 置き場所が変わっても、試験は自動で新しい場所を見る。
 */
final class BackupMediaPathTest extends TestCase
{
    use RefreshDatabase;

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
    protected function setUp(): void
    {
        parent::setUp();

        $sandbox = storage_path('framework/testing/backup-media-'.getmypid());

        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root) && str_contains($root, 'framework/testing/')) {
            self::removeTree($root);
        }

        parent::tearDown();
    }

    private static function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }

    /**
     * 「メディア N 件」は、**人が上げた枚数**で数える（WP 一周 9 周目 2026-09-04）。
     *
     * 画像を 1 枚上げると、製品は webp と avif も作る。ファイルは 3 個になるので、
     * 数え方が「ファイルの個数」だと backup は「メディア 3 件」と言う。
     * メディア画面は「1 件」なので、**どこにも一致しない数字**が出る。
     *
     * 「画像は全部入ったか」を確かめようとした人が、確かめられない。
     * 10 枚上げれば「30 件」になる。
     *
     * 変換後は写す（戻すときに要る）。**数えないだけ**である。
     */
    public function test_変換後の画像を件数に数えない(): void
    {
        $kind = MediaKind::Image;

        Storage::disk($kind->disk())->put($kind->directory().'/photo.png', 'image bytes');
        Storage::disk($kind->disk())->put($kind->directory().'/variants/photo/webp.webp', 'webp bytes');
        Storage::disk($kind->disk())->put($kind->directory().'/variants/photo/avif.avif', 'avif bytes');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(1, $manifest->media['files'], '変換後まで数えている');

        // 大きさは全部ぶん（zip の中身と合わせる）
        $this->assertGreaterThan(strlen('image bytes'), $manifest->media['bytes']);

        // 数えないだけで、写してはいる
        $entries = $this->entries($manifest->id);

        foreach (['#/photo\.png$#', '#/webp\.webp$#', '#/avif\.avif$#'] as $pattern) {
            $this->assertTrue(
                (bool) preg_grep($pattern, $entries),
                "変換後が zip に入っていない: {$pattern}",
            );
        }
    }

    /**
     * 🔴 **置き場所は製品に聞く。** 試験が場所を決め打ちにすると、
     * 製品がどこへ書こうと緑になる（それが今回の見落としである）。
     */
    public function test_製品が書く場所の画像が_backupに入る(): void
    {
        $kind = MediaKind::Image;

        Storage::disk($kind->disk())->put($kind->directory().'/photo.png', 'image bytes');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(1, $manifest->media['files'], 'アップロードした画像が入っていない');
        $this->assertGreaterThan(0, $manifest->media['bytes']);

        $entries = $this->entries($manifest->id);

        $this->assertTrue(
            (bool) preg_grep('#/photo\.png$#', $entries),
            'zip に画像が入っていない: '.implode(' / ', $entries),
        );
    }

    public function test_製品が書く場所のファイルも_backupに入る(): void
    {
        $kind = MediaKind::File;

        Storage::disk($kind->disk())->put($kind->directory().'/shiryo.pdf', 'pdf bytes');

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(1, $manifest->media['files'], 'アップロードしたファイルが入っていない');
    }

    /**
     * 🔴 **戻したら 404 にならない。**
     *
     * DB だけ戻って画像が戻らないと、記事は残るのに絵が全部消える。
     */
    public function test_戻すと画像が同じ場所へ返ってくる(): void
    {
        $kind = MediaKind::Image;
        $path = $kind->directory().'/photo.png';

        Storage::disk($kind->disk())->put($path, 'original');

        $manifest = app(BackupBuilder::class)->create();

        Storage::disk($kind->disk())->delete($path);
        $this->assertFalse(Storage::disk($kind->disk())->exists($path));

        app(BackupRestorer::class)->restore($manifest->id);

        $this->assertTrue(
            Storage::disk($kind->disk())->exists($path),
            '戻したのに画像が返っていない',
        );
        $this->assertSame('original', Storage::disk($kind->disk())->get($path));
    }

    /**
     * 置き場所が増えたときに、**黙って写し漏らさない**。
     *
     * `MediaKind` が知っている場所は、全部 backup の対象でなければならない。
     */
    public function test_media_kindが知っている置き場所を全部写す(): void
    {
        foreach (MediaKind::cases() as $kind) {
            Storage::disk($kind->disk())->put($kind->directory().'/a.bin', 'x');
        }

        $manifest = app(BackupBuilder::class)->create();

        $this->assertSame(
            count(MediaKind::cases()),
            $manifest->media['files'],
            '写していない置き場所がある',
        );
    }

    /** @return list<string> */
    private function entries(string $id): array
    {
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($id));

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $zip->close();

        return $names;
    }
}
