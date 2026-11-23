<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

use AgentCms\Core\Spam\SpamBlocks;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * 受信の個人情報を整理する（Phase 9.12 / STAGE 25C・SPEC §G.2・OG11）。
 *
 * | 対象 | 既定 | 何をするか |
 * | --- | --- | --- |
 * | `ip_hash` / `user_agent` | 90 日 | **匿名化**（列を null にする） |
 * | 受信そのもの | **0（何もしない）** | 消さない |
 * | 弾いた件数 | 365 日 | 削除 |
 *
 * ## 受信の既定を「消さない」にしてある
 *
 * 監査ログや 404 の記録と違い、これは**顧客とのやり取りそのもの**である。
 * 消す判断は運用が持つ。設定で日数を入れたときだけ消す。
 *
 * 自動では走らない。整理するのはこの明示実行だけである。
 */
final class PruneFormSubmissionsCommand extends Command
{
    protected $signature = 'agentcms:form-prune
        {--dry-run : 実際には変更せず、対象件数だけを表示する}';

    protected $description = 'フォーム受信の個人情報を匿名化し、弾いた記録を整理します';

    public function handle(ConnectionInterface $connection): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $anonymized = $this->anonymize($connection, $this->days('anonymize_after_days', 90), $dryRun);
        $deleted = $this->deleteSubmissions($connection, $this->days('delete_after_days', 0), $dryRun);
        $blocks = $this->deleteBlocks($connection, $this->days('delete_blocks_after_days', 365), $dryRun);

        $summary = "匿名化 {$anonymized} 件 / 受信の削除 {$deleted} 件 / 弾いた記録の削除 {$blocks} 件";

        $this->info($dryRun ? "対象: {$summary}（--dry-run のため変更していません）" : $summary);

        if ($deleted === 0 && $this->days('delete_after_days', 0) <= 0) {
            $this->line('受信そのものは消していません（既定。消す判断は運用が持ちます）');
        }

        return self::SUCCESS;
    }

    private function days(string $key, int $default): int
    {
        return (int) config("agentcms.forms.retention.{$key}", $default);
    }

    private function anonymize(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('form_submissions')
            ->where('created_at', '<', now()->subDays($days))
            ->where(function ($q): void {
                $q->whereNotNull('ip_hash')->orWhereNotNull('user_agent');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update(['ip_hash' => null, 'user_agent' => null]);
    }

    private function deleteSubmissions(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('form_submissions')
            ->where('created_at', '<', now()->subDays($days));

        return $dryRun ? $query->count() : $query->delete();
    }

    private function deleteBlocks(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table(SpamBlocks::TABLE)
            // 表は書き込み口で共用している。**フォームの分だけ**片付ける
            ->where('kind', 'form')
            ->where('blocked_on', '<', now()->subDays($days)->toDateString());

        return $dryRun ? $query->count() : $query->delete();
    }
}
