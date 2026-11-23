<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\RestoreJournal;
use AgentCms\Core\Backups\RestoreRecovery;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 回復そのものが途中で落ちても、もう一度やれば同じ結果になる（Codex レビュー・2026-09-09）。
 *
 * ## 何が起きていたか
 *
 * 手（rename）ごとに「起きたか」を実物で見ていた。「現物 → 退避」「展開先 → 現物」の 2 手を
 * 戻し終えると、展開先にも現物の場所にも物が在り、2 手目は「起きた」ようにも見える。
 * journal を履歴へ移す直前に落ちると、次回の回復は正しく戻っている状態を失敗と報告し、
 * **サイトが 503 のまま**になる。
 *
 * ## どう直したか
 *
 * root ごとに（展開先・戻す先・退避先の有無）と計画時の「戻す先は在ったか」から 1 つの状態を決める。
 * 戻し終えたら journal に `rolled_back` を書いてから作業ディレクトリを消す。
 */
final class BackupRecoveryReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sgk-recovery-review-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0700, true);

        config()->set('filesystems.disks.local.root', $this->sandbox);
        config()->set('filesystems.disks.public.root', $this->sandbox.'/public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /** 🔴 Codex の再現。戻し終えた状態にもう一度 undo をかけても、失敗と言わない・動かさない */
    public function test_replaying_rollback_before_journal_archive_is_idempotent(): void
    {
        $root = $this->sandbox.'/replay';
        mkdir($root.'/source', 0700, true);
        mkdir($root.'/target');
        file_put_contents($root.'/source/value.txt', 'backup value');
        file_put_contents($root.'/target/value.txt', 'current value');
        $moves = [[
            'root' => 'media/image', 'source' => $root.'/source', 'target' => $root.'/target',
            'parked' => $root.'/parked', 'target_existed' => true,
        ]];
        rename($root.'/target', $root.'/parked');
        rename($root.'/source', $root.'/target');

        $this->assertSame([], RestoreRecovery::undo($moves));
        // この直後の強制終了では journal はまだ残る。次回も同じ意図が読まれる。
        $this->assertSame([], RestoreRecovery::undo($moves));
        $this->assertSame('current value', file_get_contents($root.'/target/value.txt'));
        $this->assertSame('backup value', file_get_contents($root.'/source/value.txt'));
        $this->assertFileDoesNotExist($root.'/parked');
    }

    /** 2 手の途中（退避先から戻す前）で落ちても、続きから戻る */
    public function test_rollback_resumes_between_the_two_renames(): void
    {
        $root = $this->sandbox.'/between';
        mkdir($root.'/source', 0700, true);
        mkdir($root.'/parked');
        file_put_contents($root.'/source/value.txt', 'backup value');
        file_put_contents($root.'/parked/value.txt', 'current value');
        $moves = [[
            'root' => 'media/image', 'source' => $root.'/source', 'target' => $root.'/target',
            'parked' => $root.'/parked', 'target_existed' => true,
        ]];

        $this->assertSame(RestoreRecovery::PARKED_ONLY, RestoreRecovery::rootState($moves[0]));
        $this->assertSame([], RestoreRecovery::undo($moves));
        $this->assertSame([], RestoreRecovery::undo($moves));
        $this->assertSame('current value', file_get_contents($root.'/target/value.txt'));
    }

    /**
     * root が複数で、片方は戻し済み・片方は入れ替わったまま。戻す先が最初から無かった root も混ぜる。
     * 何度やっても、全 root が復元前の置き方に落ち着く。
     */
    public function test_multiple_roots_partially_rolled_back_converge(): void
    {
        $root = $this->sandbox.'/multi';
        // image: 戻す先は在った。まだ入れ替わったまま
        mkdir($root.'/image-parked', 0700, true);
        mkdir($root.'/image-target');
        file_put_contents($root.'/image-parked/v.txt', 'current image');
        file_put_contents($root.'/image-target/v.txt', 'backup image');
        // file: 戻す先は最初から無かった。入れ替わったまま（展開先は空、戻す先に backup）
        mkdir($root.'/file-target');
        file_put_contents($root.'/file-target/v.txt', 'backup file');
        // extra: 戻す先は在った。もう戻し済み
        mkdir($root.'/extra-source');
        mkdir($root.'/extra-target');
        file_put_contents($root.'/extra-source/v.txt', 'backup extra');
        file_put_contents($root.'/extra-target/v.txt', 'current extra');

        $moves = [
            ['root' => 'media/image', 'source' => $root.'/image-source', 'target' => $root.'/image-target', 'parked' => $root.'/image-parked', 'target_existed' => true],
            ['root' => 'media/file', 'source' => $root.'/file-source', 'target' => $root.'/file-target', 'parked' => $root.'/file-parked', 'target_existed' => false],
            ['root' => 'media/extra', 'source' => $root.'/extra-source', 'target' => $root.'/extra-target', 'parked' => $root.'/extra-parked', 'target_existed' => true],
        ];

        $this->assertSame(
            [RestoreRecovery::SWAPPED, RestoreRecovery::SWAPPED, RestoreRecovery::UNTOUCHED],
            array_map(RestoreRecovery::rootState(...), $moves),
        );

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame([], RestoreRecovery::undo($moves), "{$i} 回目");
            $this->assertSame('current image', file_get_contents($root.'/image-target/v.txt'));
            $this->assertSame('backup image', file_get_contents($root.'/image-source/v.txt'));
            $this->assertFileDoesNotExist($root.'/file-target');
            $this->assertSame('backup file', file_get_contents($root.'/file-source/v.txt'));
            $this->assertSame('current extra', file_get_contents($root.'/extra-target/v.txt'));
        }
    }

    /** 判定できない置き方は触らず、理由を返す（勝手に「戻した」と言わない） */
    public function test_ambiguous_layout_is_left_alone(): void
    {
        $root = $this->sandbox.'/ambiguous';
        // 戻す先は在ったはずなのに、展開先・戻す先・退避先の 3 つが全部在る
        foreach (['source', 'target', 'parked'] as $dir) {
            mkdir($root.'/'.$dir, 0700, true);
            file_put_contents($root.'/'.$dir.'/v.txt', $dir);
        }
        $moves = [[
            'root' => 'media/image', 'source' => $root.'/source', 'target' => $root.'/target',
            'parked' => $root.'/parked', 'target_existed' => true,
        ]];

        $problems = RestoreRecovery::undo($moves);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('判断できない', $problems[0]);
        foreach (['source', 'target', 'parked'] as $dir) {
            $this->assertSame($dir, file_get_contents($root.'/'.$dir.'/v.txt'));
        }
    }

    /**
     * 🔴 本物の経路。作業ディレクトリを消したあと・journal を履歴へ移す前に落ちた形を作り、
     * `recover()` をもう一度かける。実物からは判定できないので、journal の `rolled_back` が効く。
     */
    public function test_recover_replays_after_workspace_cleanup_before_archive(): void
    {
        $this->image('photo.png', 'original');
        $manifest = app(BackupBuilder::class)->create();

        $workspace = BackupPaths::workspace('restore-replay');
        mkdir($workspace.'/media/image', 0700, true);
        file_put_contents($workspace.'/media/image/photo.png', 'from backup');
        $parked = BackupPaths::disk()->path(BackupPaths::REPLACED).'/replay/media/image';
        mkdir(dirname($parked), 0700, true);

        $journal = RestoreJournal::begin('restore-replay', $manifest->id, 'pre', $workspace, [
            ['root' => 'media/image', 'source' => $workspace.'/media/image', 'target' => $this->imageDir(), 'parked' => $parked, 'target_existed' => true],
        ]);
        rename($this->imageDir(), $parked);
        rename($workspace.'/media/image', $this->imageDir());

        // 1 回目: 戻す。ここで「戻した」を記録してから作業ディレクトリを消し、履歴へ移す
        $this->assertSame([], app(RestoreRecovery::class)->recover()['problems']);
        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));

        // 履歴へ移す直前で落ちた形を再現: rolled_back まで書かれ、作業ディレクトリは無く、journal は残っている
        $history = RestoreJournal::historyDirectory().'/restore-replay.json';
        $decoded = json_decode((string) file_get_contents($history), true);
        $this->assertSame(RestoreJournal::ROLLED_BACK, $decoded['phase'] ?? null);
        unset($decoded['outcome'], $decoded['finished_at']);
        file_put_contents(RestoreJournal::path(), json_encode($decoded));
        unlink($history);
        $this->assertDirectoryDoesNotExist($workspace);
        $this->get('/')->assertStatus(503);

        // 2 回目: 実物は「展開先が無い」ので判定できないが、journal が「戻した」と言っている
        $this->assertSame(RestoreRecovery::AMBIGUOUS, RestoreRecovery::rootState($journal->moves()[0]));
        $this->assertSame([], app(RestoreRecovery::class)->recover()['problems']);
        $this->assertFalse(RestoreJournal::isPending());
        $this->assertSame('original', file_get_contents($this->imageDir().'/photo.png'));
        $this->assertFileExists($history);
        $this->get('/')->assertOk();
    }

    /** `rolled_back` と書いてあるのに DB は commit 済み — あり得ない組み合わせは触らない */
    public function test_rolled_back_journal_with_commit_marker_is_not_touched(): void
    {
        $manifest = app(BackupBuilder::class)->create();
        $workspace = BackupPaths::workspace('restore-contradiction');
        mkdir($workspace, 0700, true);
        $journal = RestoreJournal::begin('restore-contradiction', $manifest->id, 'pre', $workspace, [
            ['root' => 'media/image', 'source' => $workspace.'/media/image', 'target' => $this->imageDir(), 'parked' => $this->sandbox.'/parked', 'target_existed' => true],
        ]);
        $journal->advance(RestoreJournal::ROLLED_BACK);
        DB::table(RestoreRecovery::MARKER_TABLE)->insert([
            'restore_id' => 'restore-contradiction', 'backup_id' => $manifest->id, 'pre_restore_id' => 'pre', 'committed_at' => now(),
        ]);

        $result = app(RestoreRecovery::class)->recover();

        $this->assertNotSame([], $result['problems']);
        $this->assertTrue(RestoreJournal::isPending());
        $this->assertDirectoryExists($workspace);
    }

    private function imageDir(): string
    {
        return Storage::disk(MediaKind::Image->disk())->path(MediaKind::Image->directory());
    }

    private function image(string $relative, string $contents): void
    {
        Storage::disk(MediaKind::Image->disk())->put(MediaKind::Image->directory().'/'.$relative, $contents);
    }
}
