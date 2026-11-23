<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupRunStore;

/**
 * 画面から始めた取得の**いま**を答える（2026-09-09・OG3 改訂）。
 *
 * 読むだけだが、**止まっていれば `failed` に落としてから返す**（`BackupRunStore::current`）。
 * 「処理中」を無限に出し続けないための判定は、書く側ではなく読む側に置く —
 * 止まった process は自分では何も書けないからである。
 *
 * 権限は取得と同じ（Owner）。進みの中に出るのは段階と数だけだが、
 * 「誰がいつ取り始めたか」まで含めて取得の一部として扱う。
 */
final class BackupRunStatus implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupRunStore $store,
    ) {}

    public function operationId(): string
    {
        return 'backup.run.status';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    /**
     * @param  string|null  $runId  null ならいちばん新しい run
     */
    public function execute(?string $runId, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if ($runId !== null && ! BackupRunStore::isValidRunId($runId)) {
            return Result::notFound('その取得の記録はありません');
        }

        $state = $this->store->current($runId);

        if ($state === null) {
            return $runId === null
                ? Result::success(['run' => null])
                : Result::notFound('その取得の記録はありません');
        }

        return Result::success(['run' => $state->toArray()]);
    }
}
