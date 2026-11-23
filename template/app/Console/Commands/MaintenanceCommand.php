<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Maintenance\MaintenanceProbe;
use App\Support\Maintenance\MaintenanceReport;
use Illuminate\Console\Command;
use Throwable;

/** 定期保守は読むだけ。更新・公開・削除を自動実行しない。 */
final class MaintenanceCommand extends Command
{
    protected $signature = 'agentcms:maintenance';

    protected $description = 'PHP依存関係とHTTPS証明書を確認し、管理画面に結果を残す';

    public function handle(MaintenanceProbe $probe, MaintenanceReport $report): int
    {
        // 多重実行は古いプロセスの結果で上書きしないよう、確認開始前から排他する。
        $dir = dirname($report->path());
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('保守結果の保存先を作れません。');

            return self::FAILURE;
        }
        $path = $dir.'/run.lock';
        if (is_link($path)) {
            $this->error('保守ロックの保存先を確認してください。');

            return self::FAILURE;
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            $this->error('保守ロックを作れません。');

            return self::FAILURE;
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->error('別の保守確認が実行中です。');

                return self::FAILURE;
            }
            $fingerprint = $report->fingerprint();
            $checks = [$probe->dependencies(), $probe->certificate()];
            $report->save($checks, $fingerprint);
            foreach ($checks as $check) {
                $this->line($check['status'].' '.$check['label'].'：'.$check['message']);
            }

            return collect($checks)->every(fn (array $row): bool => $row['status'] === 'pass') ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('保守確認を完了できませんでした。保存先と実行環境を確認してください。');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
