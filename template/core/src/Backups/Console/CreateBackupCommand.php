<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\DatabaseDump;
use Illuminate\Console\Command;

/**
 * backup を取る（Phase 9.11 / STAGE 24B・SPEC §C.1・OG1）。
 *
 * **HTTP の中からは呼ばない。** 大きなサイトでは web の要求時間に収まらず、
 * 途中で切れると中途半端な物が残る。worker が常駐しない環境もあるので queue にも載せない。
 *
 * 画面からの取得（2026-09-09・OG3 改訂）は、このコマンドではなく `agentcms:backup-run`
 * を**別プロセス**として起こす（`ProcessBackupRunLauncher`）。こちらは人が打つ入口で、
 * 起動できない環境では管理画面が**このコマンドをそのまま表示**する。挙動は変えていない。
 *
 * このコマンド自体は自動では走らない（保持期間の整理と同じ作法）。毎日の自動取得と
 * 外の置き場所への写しは `agentcms:backup-auto`（`AGENTCMS_BACKUP_SCHEDULE` を入れたときだけ載る）。
 */
final class CreateBackupCommand extends Command
{
    protected $signature = 'agentcms:backup
        {--dry-run : 実際には作らず、何がどれだけ入るかだけ表示する}';

    protected $description = 'DB とメディアの backup を取ります（秘密情報は含めません）';

    public function handle(BackupBuilder $builder, BackupCatalog $catalog, DatabaseDump $dump): int
    {
        if ((bool) $this->option('dry-run')) {
            $tables = $dump->tables();

            $this->info('入るもの: 表 '.count($tables).' 個 + メディア');
            $this->line('入れないもの: '.implode(', ', DatabaseDump::EXCLUDED_TABLES));
            $this->line('秘密情報（.env / .agentcms/local/ / *.key）は含めません');
            $this->comment('--dry-run のため何も作っていません');

            return self::SUCCESS;
        }

        try {
            $manifest = $builder->create();
        } catch (BackupFailed $e) {
            // 理由はそのまま出す（コマンドは運用者が見るものなので、path も手がかりになる）
            $this->error('backup を作れませんでした: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'backup を作りました: %s（%s / %d 行 / メディア %d 件）',
            $manifest->id,
            self::humanSize($manifest->sizeBytes),
            $manifest->rowCount(),
            $manifest->media['files'],
        ));

        $removed = $catalog->prune();

        if ($removed !== []) {
            $this->line('古い世代を '.count($removed).' 件片付けました');
        }

        return self::SUCCESS;
    }

    private static function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
