<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * 404 の記録を整理する（Phase 9.10 / STAGE 23C・SPEC §F.4）。
 *
 * 監査ログと同じ段階の分け方をする。**記録そのものは長く残し、個人情報だけ先に消す**。
 *
 * | 対象 | 既定 | 何をするか |
 * | --- | --- | --- |
 * | referrer / user-agent / ip_hash | 30 日 | **匿名化**（列を null にする） |
 * | 直した記録（`resolved_at` あり） | 90 日 | 削除 |
 * | 直していない記録 | 365 日 | 削除 |
 *
 * `config('agentcms.not_found.retention')` で変えられる。
 * **`0` 以下を指定すると、その段階は何もしない**（既存の記録を不用意に消さない）。
 *
 * 自動では走らない。整理するのはこの明示実行だけである。
 */
final class PruneNotFoundLogsCommand extends Command
{
    protected $signature = 'agentcms:redirect-prune
        {--dry-run : 実際には変更せず、対象件数だけを表示する}';

    protected $description = '404 の記録の個人情報を匿名化し、保持期間を過ぎた行を削除します';

    public function handle(ConnectionInterface $connection): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $anonymized = $this->anonymize($connection, (int) config('agentcms.not_found.retention.anonymize_after_days'), $dryRun);
        $resolved = $this->delete(
            $connection,
            (int) config('agentcms.not_found.retention.delete_resolved_after_days'),
            $dryRun,
            resolvedOnly: true,
        );
        $deleted = $this->delete(
            $connection,
            (int) config('agentcms.not_found.retention.delete_after_days'),
            $dryRun,
            resolvedOnly: false,
        );

        $this->info($dryRun
            ? "対象: 匿名化 {$anonymized} 件 / 削除 ".($resolved + $deleted).' 件（--dry-run のため変更していません）'
            : "匿名化 {$anonymized} 件 / 削除 ".($resolved + $deleted).' 件');

        return self::SUCCESS;
    }

    private function anonymize(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('not_found_logs')
            ->where('last_seen_at', '<', now()->subDays($days))
            ->where(function ($q): void {
                $q->whereNotNull('last_ip_hash')
                    ->orWhereNotNull('last_referrer')
                    ->orWhereNotNull('last_user_agent');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'last_ip_hash' => null,
            'last_referrer' => null,
            'last_user_agent' => null,
        ]);
    }

    private function delete(ConnectionInterface $connection, int $days, bool $dryRun, bool $resolvedOnly): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('not_found_logs')
            ->where('last_seen_at', '<', now()->subDays($days))
            ->when(
                $resolvedOnly,
                static fn ($q) => $q->whereNotNull('resolved_at'),
                static fn ($q) => $q->whereNull('resolved_at'),
            );

        return $dryRun ? $query->count() : $query->delete();
    }
}
