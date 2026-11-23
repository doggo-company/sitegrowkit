<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 画面から始めた取得を、別プロセスの中で最後まで進める（2026-09-09・OG3 改訂）。
 *
 * ## 成功の条件は「作れた」ではない
 *
 * `BackupBuilder` が返っても、それだけでは `done` にしない。
 *
 *   1. zip がその場所に在る
 *   2. zip の `manifest.json` が読める（`BackupCatalog::read`）
 *   3. `BackupRestorer::verify` が「戻せる」と言う
 *
 * 3 つとも通って初めて `done`。**見た目だけ成功の backup を一覧に載せない。**
 *
 * ## 進みは実測だけ
 *
 * `BackupBuilder` から段階と、数えた数だけを受け取り、そのまま状態ファイルへ書く。
 * 書く頻度は絞る（メディア 1 万件で 1 万回 rename しない）が、段階の切り替わりは必ず書く。
 */
final class BackupRunner implements BackupProgress
{
    /** 同じ段階の中で状態ファイルを書き直す間隔（秒） */
    private const HEARTBEAT_INTERVAL_SECONDS = 2;

    private ?BackupRunState $state = null;

    private float $lastWrite = 0.0;

    public function __construct(
        private readonly BackupBuilder $builder,
        private readonly BackupCatalog $catalog,
        private readonly BackupRestorer $restorer,
        private readonly BackupRunStore $store,
    ) {}

    /**
     * @param  int|null  $pid  この process の pid（別プロセスで走っているとき）。読む側が生存確認に使う
     */
    public function run(string $runId, ?int $pid = null): BackupRunState
    {
        $state = $this->store->find($runId);

        if ($state === null) {
            throw new BackupFailed('その run はありません');
        }

        if (! $state->isActive()) {
            // もう終わっている（二重に走らせない）
            return $state;
        }

        $this->state = $state->withPid($pid ?? $state->pid);

        try {
            $manifest = $this->builder->create('', $this);

            $this->stage(BackupStage::Verify);

            $this->assertUsable($manifest->id);

            $this->catalog->prune();

            return $this->finish($this->snapshot()->done($manifest->id));
        } catch (Throwable $e) {
            Log::error('backup.run.failed', ['run' => $runId, 'error' => $e->getMessage()]);

            return $this->finish($this->snapshot()->failed(self::humanReason($e)));
        }
    }

    public function stage(BackupStage $stage): void
    {
        if ($this->state === null) {
            return;
        }

        $this->state = $this->state->running($stage);
        $this->write(force: true);
    }

    public function advance(BackupStage $stage, int $done, ?int $total = null): void
    {
        if ($this->state === null) {
            return;
        }

        $this->state = $this->state->running($stage, $done, $total);
        $this->write(force: false);
    }

    /** 作れたように見える backup が本当に使えるかを、読む側と同じ道で確かめる */
    private function assertUsable(string $backupId): void
    {
        if (! is_file(BackupPaths::archive($backupId))) {
            throw new BackupFailed('backup のファイルが見つかりません');
        }

        if ($this->catalog->read($backupId) === null) {
            throw new BackupFailed('backup の目録（manifest）を読めません');
        }

        $verdict = $this->restorer->verify($backupId);

        if (! $verdict['ok']) {
            throw new BackupFailed('できあがった backup が戻せる状態ではありません: '.implode(' / ', $verdict['problems']));
        }
    }

    private function snapshot(): BackupRunState
    {
        return $this->state ?? throw new BackupFailed('run が始まっていません');
    }

    private function finish(BackupRunState $state): BackupRunState
    {
        $this->state = $state;
        $this->write(force: true);

        return $this->store->find($state->runId) ?? $state;
    }

    private function write(bool $force): void
    {
        if ($this->state === null) {
            return;
        }

        $now = microtime(true);

        if (! $force && $now - $this->lastWrite < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $stored = $this->store->find($this->state->runId);
        if ($this->state->isActive() && $stored !== null && ! $stored->isActive()) {
            throw new BackupFailed('取得は既に終了扱いです。新しい取得を始めてください');
        }
        $this->store->save($this->state);
        $this->lastWrite = $now;
    }

    /**
     * 画面に出す理由。
     *
     * `BackupFailed` の文はもともと人向けだが、置き場所の絶対パスが混ざるものがある
     * （`書き出し先を開けません: /var/www/...`）。storage の根から先だけ残す。
     * それ以外の例外は中身を出さない（SQL や framework のパスが混ざる）。
     */
    private static function humanReason(Throwable $e): string
    {
        if (! $e instanceof BackupFailed) {
            return '取得の途中でエラーが起きました。サーバーのログ（storage/logs）を確認してください';
        }

        return str_replace(rtrim(storage_path(), '/').'/', 'storage/', $e->getMessage());
    }
}
