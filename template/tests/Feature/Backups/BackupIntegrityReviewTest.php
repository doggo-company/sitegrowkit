<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupProgress;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\BackupStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Tests\TestCase;
use ZipArchive;

// リリース前レビュー用。常に SQLite / Storage::fake の中で再現する。
final class BackupIntegrityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_empty_snapshot_removes_later_files(): void
    {
        $backup = app(BackupBuilder::class)->create();
        Storage::disk('public')->put('media/later.png', 'later');
        app(BackupRestorer::class)->restore($backup->id);
        $this->assertFalse(Storage::disk('public')->exists('media/later.png'), 'Empty snapshot retained files created later');
    }

    public function test_missing_archived_media_is_not_reported_restorable(): void
    {
        Storage::disk('public')->put('media/original.png', 'original');
        $backup = app(BackupBuilder::class)->create();
        $zip = new ZipArchive;
        $zip->open(BackupPaths::archive($backup->id));
        $zip->deleteName('media/image/original.png');
        $zip->close();
        $this->assertFalse(app(BackupRestorer::class)->verify($backup->id)['ok'], 'Missing archived image was reported restorable');
    }

    public function test_media_removed_after_database_snapshot_fails_creation(): void
    {
        Storage::disk('public')->put('media/original.png', 'original');
        Media::query()->create(['name' => 'test', 'path' => 'media/original.png', 'mime' => 'image/png', 'size' => 8, 'width' => 1, 'height' => 1]);
        $progress = new class implements BackupProgress
        {
            public function stage(BackupStage $stage): void
            {
                if ($stage === BackupStage::Media) {
                    Storage::disk('public')->delete('media/original.png');
                }
            }

            public function advance(BackupStage $stage, int $done, ?int $total = null): void {}
        };
        $this->expectException(BackupFailed::class);
        app(BackupBuilder::class)->create('', $progress);
    }
}
