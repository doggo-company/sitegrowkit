<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRestorer;

/**
 * 戻せるかどうかだけを答える（Phase 9.11 / STAGE 24C・SPEC §D.1）。
 *
 * **何も変えない。** 復元できない backup を復元しようとして初めて気づく、が最悪なので、
 * 先に確かめる口を用意する。何も変えないので AI へ公開してよい（OG7）。
 */
final class VerifyBackup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupRestorer $restorer,
    ) {}

    public function operationId(): string
    {
        return 'backup.verify';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! BackupPaths::isValidId($id)) {
            return Result::notFound('その backup はありません');
        }

        $result = $this->restorer->verify($id);

        return Result::success([
            'id' => $id,
            'ok' => $result['ok'],
            'problems' => $result['problems'],
            'manifest' => $result['manifest']?->toArray(),
        ]);
    }
}
