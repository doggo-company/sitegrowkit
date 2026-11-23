<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 取得と復元を**プロセスをまたいで**同時に 1 つにする（2026-09-09・release R09）。
 *
 * ## なぜ `active.lock`（BackupRunStore）だけでは足りないか
 *
 * あれは「画面から始めた取得」の連打を止める印で、CLI の `agentcms:backup` と
 * 画面の復元は通らない。取得の最中に復元が走れば、DB dump の途中でメディアが
 * 入れ替わる backup ができる。逆も同じである。
 *
 * ## flock を使う。TTL で奪わない
 *
 * `flock()` は持ち主のプロセスが（SIGKILL でも）消えれば OS が外す。
 * だから「古そうだから奪う」という判断を持たない — 奪った先で 2 つが同時に走る
 * ほうが、待たされるより悪い。取れなければ**すぐ**に人が読める理由で止まる。
 *
 * ## 同じプロセスの中では再入できる
 *
 * 復元は最初に復元前 backup を取る（`BackupRestorer` → `BackupBuilder::create`）。
 * 同じ lock を 2 度取ろうとして自分で自分を待たないよう、深さを数える。
 * **container の singleton として 1 つだけ**存在することが前提である。
 */
final class BackupLock
{
    /** 取れないときに諦めるまで（秒）。待ち続けて web の要求を塞がない */
    private const WAIT_SECONDS = 2.0;

    public const CONTENTION_MESSAGE = '別の backup の取得または復元が進行中です。終わってからもう一度お試しください';

    private int $depth = 0;

    /** @var resource|null */
    private $handle = null;

    public static function path(): string
    {
        return BackupPaths::root().'/.exclusive.lock';
    }

    /**
     * lock の中で実行する。
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public function exclusive(string $purpose, callable $action): mixed
    {
        $this->acquire($purpose);

        try {
            return $action();
        } finally {
            $this->release();
        }
    }

    public function acquire(string $purpose): void
    {
        if ($this->depth > 0) {
            $this->depth++;

            return;
        }

        BackupPaths::ensureRoot();

        // 消さない（inode を差し替えると、別プロセスが別のファイルを lock する）
        $handle = @fopen(self::path(), 'c+');

        if ($handle === false) {
            throw new BackupFailed('backup の lock を開けません');
        }

        @chmod(self::path(), 0600);

        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new BackupFailed(self::CONTENTION_MESSAGE);
            }

            usleep(20000);
        }

        // 中身は診断用。判定には使わない（判定は flock だけ）
        @ftruncate($handle, 0);
        @fwrite($handle, $purpose.' pid='.(getmypid() ?: 0).' at='.now()->toIso8601String()."\n");
        @fflush($handle);

        $this->handle = $handle;
        $this->depth = 1;
    }

    public function release(): void
    {
        if ($this->depth === 0) {
            return;
        }

        $this->depth--;

        if ($this->depth > 0 || $this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function isHeld(): bool
    {
        return $this->depth > 0;
    }
}
