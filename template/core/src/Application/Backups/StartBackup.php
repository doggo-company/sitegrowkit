<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRunLauncher;
use AgentCms\Core\Backups\BackupRunState;
use AgentCms\Core\Backups\BackupRunStore;

/**
 * 画面から取得を**始める**（2026-09-09・OG3 改訂）。
 *
 * ## HTTP の中では「始める」ことしかしない
 *
 * 取得そのものは別プロセス（`agentcms:backup-run`）が進める。ここでやるのは
 *
 *   1. 権限（Owner だけ — `backup.create` → `BackupRestore`）
 *   2. 同時に 1 つ（`BackupRunStore::acquire`）。連打は 2 回目から「いま走っている run」を返す
 *   3. 状態を `queued` で保存
 *   4. 別プロセスを起動。**起動できなければその場で `failed` にして理由を返す**
 *
 * 起動できない環境（`proc_open` が塞がれている等）は 4 の前で分かるので、
 * 状態を作らず理由だけ返す。画面は CLI の手順を出す。
 *
 * ## AI へは出さない
 *
 * `OperationCatalog` に載せない。取得は Admin（画面）と CLI だけ。
 */
final class StartBackup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupRunStore $store,
        private readonly BackupRunLauncher $launcher,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'backup.create';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $reason = $this->launcher->unavailableReason();

        if ($reason !== null) {
            return Result::conflict($reason, ['unavailable' => true]);
        }

        $runId = BackupPaths::newId();
        $blocking = $this->store->acquire($runId);

        if ($blocking !== null) {
            return Result::conflict(
                'いま別の取得が進んでいます。終わるまでお待ちください',
                ['run' => $blocking->toArray()],
            );
        }

        $state = BackupRunState::queued($runId, $context->actorId);
        $this->store->save($state);

        try {
            $pid = $this->launcher->launch($runId);
        } catch (BackupFailed $e) {
            // 起動できなかったことを**記録として**残す（lock は save が外す）
            $this->store->save($state->failed($e->getMessage()));

            return $this->outcome->record(
                $this,
                $context,
                Result::conflict($e->getMessage(), ['run' => $this->store->find($runId)?->toArray()]),
                resourceType: 'backup_run',
                resourceId: $runId,
            );
        }

        /*
         * pid は子も自分で書く（`BackupRunner::run`）。ここで書くのは、**子が何も書けずに
         * 死んだとき**に読む側が「process が居ない」とすぐ判定できるようにするためである。
         * 子が先に `running` を書いていたら、その記録を戻さない。
         */
        if ($pid !== null) {
            $this->store->recordPidIfQueued($runId, $pid);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['run' => $this->store->find($runId)?->toArray()], ['run_id' => $runId]),
            resourceType: 'backup_run',
            resourceId: $runId,
        );
    }
}
