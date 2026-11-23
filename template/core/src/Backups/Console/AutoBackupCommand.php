<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\OffsiteBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 自動の backup（schedule から毎日呼ばれる。手で打ってもよい）。
 *
 * 順序を固定する:
 *
 *   1. 取る（`agentcms:backup` と同じ `BackupBuilder`）
 *   2. 外の置き場所が設定されていれば写す。大きさと checksum まで確かめる
 *   3. 写せたときだけ、外の古い世代を整理する
 *   4. 手元の世代を整理する（今までと同じ 5 世代）
 *
 * **外へ写せなかったら exit 1 で終わり、ログ（error）にも残す。** 手元の backup は残す。
 * 外の置き場所が未設定なら、取ったうえで「このサーバーの中にしか無い」と出す（exit 0）。
 */
final class AutoBackupCommand extends Command
{
    protected $signature = 'agentcms:backup-auto';

    protected $description = 'backup を取り、設定した外の置き場所へ写して、古い世代を整理します（毎日の自動実行用）';

    public function handle(BackupBuilder $builder, BackupCatalog $catalog, OffsiteBackups $offsite): int
    {
        try {
            $manifest = $builder->create();
        } catch (BackupFailed $e) {
            $this->error('backup を作れませんでした: '.$e->getMessage());
            Log::error('agentcms:backup-auto: backup を作れませんでした: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('backup を作りました: '.$manifest->id);

        $failed = false;

        if ($offsite->configured()) {
            try {
                $sent = $offsite->push($manifest->id);
                $this->info(sprintf('外の置き場所へ送りました: %s%s.zip（%d bytes・sha256 一致）', $offsite->describe(), $sent['id'], $sent['bytes']));

                $removed = $offsite->prune();

                if ($removed !== []) {
                    $this->line('外の古い世代を '.count($removed).' 件片付けました');
                }
            } catch (BackupFailed $e) {
                $reason = $e->getMessage();

                if (! str_starts_with($reason, '外の置き場所へ送れませんでした')) {
                    $reason = '外の置き場所へ送れませんでした: '.$reason;
                }

                $this->error($reason.'（手元の backup は残しています）');

                // schedule:run は出力を捨てる。夜中の失敗をログにも残す（鍵などの秘密は理由に含まれない）
                Log::error('agentcms:backup-auto: '.$reason, ['backup' => $manifest->id]);
                $failed = true;
            }
        } else {
            $this->warn('外の置き場所が未設定です。backup はこのサーバーの中にしかありません（AGENTCMS_BACKUP_OFFSITE_DISK）');
        }

        $removedLocal = $catalog->prune();

        if ($removedLocal !== []) {
            $this->line('手元の古い世代を '.count($removedLocal).' 件片付けました');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
