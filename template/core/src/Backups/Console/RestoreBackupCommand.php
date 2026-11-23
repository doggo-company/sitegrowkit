<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups\Console;

use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Backups\OffsiteBackups;
use AgentCms\Core\Backups\RestoreImpact;
use Illuminate\Console\Command;

/**
 * サーバーに入れる人が backup から戻す（1 コマンド）。
 *
 *     php artisan agentcms:backup-restore --from-offsite --latest
 *
 * `--from-offsite` を付けると、外の置き場所から手元へ取ってきて（checksum が一致したときだけ）
 * から戻す。手元の backup が全部消えたサーバー・作り直したサーバーで使う。
 *
 * 戻す処理は管理画面と同じ `BackupRestorer` — 検証（中身の一致・DB の形の一致）を通らなければ
 * 戻さない、戻す直前に復元前 backup を取る、全員のログインを切る、はそのまま。
 * 管理画面の Owner 限定・確認の札は HTTP 側の門で、ここはサーバーに入れる人の入口
 * （`agentcms:backup-recover` と同じ立場）。代わりに、何が変わるかを出して確認を取る。
 * 確認を飛ばすのは `--force` だけ（端末の無い実行では確認できないので必ず止まる）。
 */
final class RestoreBackupCommand extends Command
{
    protected $signature = 'agentcms:backup-restore
        {id? : 戻す backup の id}
        {--latest : いちばん新しい backup を使う（--from-offsite なら外のいちばん新しいもの）}
        {--from-offsite : 外の置き場所から取ってきてから戻す}
        {--force : 確認せずに戻す}';

    protected $description = 'backup から DB とメディアを戻します（--from-offsite で外の置き場所から取ってくる）';

    public function handle(BackupRestorer $restorer, BackupCatalog $catalog, OffsiteBackups $offsite, RestoreImpact $impact): int
    {
        $fromOffsite = (bool) $this->option('from-offsite');

        try {
            $id = $this->resolveId($catalog, $offsite, $fromOffsite);

            if ($fromOffsite) {
                $offsite->pull($id);
                $this->info('外の置き場所から取ってきました（checksum 一致）: '.$id);
            }
        } catch (BackupFailed $e) {
            $this->error($offsite->redact($e->getMessage()));

            return self::FAILURE;
        }

        $check = $restorer->verify($id);

        if (! $check['ok'] || $check['manifest'] === null) {
            $this->error('この backup は復元できません: '.implode(' / ', $check['problems']));

            return self::FAILURE;
        }

        $manifest = $check['manifest'];

        $this->line($impact->readable($manifest->createdAt).' の backup へ戻します（'.$id.'）');

        foreach ($impact->since($manifest->createdAt, array_keys($manifest->tables)) as $line) {
            $this->line('  '.$line);
        }

        $this->line('  いまの内容は、戻す直前に自動で backup を取ってから置き換えます');
        $this->line('  全員のログインが切れます');

        if (! (bool) $this->option('force') && ! $this->confirm('この backup へ戻しますか？', false)) {
            $this->warn('戻していません');

            return self::FAILURE;
        }

        try {
            $result = $restorer->restore($id);
        } catch (BackupFailed $e) {
            $this->error('戻せませんでした: '.$offsite->redact($e->getMessage()));

            return self::FAILURE;
        }

        $this->info('戻しました: '.$id.'（戻す前の状態は '.$result['preRestore'].' に残しています）');

        return self::SUCCESS;
    }

    private function resolveId(BackupCatalog $catalog, OffsiteBackups $offsite, bool $fromOffsite): string
    {
        $id = $this->argument('id');

        if (is_string($id) && $id !== '') {
            if (! BackupPaths::isValidId($id)) {
                throw new BackupFailed('その id は使えません: '.$id);
            }

            return $id;
        }

        if (! (bool) $this->option('latest')) {
            throw new BackupFailed('戻す backup の id か --latest を指定してください');
        }

        if ($fromOffsite) {
            return $offsite->latest() ?? throw new BackupFailed('外の置き場所に backup がありません');
        }

        foreach ($catalog->all() as $manifest) {
            if (! $manifest->isPreRestore()) {
                return $manifest->id;
            }
        }

        throw new BackupFailed('手元に backup がありません');
    }
}
