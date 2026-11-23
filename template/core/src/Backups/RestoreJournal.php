<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 復元の**耐久性のある**記録（2026-09-09・release R09）。
 *
 * ## なぜ memory の journal では足りないか
 *
 * `BackupRestorer` は動かした rename を配列に覚え、失敗したら逆順に戻していた。
 * それは例外で戻れる形にしか効かない。rename のあと・DB commit の前に
 * プロセスが SIGKILL で消えると、DB は戻る（transaction が閉じられない）のに
 * **メディアだけ入れ替わったまま**になり、それを知る記録がどこにも無い。
 *
 * だから rename を**始める前に**ファイルへ書く。1 手ごとに追記し、書き切ってから
 * 名前を差し替える（読み手に途中の内容を見せない）。
 *
 * ## 置き場所は復元の対象外
 *
 * `backups/.restore/journal.json`。zip と同じ private の根の下で、復元が入れ替える
 * `media/*` の root にも、DB にも含まれない。復元が自分の記録を消すことはない。
 *
 * ## 「在る」= 中断している
 *
 * 正常に終われば `history/` へ移す。journal が残っているあいだは
 *
 *   - HTTP は 503 で止まる（`RestorePendingMiddleware`）
 *   - 取得も復元も始められない（`BackupBuilder` / `BackupRestorer`）
 *   - `php artisan agentcms:backup-recover` が DB の commit 印を見て、
 *     **commit 前なら戻す / commit 後なら仕上げる**
 *
 * どちらだったかは journal ではなく DB の印（`backup_restores`）で決める。
 * journal の「committing」は意図であって事実ではない。
 */
final class RestoreJournal
{
    public const VERSION = 1;

    public const PLANNED = 'planned';

    public const SWAPPING = 'swapping';

    public const COMMITTING = 'committing';

    public const COMMITTED = 'committed';

    /** 全 root を復元前の置き方へ戻し終えた（作業ディレクトリを消す**前に**書く） */
    public const ROLLED_BACK = 'rolled_back';

    public const RECOVER_COMMAND = 'php artisan agentcms:backup-recover';

    public const PENDING_MESSAGE = '前回の復元が途中で止まったままです。サーバーで `'.self::RECOVER_COMMAND.'` を実行して状態を整えてから、もう一度お試しください';

    /** @param  array<string, mixed>  $data */
    private function __construct(private array $data) {}

    public static function directory(): string
    {
        return BackupPaths::root().'/.restore';
    }

    public static function path(): string
    {
        return self::directory().'/journal.json';
    }

    public static function historyDirectory(): string
    {
        return self::directory().'/history';
    }

    /** 中断した復元が残っているか。**stat 1 回**（毎リクエストで呼ばれる） */
    public static function isPending(): bool
    {
        return is_file(self::path());
    }

    public static function assertNone(): void
    {
        if (self::isPending()) {
            throw new BackupFailed(self::PENDING_MESSAGE);
        }
    }

    /** 残っている記録。無ければ null、壊れていれば例外（黙って「無い」にしない） */
    public static function pending(): ?self
    {
        if (! self::isPending()) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents(self::path()), true);

        if (! is_array($decoded) || ! isset($decoded['restore_id'], $decoded['moves'], $decoded['steps'])) {
            throw new BackupFailed('復元の記録（journal）が読めません。手で確認が必要です: '.self::path());
        }

        return new self($decoded);
    }

    /**
     * 記録を始める。**まだ何も動かしていない時点で呼ぶ。**
     *
     * @param  list<array{root: string, source: string, target: string, parked: string, target_existed: bool}>  $moves
     */
    public static function begin(
        string $restoreId,
        string $backupId,
        string $preRestoreId,
        string $workspace,
        array $moves,
    ): self {
        self::assertNone();

        if (! is_dir(self::directory()) && ! @mkdir(self::directory(), 0700, true) && ! is_dir(self::directory())) {
            throw new BackupFailed('復元の記録を置くディレクトリを作れません');
        }

        $journal = new self([
            'version' => self::VERSION,
            'restore_id' => $restoreId,
            'backup_id' => $backupId,
            'pre_restore_id' => $preRestoreId,
            'workspace' => $workspace,
            'started_at' => now()->toIso8601String(),
            'phase' => self::PLANNED,
            'moves' => $moves,
            'steps' => [],
        ]);

        $journal->persist();

        return $journal;
    }

    public function restoreId(): string
    {
        return (string) $this->data['restore_id'];
    }

    public function backupId(): string
    {
        return (string) ($this->data['backup_id'] ?? '');
    }

    public function preRestoreId(): string
    {
        return (string) ($this->data['pre_restore_id'] ?? '');
    }

    public function workspace(): string
    {
        return (string) ($this->data['workspace'] ?? '');
    }

    public function phase(): string
    {
        return (string) ($this->data['phase'] ?? '');
    }

    public function startedAt(): string
    {
        return (string) ($this->data['started_at'] ?? '');
    }

    /** @return list<array{root: string, source: string, target: string, parked: string, target_existed: bool}> */
    public function moves(): array
    {
        $moves = [];

        foreach ((array) $this->data['moves'] as $move) {
            if (! is_array($move)) {
                continue;
            }

            $moves[] = [
                'root' => (string) ($move['root'] ?? ''),
                'source' => (string) ($move['source'] ?? ''),
                'target' => (string) ($move['target'] ?? ''),
                'parked' => (string) ($move['parked'] ?? ''),
                'target_existed' => (bool) ($move['target_existed'] ?? false),
            ];
        }

        return $moves;
    }

    /** @return list<array{from: string, to: string}> 記録した順 */
    public function steps(): array
    {
        /** @var list<array{from: string, to: string}> $steps */
        $steps = (array) $this->data['steps'];

        return $steps;
    }

    public function advance(string $phase): void
    {
        $this->data['phase'] = $phase;
        $this->persist();
    }

    /** rename の**前に**呼ぶ。意図を先に残す（動かしたかどうかは実物で判定する） */
    public function step(string $from, string $to): void
    {
        $this->data['steps'][] = ['from' => $from, 'to' => $to];
        $this->persist();
    }

    /**
     * 終わった記録を `history/` へ移す。**消さない。**
     *
     * 何が起きたか（戻したのか・仕上げたのか）を後から追えるようにする。
     */
    public function archive(string $outcome): void
    {
        $this->data['outcome'] = $outcome;
        $this->data['finished_at'] = now()->toIso8601String();

        if (! is_dir(self::historyDirectory())) {
            @mkdir(self::historyDirectory(), 0700, true);
        }

        $history = self::historyDirectory().'/'.$this->restoreId().'.json';

        self::write($history, $this->toJson());

        if (! @unlink(self::path()) && is_file(self::path())) {
            throw new BackupFailed('復元の記録を片付けられません: '.self::path());
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private function toJson(): string
    {
        return (string) json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function persist(): void
    {
        self::write(self::path(), $this->toJson());
    }

    /** 書き切って fsync してから名前を差し替える（途中の内容を読ませず、書き込みをOSへ同期する） */
    private static function write(string $path, string $contents): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(3)).'.tmp';
        $handle = @fopen($tmp, 'wb');

        if ($handle === false) {
            throw new BackupFailed('復元の記録を書けません');
        }

        // ディスク不足等による部分書き込みも失敗として扱い、直前の記録を維持する。
        $ok = fwrite($handle, $contents) === strlen($contents) && fflush($handle) && fsync($handle);
        fclose($handle);
        @chmod($tmp, 0600);

        if (! $ok || ! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new BackupFailed('復元の記録を保存できません');
        }
    }
}
