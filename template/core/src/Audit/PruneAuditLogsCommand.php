<?php

declare(strict_types=1);

namespace AgentCms\Core\Audit;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * 監査ログの保持期間を守る（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 段階を分ける。**履歴そのものは長く残し、個人情報だけ先に消す**。
 *
 * | 対象 | 既定 | 何をするか |
 * | --- | --- | --- |
 * | IP / User-Agent | 90 日 | **匿名化**（列を null にし `anonymized_at` を立てる） |
 * | 行そのもの | 365 日 | 削除 |
 *
 * どちらも `config('agentcms.audit.retention')` で変えられる。
 * **0 以下を指定すると、その段階は何もしない**（既存ログを不用意に消さない）。
 */
final class PruneAuditLogsCommand extends Command
{
    protected $signature = 'agentcms:audit-prune
        {--dry-run : 実際には変更せず、対象件数だけを表示する}';

    protected $description = '監査ログの個人情報を匿名化し、保持期間を過ぎた行を削除します';

    public function handle(ConnectionInterface $connection): int
    {
        $anonymizeDays = (int) config('agentcms.audit.retention.anonymize_after_days');
        $deleteDays = (int) config('agentcms.audit.retention.delete_after_days');
        $dryRun = (bool) $this->option('dry-run');

        $anonymized = $this->anonymize($connection, $anonymizeDays, $dryRun);
        $deleted = $this->delete($connection, $deleteDays, $dryRun);

        $this->info($dryRun
            ? "対象: 匿名化 {$anonymized} 件 / 削除 {$deleted} 件（--dry-run のため変更していません）"
            : "匿名化 {$anonymized} 件 / 削除 {$deleted} 件");

        return self::SUCCESS;
    }

    private function anonymize(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('audit_logs')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNull('anonymized_at')
            ->where(function ($q): void {
                $q->whereNotNull('ip_address')->orWhereNotNull('user_agent');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'ip_address' => null,
            'user_agent' => null,
            'anonymized_at' => now(),
        ]);
    }

    private function delete(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('audit_logs')->where('created_at', '<', now()->subDays($days));

        return $dryRun ? $query->count() : $query->delete();
    }
}
