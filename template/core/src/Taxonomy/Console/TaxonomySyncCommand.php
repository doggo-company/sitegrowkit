<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Console;

use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionException;
use Illuminate\Console\Command;
use Throwable;

/**
 * code-managed taxonomy をデータベースへ同期する（Phase 9.3B / STAGE 1・SPEC §J.4）。
 *
 * **明示的な setup / update / dev ステップからのみ実行する**（SY-2 / SY-3）。
 * ServiceProvider の boot・middleware・controller・通常の Web request からは呼ばない。
 * migration からも呼ばない（SY-1）。
 *
 * 終了コード:
 * - 0 … 同期済み（created / updated / unchanged のみ）
 * - 1 … 定義ファイル不正 / CONFLICT / データベース失敗
 */
final class TaxonomySyncCommand extends Command
{
    protected $signature = 'agentcms:taxonomy-sync {--json : 結果を JSON で出力する}';

    protected $description = 'content/taxonomies/*.json の分類定義をデータベースへ同期する（非破壊・冪等）';

    public function handle(TaxonomySynchronizer $synchronizer): int
    {
        try {
            $report = $synchronizer->sync();
        } catch (TaxonomyDefinitionException $e) {
            $this->components->error('分類の定義ファイルに問題があります。同期は行いませんでした。');

            foreach ($e->problems as $problem) {
                $this->line('  • '.$problem);
            }

            $this->line('');
            $this->line('  content/taxonomies/ の JSON を直してから、もう一度実行してください。');

            return self::FAILURE;
        } catch (Throwable $e) {
            // 例外本文には SQL や接続情報が含まれ得るため画面へ出さない。
            // 詳細はアプリケーションログへ送る（秘密を端末に出さないため）
            report($e);

            $this->components->error('データベースへの同期に失敗しました。分類は変更していません。');
            $this->line('  データベースが起動しているかを確認し、もう一度実行してください。');
            $this->line('  詳しい原因は storage/logs のログに記録しました。');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report->hasConflicts() ? self::FAILURE : self::SUCCESS;
        }

        if ($report->hasConflicts()) {
            $this->components->error(
                '分類の定義とデータベースの内容が食い違っています。安全のため **何も変更していません**。'
            );

            foreach ($report->conflicts as $conflict) {
                $this->line("  • [{$conflict['key']}] {$conflict['reason']}");
            }

            $this->line('');
            $this->line('  上の内容を解消してから、もう一度実行してください。');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '分類を同期しました（新規 %d / 更新 %d / 変更なし %d）',
            count($report->created),
            count($report->updated),
            count($report->unchanged),
        ));

        foreach ($report->created as $key) {
            $this->line("  + {$key}（新規作成）");
        }

        foreach ($report->updated as $key) {
            $this->line("  ~ {$key}（更新）");
        }

        foreach ($report->unchanged as $key) {
            $this->line("  = {$key}（変更なし）");
        }

        return self::SUCCESS;
    }
}
