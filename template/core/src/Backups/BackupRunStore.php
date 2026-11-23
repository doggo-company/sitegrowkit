<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 取得の状態を**ファイルに**残す（2026-09-09・OG3 改訂）。
 *
 * ## なぜ DB ではないか
 *
 * 復元は DB を丸ごと入れ替える。「復元の直前に取った backup の記録」が
 * 復元で消えると、何が起きたのか追えなくなる。zip と同じ private の根の下に置く。
 *
 * ## 止まったものを「処理中」のまま置かない
 *
 * `current()` は読むたびに鮮度を確かめる。
 *
 *   - `queued` のまま `start_timeout` 秒を過ぎた → 別プロセスが立ち上がらなかった
 *   - `running` のまま `stale_after` 秒 heartbeat が無い → 途中で止まった
 *   - pid が分かっていて、その process がもう居ない → 途中で落ちた
 *
 * どれも `failed` へ落として理由を書く。**無限の「処理中」を作らない。**
 *
 * ## 同時に 1 つ
 *
 * `active.lock` を `fopen('x')` で作れた側だけが始められる。連打は 2 回目から止まる。
 * 落ちたまま残った lock は、上の鮮度判定で `failed` に落ちた時点で外す。
 */
class BackupRunStore
{
    /** 状態ファイルを残す数（古いものから消す） */
    private const KEEP_RUNS = 20;

    private bool $locked = false;

    /** 状態の読取→更新を一体化する。削除しない mutex で inode の入れ替わりを防ぐ。
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function synchronized(callable $action): mixed
    {
        if ($this->locked) {
            return $action();
        }
        BackupPaths::ensureRuns();
        $handle = @fopen(BackupPaths::runs().'/.state-mutex', 'c');
        if ($handle === false) {
            throw new BackupFailed('取得の状態を保護するロックを開けません');
        }
        @chmod(BackupPaths::runs().'/.state-mutex', 0600);
        $deadline = microtime(true) + 2;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new BackupFailed('取得の状態を更新中です。少し待ってもう一度お試しください');
            }
            usleep(10000);
        }
        $this->locked = true;
        try {
            return $action();
        } finally {
            $this->locked = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 起動元が PID を書く間に子が進んでも、処理中や完了を起動待ちへ戻さない。 */
    public function recordPidIfQueued(string $runId, int $pid): void
    {
        $this->synchronized(function () use ($runId, $pid): void {
            $state = $this->find($runId);
            if ($state?->status === BackupRunState::QUEUED && $state->pid === null) {
                $this->save($state->withPid($pid));
            }
        });
    }

    public function save(BackupRunState $state): void
    {
        $this->synchronized(function () use ($state): void {
            $this->saveLocked($state);
        });
    }

    private function saveLocked(BackupRunState $state): void
    {
        $previous = $this->find($state->runId);
        // 遅れて届いた heartbeat や PID 記録で、終了済みの処理を復活させない。
        if ($previous !== null && ! $previous->isActive()) {
            return;
        }
        if ($previous?->status === BackupRunState::RUNNING && $state->status === BackupRunState::QUEUED) {
            return;
        }
        BackupPaths::ensureRuns();

        $path = BackupPaths::run($state->runId);
        $tmp = $path.'.tmp';

        // 途中まで書いたファイルを読ませない（書き切ってから名前を差し替える）
        if (file_put_contents($tmp, $state->toJson(), LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new BackupFailed('取得の状態を保存できません');
        }

        @chmod($path, 0600);

        if (! $state->isActive()) {
            $this->release($state->runId);
            $this->prune();
        }
    }

    public function find(string $runId): ?BackupRunState
    {
        if (! self::isValidRunId($runId)) {
            return null;
        }

        $path = BackupPaths::run($runId);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? BackupRunState::fromArray($decoded) : null;
    }

    /** いちばん新しい run（無ければ null）。鮮度判定は通さない生の記録 */
    public function latest(): ?BackupRunState
    {
        $ids = $this->runIds();

        return $ids === [] ? null : $this->find($ids[0]);
    }

    /**
     * 画面に出す「いま」の状態。**止まっていれば failed に落としてから返す。**
     */
    public function current(?string $runId = null): ?BackupRunState
    {
        return $this->synchronized(fn (): ?BackupRunState => $this->currentLocked($runId));
    }

    private function currentLocked(?string $runId): ?BackupRunState
    {
        $state = $runId === null ? $this->latest() : $this->find($runId);

        if ($state === null || ! $state->isActive()) {
            return $state;
        }

        $reason = $this->stallReason($state);

        if ($reason === null) {
            return $state;
        }

        $failed = $state->failed($reason);
        $this->save($failed);

        return $failed;
    }

    /**
     * 始める権利を取る。
     *
     * @return BackupRunState|null 取れなければ、いま走っている run（画面はそれを出す）
     */
    public function acquire(string $runId): ?BackupRunState
    {
        return $this->synchronized(fn (): ?BackupRunState => $this->acquireLocked($runId));
    }

    private function acquireLocked(string $runId): ?BackupRunState
    {
        BackupPaths::ensureRuns();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen(BackupPaths::runLock(), 'x');

            if ($handle !== false) {
                fwrite($handle, $runId);
                fclose($handle);
                // lock だけ存在し状態がまだ無い隙間を、別の POST に見せない。
                $this->save(BackupRunState::queued($runId, null));

                return null;
            }

            $holder = trim((string) @file_get_contents(BackupPaths::runLock()));
            $active = $holder === '' ? null : $this->current($holder);

            if ($active !== null && $active->isActive()) {
                return $active;
            }

            // 持ち主が終わっている（か、記録が無い）のに lock だけ残っている → 外してもう 1 度
            @unlink(BackupPaths::runLock());
        }

        return BackupRunState::queued($runId, null)->failed('ほかの取得が終わるのを待っています');
    }

    /** その run が持っている lock だけを外す（他人の lock を外さない） */
    public function release(string $runId): void
    {
        $this->synchronized(function () use ($runId): void {
            $this->releaseLocked($runId);
        });
    }

    private function releaseLocked(string $runId): void
    {
        $path = BackupPaths::runLock();

        if (is_file($path) && trim((string) @file_get_contents($path)) === $runId) {
            @unlink($path);
        }
    }

    public static function isValidRunId(string $runId): bool
    {
        return BackupPaths::isValidId($runId);
    }

    /**
     * 止まっている理由（止まっていなければ null）。
     *
     * 判定の閾値は config（`agentcms.backup.run_start_timeout` / `run_stale_after`）。
     */
    private function stallReason(BackupRunState $state): ?string
    {
        $alive = self::processAlive($state->pid);

        if ($alive === false) {
            return '取得の処理が途中で止まりました（プロセスが終了しています）。サーバーのログを確認するか、もう一度お試しください';
        }

        $heartbeat = strtotime($state->heartbeatAt);

        if ($heartbeat === false) {
            return '取得の記録が読めません';
        }

        $age = now()->getTimestamp() - $heartbeat;

        if ($state->status === BackupRunState::QUEUED) {
            $limit = (int) config('agentcms.backup.run_start_timeout', 120);

            return $age > $limit
                ? sprintf('%d 秒待ちましたが取得の処理が始まりませんでした。サーバーで別プロセスを起動できない可能性があります', $limit)
                : null;
        }

        $limit = (int) config('agentcms.backup.run_stale_after', 600);

        return $age > $limit
            ? sprintf('%d 分以上、取得の処理から応答がありません。途中で止まった可能性があります', intdiv($limit, 60))
            : null;
    }

    /**
     * pid の process が居るか。分からなければ null（無いと決めつけない）。
     *
     * `posix_kill($pid, 0)` は送らずに存在だけ確かめる。EPERM（他人の process）は
     * 「居る」と読む。
     */
    private static function processAlive(?int $pid): ?bool
    {
        if ($pid === null || $pid <= 0 || ! function_exists('posix_kill')) {
            return null;
        }

        if (@posix_kill($pid, 0)) {
            return true;
        }

        return function_exists('posix_get_last_error') && posix_get_last_error() === 1 /* EPERM */ ? true : false;
    }

    /** @return list<string> 新しい順 */
    private function runIds(): array
    {
        $files = glob(BackupPaths::runs().'/*.json') ?: [];
        $ids = array_map(static fn (string $f): string => basename($f, '.json'), $files);

        rsort($ids, SORT_STRING);

        return array_values(array_filter($ids, self::isValidRunId(...)));
    }

    private function prune(): void
    {
        foreach (array_slice($this->runIds(), self::KEEP_RUNS) as $id) {
            @unlink(BackupPaths::run($id));
        }
    }
}
