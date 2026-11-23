<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Backups;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupPaths;

/**
 * backup を消す（Phase 9.11 / STAGE 24C）。
 *
 * **消したら戻せない。** 確認では「いつの・どれだけの」backup かを出す。
 * 復元前 backup（`pre-restore-`）は、事故の直前状態そのものなので、
 * 確認の文面でそのことを言う。
 */
final class DeleteBackup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly BackupCatalog $catalog,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'backup.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        if (! BackupPaths::isValidId($id)) {
            return Result::notFound('その backup はありません');
        }

        $manifest = $this->catalog->read($id);

        if ($manifest === null) {
            return Result::notFound('その backup はありません');
        }

        $impact = [
            "{$manifest->createdAt} に取った backup です",
            "投稿などの記録 {$manifest->rowCount()} 行、メディア {$manifest->media['files']} 件が入っています",
            '消すと元に戻せません',
        ];

        if ($manifest->isPreRestore()) {
            // 事故の直前状態そのものである
            $impact[] = 'これは**復元の直前**に自動で取られた backup です。復元をやり直す最後の手段になります';
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            "backup「{$id}」を削除します",
            $impact,
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        $this->catalog->delete($id);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(null, ['id' => $id]),
            resourceType: 'backup',
            resourceId: $id,
        );
    }
}
