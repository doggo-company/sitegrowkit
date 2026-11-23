<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

use AgentCms\Core\Spam\SpamBlocks;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * コメントの個人情報を整理する（Phase 9.13 / STAGE 26D・SPEC §G.2・OG11）。
 *
 * | 対象 | 既定 | 何をするか |
 * | --- | --- | --- |
 * | `ip_hash` / `user_agent` | 90 日 | **匿名化**（列を null にする） |
 * | `spam` のコメント | 90 日 | **削除**（スパムを貯める理由が無い） |
 * | `trash` のコメント | **0（何もしない）** | 消す判断は運用が持つ |
 * | `approved` / `pending` | **0（何もしない）** | 他人の文章を勝手に消さない |
 * | 弾いた件数（`kind = comment`） | 365 日 | 削除 |
 *
 * ## 返信のある親から先に消さない
 *
 * `parent_id` は RESTRICT なので、親を先に消すと落ちる。
 * **返信から先に**消す。順序を DB の都合に合わせる。
 *
 * 自動では走らない。整理するのはこの明示実行だけである。
 */
final class PruneCommentsCommand extends Command
{
    protected $signature = 'agentcms:comment-prune
        {--dry-run : 実際には変更せず、対象件数だけを表示する}';

    protected $description = 'コメントの個人情報を匿名化し、スパムと弾いた記録を整理します';

    public function handle(ConnectionInterface $connection): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $anonymized = $this->anonymize($connection, $this->days('anonymize_after_days', 90), $dryRun);
        $spam = $this->deleteByStatus($connection, CommentStatus::Spam, $this->days('delete_spam_after_days', 90), $dryRun);
        $trash = $this->deleteByStatus($connection, CommentStatus::Trash, $this->days('delete_trash_after_days', 0), $dryRun);
        $blocks = $this->deleteBlocks($connection, 365, $dryRun);

        $summary = "匿名化 {$anonymized} 件 / スパムの削除 {$spam} 件 / ゴミ箱の削除 {$trash} 件 / 弾いた記録の削除 {$blocks} 件";

        $this->info($dryRun ? "対象: {$summary}（--dry-run のため変更していません）" : $summary);

        if ($this->days('delete_trash_after_days', 0) <= 0) {
            $this->line('ゴミ箱のコメントは消していません（既定。消す判断は運用が持ちます）');
        }

        return self::SUCCESS;
    }

    private function days(string $key, int $default): int
    {
        return (int) config("agentcms.comments.retention.{$key}", $default);
    }

    private function anonymize(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $query = $connection->table('content_comments')
            ->where('created_at', '<', now()->subDays($days))
            ->where(function ($q): void {
                $q->whereNotNull('ip_hash')->orWhereNotNull('user_agent');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update(['ip_hash' => null, 'user_agent' => null]);
    }

    private function deleteByStatus(ConnectionInterface $connection, CommentStatus $status, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        $target = fn () => $connection->table('content_comments')
            ->where('status', $status->value)
            ->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return $target()->count();
        }

        /*
         * **2 段を 1 つの取引にする。**
         *
         * 返信を消してから根を消す 2 段になっている。途中で落ちると
         * **返信だけ消えて根が残る**（実測: 直す前の形で MySQL に当てると、
         * 2 件が 1 件になって止まった）。
         * 消す仕事の途中で止まった跡を残さない。
         */
        return $connection->transaction(function () use ($connection, $target): int {
            // **返信から先に消す。** 親を先に消すと `fk_comment_parent` で落ちる
            $replies = $target()->whereNotNull('parent_id')->delete();

            /*
             * **消す表を、消す文の中から読まない。**
             *
             * 「返信が残っている親は消さない」を `WHERE NOT EXISTS (SELECT … FROM
             * content_comments …)` で書いていたが、**MySQL はそれを拒む**
             * （`1093 You can't specify target table for update in FROM clause`）。
             * SQLite は通すので、**本番でだけ落ちる**状態だった。
             *
             * だから**先に id を集めてから消す**。読む文と消す文が分かれるので、
             * どの driver でも同じように動く。
             */
            $orphanIds = $target()
                ->whereNull('parent_id')
                ->whereNotIn(
                    'id',
                    $connection->table('content_comments')
                        ->whereNotNull('parent_id')
                        ->distinct()
                        ->pluck('parent_id')
                        ->all(),
                )
                ->pluck('id')
                ->all();

            $roots = $orphanIds === []
                ? 0
                : $connection->table('content_comments')->whereIn('id', $orphanIds)->delete();

            return $replies + $roots;
        });
    }

    private function deleteBlocks(ConnectionInterface $connection, int $days, bool $dryRun): int
    {
        $query = $connection->table(SpamBlocks::TABLE)
            ->where('kind', 'comment')
            ->where('blocked_on', '<', now()->subDays($days)->toDateString());

        return $dryRun ? $query->count() : $query->delete();
    }
}
