<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupRunner;
use AgentCms\Core\Backups\BackupRunState;
use AgentCms\Core\Backups\BackupRunStore;
use Illuminate\Console\Command;

/**
 * 画面から始めた取得を、別プロセスとして進める（2026-09-09・OG3 改訂）。
 *
 * **人が直接打つコマンドではない**（`hidden`）。`ProcessBackupRunLauncher` が
 * `php artisan agentcms:backup-run <run>` として起動する。手で取るときは
 * 従来どおり `php artisan agentcms:backup` を使う — そちらは何も変えていない。
 *
 * run の状態（進み・結末）は `BackupRunStore` のファイルに残り、画面はそれを読む。
 */
final class RunBackupCommand extends Command
{
    protected $signature = 'agentcms:backup-run {run : 画面が発行した run の id}';

    protected $description = '画面から始めた backup の取得を別プロセスで進めます（内部用）';

    protected $hidden = true;

    public function handle(BackupRunner $runner, BackupRunStore $store): int
    {
        $runId = (string) $this->argument('run');

        if (! BackupRunStore::isValidRunId($runId) || $store->find($runId) === null) {
            $this->error('その run はありません: '.$runId);

            return self::FAILURE;
        }

        try {
            $state = $runner->run($runId, getmypid() ?: null);
        } catch (BackupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($state->status !== BackupRunState::DONE) {
            $this->error('backup を作れませんでした: '.(string) $state->error);

            return self::FAILURE;
        }

        $this->info('backup を作りました: '.(string) $state->backupId);

        return self::SUCCESS;
    }
}
