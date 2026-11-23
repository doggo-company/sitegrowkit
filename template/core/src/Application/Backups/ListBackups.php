<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupManifest;

/**
 * backup の一覧（Phase 9.11 / STAGE 24C・SPEC §E.1）。
 *
 * **中身は開かない。** zip の `manifest.json` だけを読むので、
 * 大きな backup が並んでいても一覧は重くならない。
 */
final class ListBackups implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupCatalog $catalog,
    ) {}

    public function operationId(): string
    {
        return 'backup.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $backups = array_map(
            static fn (BackupManifest $m): array => [
                'id' => $m->id,
                'created_at' => $m->createdAt,
                'size_bytes' => $m->sizeBytes,
                'rows' => $m->rowCount(),
                'media_files' => $m->media['files'],
                // 復元前 backup は世代整理の対象外。画面でも区別できるようにする
                'pre_restore' => $m->isPreRestore(),
                'excluded' => $m->excluded,
            ],
            $this->catalog->all(),
        );

        return Result::success(['backups' => $backups], ['count' => count($backups)]);
    }
}
