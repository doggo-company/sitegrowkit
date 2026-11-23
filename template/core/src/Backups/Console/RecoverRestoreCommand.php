<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\RestoreJournal;
use AgentCms\Core\Backups\RestoreRecovery;
use Illuminate\Console\Command;

/**
 * 途中で止まった復元を整える（2026-09-09・release R09）。
 *
 * **HTTP からは行わない。** 中断した復元が残っているあいだ、サイトは 503 で止まる
 * （`RestorePendingMiddleware`）。整えるのは、サーバーに入れる人が**このコマンドで**行う —
 * ファイルを動かす判断を web の要求時間の中でしない。
 *
 * 何をするかは `RestoreRecovery` を参照。要点は「DB の印が在れば仕上げる・無ければ戻す」。
 * `--status` は何も変えずに、どちらになるかだけを出す。
 */
final class RecoverRestoreCommand extends Command
{
    protected $signature = 'agentcms:backup-recover
        {--status : 何も変えず、残っている復元の状態と、実行すると何をするかだけ表示する}';

    protected $description = '途中で止まった backup の復元を整えます（DB が commit 済みなら仕上げ、未 commit ならメディアを復元前へ戻す）';

    public function handle(RestoreRecovery $recovery): int
    {
        try {
            $status = $recovery->status();
        } catch (BackupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $status['pending']) {
            $this->info('途中で止まった復元はありません');

            return self::SUCCESS;
        }

        $this->line('中断した復元: '.$status['restore_id']);
        $this->line('  backup: '.$status['backup_id'].' / 復元前 backup: '.$status['pre_restore_id']);
        $this->line('  開始: '.$status['started_at'].' / 段階: '.$status['phase']);
        $this->line('  DB の commit: '.($status['committed'] === true ? '済み（復元後の DB）' : '未（復元前の DB）'));

        foreach ($status['roots'] as $root) {
            $this->line(sprintf('  メディア（%s）: %s', $root['root'], match ($root['state']) {
                RestoreRecovery::UNTOUCHED => '復元前の置き方（何もしない）',
                RestoreRecovery::PARKED_ONLY => '現物を退避しただけ（退避先から戻す）',
                RestoreRecovery::SWAPPED => '入れ替え済み',
                default => '判断できない（触らない）',
            }));
        }

        $this->line('  実行すると: '.($status['action'] === 'finish' ? '仕上げる（メディアは戻さない・cache を捨てる）' : '戻す（メディアを復元前へ・DB は触らない）'));

        if ((bool) $this->option('status')) {
            $this->comment('--status のため何も変えていません');

            return self::SUCCESS;
        }

        try {
            $result = $recovery->recover();
        } catch (BackupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['problems'] !== []) {
            foreach ($result['problems'] as $problem) {
                $this->error($problem);
            }

            $this->error('整えきれませんでした。上の項目を手で確かめてから、もう一度実行してください（記録は残しています: '.RestoreJournal::path().'）');

            return self::FAILURE;
        }

        $this->info($result['action'] === 'finish'
            ? '復元を仕上げました（DB もメディアも復元後の状態です）。全員のログインは切れています'
            : '復元前の状態へ戻しました（DB もメディアも復元前の状態です）');

        return self::SUCCESS;
    }
}
