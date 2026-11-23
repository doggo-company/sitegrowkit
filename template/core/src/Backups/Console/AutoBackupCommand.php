<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\AutoBackupAlert;
use AgentCms\Core\Backups\AutoBackupStatus;
use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\OffsiteBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自動の backup（schedule から毎日呼ばれる。手で打ってもよい）。
 *
 * 順序を固定する:
 *
 *   1. 始めたことを記録する（`AutoBackupStatus`）
 *   2. 取る（`agentcms:backup` と同じ `BackupBuilder`）
 *   3. 外の置き場所が設定されていれば写す。大きさと checksum まで確かめる
 *   4. 写せたときだけ、外の古い世代を整理する
 *   5. 手元の世代を整理する（今までと同じ 5 世代）。外の整理の失敗は「送り出しの失敗」と分けて知らせる
 *   6. 結果を記録する
 *
 * **失敗したら exit 1。** ただし cron が叩く `schedule:run` はそれでも exit 0（DONE）で終わり、
 * 出力も捨てる。だから失敗は次の 3 つにも残す — ログ（error）・結果の記録（状態の表示
 * `agentcms:backup-offsite --list` が exit 1、管理画面のバックアップ画面とサイドバーに出る）・
 * 知らせのメール（`AGENTCMS_BACKUP_ALERT_MAIL` を決めたときだけ）。手元の backup は残す。
 * 理由の文は鍵を伏せてから出す（`OffsiteBackups::redact()`）。
 *
 * 外の置き場所が未設定なら、取ったうえで「このサーバーの中にしか無い」と出す（exit 0）。
 */
final class AutoBackupCommand extends Command
{
    protected $signature = 'agentcms:backup-auto';

    protected $description = 'backup を取り、設定した外の置き場所へ写して、古い世代を整理します（毎日の自動実行用）';

    public function handle(
        BackupBuilder $builder,
        BackupCatalog $catalog,
        OffsiteBackups $offsite,
        AutoBackupStatus $status,
        AutoBackupAlert $alert,
    ): int {
        if (! $status->started()) {
            // 取ることは続ける。終わりの記録も書けなければ、そこで失敗として知らせる
            $this->warn('自動バックアップの開始を記録できませんでした（storage の書き込み権限を確かめてください）');
        }

        try {
            $manifest = $builder->create();
        } catch (Throwable $e) {
            return $this->recordFailure('backup', 'backup を作れませんでした: '.$e->getMessage(), null, $offsite, $status, $alert);
        }

        $this->info('backup を作りました: '.$manifest->id);

        try {
            if ($offsite->configured()) {
                $sent = $offsite->push($manifest->id);
                $this->info(sprintf('外の置き場所へ送りました: %s%s.zip（%d bytes・sha256 一致）', $offsite->describe(), $sent['id'], $sent['bytes']));
            } else {
                $this->warn('外の置き場所が未設定です。backup はこのサーバーの中にしかありません（AGENTCMS_BACKUP_OFFSITE_DISK）');
            }
        } catch (Throwable $e) {
            $reason = $e->getMessage();

            if (! str_starts_with($reason, '外の置き場所へ送れませんでした')) {
                $reason = '外の置き場所へ送れませんでした: '.$reason;
            }

            try {
                $this->pruneLocal($catalog);
            } catch (Throwable) {
                // 知らせるのは送れなかったこと。手元の整理は次の回にやり直す
            }

            return $this->recordFailure('offsite', $reason, $manifest->id, $offsite, $status, $alert);
        }

        /*
         * 外の古い世代の整理。送れた後なので、失敗しても「送り出しの失敗」とは言わない
         * （削除の権限が無い鍵だと毎晩ここで落ちる — 別の文で知らせる）。
         */
        if ($offsite->configured()) {
            try {
                $removed = $offsite->prune();

                if ($removed !== []) {
                    $this->line('外の古い世代を '.count($removed).' 件片付けました');
                }
            } catch (Throwable $e) {
                try {
                    $this->pruneLocal($catalog);
                } catch (Throwable) {
                    // 知らせるのは外の整理のこと。手元の整理は次の回にやり直す
                }

                return $this->recordFailure('prune', '外の古い世代を片付けられませんでした: '.$e->getMessage(), $manifest->id, $offsite, $status, $alert);
            }
        }

        try {
            $this->pruneLocal($catalog);
        } catch (Throwable $e) {
            return $this->recordFailure('backup', '手元の古い世代を片付けられませんでした: '.$e->getMessage(), $manifest->id, $offsite, $status, $alert);
        }

        if (! $status->succeeded($manifest->id, $offsite->configured())) {
            // 取れたが、結果を残せない。残せないと次の失敗も見えないので、ここで失敗として知らせる
            return $this->recordFailure('backup', '自動バックアップの結果を記録できませんでした（storage の書き込み権限を確かめてください）', $manifest->id, $offsite, $status, $alert);
        }

        return self::SUCCESS;
    }

    private function pruneLocal(BackupCatalog $catalog): void
    {
        $removed = $catalog->prune();

        if ($removed !== []) {
            $this->line('手元の古い世代を '.count($removed).' 件片付けました');
        }
    }

    /** @param  'backup'|'offsite'|'prune'  $stage */
    private function recordFailure(
        string $stage,
        string $reason,
        ?string $backupId,
        OffsiteBackups $offsite,
        AutoBackupStatus $status,
        AutoBackupAlert $alert,
    ): int {
        $reason = $offsite->redact($reason);

        $this->error($reason.($backupId === null ? '' : '（手元の backup は残しています）'));

        // schedule:run は出力を捨て、exit 0 で終わる。ログ・記録・知らせの 3 つに残す
        Log::error('agentcms:backup-auto: '.$reason, ['backup' => $backupId]);
        $status->failed($stage, $reason, $backupId);

        // 記録が書けなかったときも、知らせには今回の理由を載せる
        $view = $status->read();

        if ($alert->send($view['state'] === 'failed' && $view['reason'] === mb_substr($reason, 0, 500) ? $view['message'] : $reason, $backupId)) {
            $this->line('知らせのメールを送りました（AGENTCMS_BACKUP_ALERT_MAIL）');
        }

        return self::FAILURE;
    }
}
