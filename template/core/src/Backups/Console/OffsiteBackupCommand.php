<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupSchedule;
use AgentCms\Core\Backups\OffsiteBackups;
use Illuminate\Console\Command;

/**
 * 手元の backup を外の置き場所へ写す／外にあるものを並べる。
 *
 * `--list` は自動実行の時刻・置き場所・外にある世代を出す。**設定が足りない
 * （外が未設定・時刻が読めない）か外が読めなければ exit 1** — 見張りから叩いて使える。
 */
final class OffsiteBackupCommand extends Command
{
    protected $signature = 'agentcms:backup-offsite
        {id? : 写す backup の id（省略すると手元のいちばん新しいもの）}
        {--list : 写さずに、自動実行の設定と外にある backup を並べる}';

    protected $description = '手元の backup を外の置き場所へ写します（--list で外にある世代を表示）';

    public function handle(OffsiteBackups $offsite, BackupCatalog $catalog): int
    {
        if ((bool) $this->option('list')) {
            return $this->list($offsite);
        }

        $id = $this->argument('id');

        if (! is_string($id) || $id === '') {
            $latest = null;

            foreach ($catalog->all() as $manifest) {
                if (! $manifest->isPreRestore()) {
                    $latest = $manifest->id;
                    break;
                }
            }

            if ($latest === null) {
                $this->error('手元に backup がありません');

                return self::FAILURE;
            }

            $id = $latest;
        }

        try {
            $sent = $offsite->push($id);
        } catch (BackupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('外の置き場所へ送りました: %s%s.zip（%d bytes・sha256 一致）', $offsite->describe(), $sent['id'], $sent['bytes']));

        return self::SUCCESS;
    }

    private function list(OffsiteBackups $offsite): int
    {
        $ok = true;

        if (! BackupSchedule::enabled()) {
            $this->line('自動実行: しない（AGENTCMS_BACKUP_SCHEDULE が空）');
        } elseif (! BackupSchedule::valid()) {
            $this->error('自動実行: 時刻の設定が読めません（HH:MM で書く）: '.BackupSchedule::time());
            $ok = false;
        } else {
            $this->line('自動実行: 毎日 '.BackupSchedule::time().'（サーバーの cron で schedule:run が動いていること）');
        }

        if (! $offsite->configured()) {
            $this->error('外の置き場所: 未設定（backup はこのサーバーの中にしかありません）');

            return self::FAILURE;
        }

        try {
            $this->line('外の置き場所: '.$offsite->describe().'（'.$offsite->keep().' 世代残す）');
            $ids = $offsite->ids();
        } catch (BackupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($ids === []) {
            $this->warn('外にある backup: まだありません');
        }

        foreach ($ids as $id) {
            $this->line('  '.$id);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
