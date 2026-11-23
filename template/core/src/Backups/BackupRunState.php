<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 画面から始めた取得 1 回ぶんの状態（2026-09-09・OG3 改訂）。
 *
 * ## 4 つの結末しか無い
 *
 * `queued`（別プロセスの起動待ち）→ `running` → `done` / `failed`。
 * **`done` は「zip が在って manifest が読めて戻せる」を確かめた後にしか付かない。**
 * 作れたように見えるだけの状態を成功と呼ばない。
 *
 * ## 止まったことを隠さない
 *
 * `heartbeat_at` は段階が進むたびに更新される。読む側（`BackupRunStore::current()`）は
 * これが古ければ「応答が止まった」と判定して `failed` へ落とす。
 * 無限に「処理中」を出し続けない。
 */
final readonly class BackupRunState
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @param  array{done: int, total: int|null}|null  $progress  段階の中で実測した進み。数えていなければ null
     */
    public function __construct(
        public string $runId,
        public string $status,
        public string $stage,
        public ?array $progress,
        public string $startedAt,
        public string $heartbeatAt,
        public ?string $finishedAt,
        public ?string $backupId,
        public ?string $error,
        public ?int $pid,
        public ?int $requestedBy,
    ) {}

    public static function queued(string $runId, ?int $requestedBy): self
    {
        $now = now()->toIso8601String();

        return new self(
            runId: $runId,
            status: self::QUEUED,
            stage: BackupStage::Queued->value,
            progress: null,
            startedAt: $now,
            heartbeatAt: $now,
            finishedAt: null,
            backupId: null,
            error: null,
            pid: null,
            requestedBy: $requestedBy,
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::RUNNING;
    }

    public function withPid(?int $pid): self
    {
        return $this->copy(pid: $pid);
    }

    public function running(BackupStage $stage, ?int $done = null, ?int $total = null): self
    {
        return $this->copy(
            status: self::RUNNING,
            stage: $stage->value,
            progress: $done === null ? null : ['done' => $done, 'total' => $total],
            heartbeatAt: now()->toIso8601String(),
        );
    }

    public function done(string $backupId): self
    {
        $now = now()->toIso8601String();

        return $this->copy(
            status: self::DONE,
            progress: null,
            heartbeatAt: $now,
            finishedAt: $now,
            backupId: $backupId,
        );
    }

    public function failed(string $error): self
    {
        $now = now()->toIso8601String();

        return $this->copy(
            status: self::FAILED,
            progress: null,
            heartbeatAt: $now,
            finishedAt: $now,
            error: $error,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'stage' => $this->stage,
            'stage_label' => BackupStage::tryFrom($this->stage)?->label() ?? $this->stage,
            'progress' => $this->progress,
            'started_at' => $this->startedAt,
            'heartbeat_at' => $this->heartbeatAt,
            'finished_at' => $this->finishedAt,
            'backup_id' => $this->backupId,
            'error' => $this->error,
            'pid' => $this->pid,
            'requested_by' => $this->requestedBy,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        $progress = is_array($raw['progress'] ?? null) ? $raw['progress'] : null;

        return new self(
            runId: (string) ($raw['run_id'] ?? ''),
            status: (string) ($raw['status'] ?? self::FAILED),
            stage: (string) ($raw['stage'] ?? ''),
            progress: $progress === null ? null : [
                'done' => (int) ($progress['done'] ?? 0),
                'total' => isset($progress['total']) ? (int) $progress['total'] : null,
            ],
            startedAt: (string) ($raw['started_at'] ?? ''),
            heartbeatAt: (string) ($raw['heartbeat_at'] ?? ''),
            finishedAt: isset($raw['finished_at']) ? (string) $raw['finished_at'] : null,
            backupId: isset($raw['backup_id']) ? (string) $raw['backup_id'] : null,
            error: isset($raw['error']) ? (string) $raw['error'] : null,
            pid: isset($raw['pid']) ? (int) $raw['pid'] : null,
            requestedBy: isset($raw['requested_by']) ? (int) $raw['requested_by'] : null,
        );
    }

    /**
     * 値を差し替えた複製。**`progress` は段階に付く**ので、段階が動くときは呼び出し側が明示する。
     *
     * @param  array{done: int, total: int|null}|null  $progress
     */
    private function copy(
        ?string $status = null,
        ?string $stage = null,
        ?array $progress = null,
        ?string $heartbeatAt = null,
        ?string $finishedAt = null,
        ?string $backupId = null,
        ?string $error = null,
        ?int $pid = null,
    ): self {
        $stageMoves = $status !== null || $stage !== null;

        return new self(
            runId: $this->runId,
            status: $status ?? $this->status,
            stage: $stage ?? $this->stage,
            progress: $stageMoves ? $progress : $this->progress,
            startedAt: $this->startedAt,
            heartbeatAt: $heartbeatAt ?? $this->heartbeatAt,
            finishedAt: $finishedAt ?? $this->finishedAt,
            backupId: $backupId ?? $this->backupId,
            error: $error ?? $this->error,
            pid: $pid ?? $this->pid,
            requestedBy: $this->requestedBy,
        );
    }
}
