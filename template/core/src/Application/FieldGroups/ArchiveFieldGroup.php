<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupStatus;
use AgentCms\Core\FieldGroups\FieldGroupWriter;

/**
 * Field Group のアーカイブ（Phase 9.5-B / ADR-0016）。
 *
 * **物理削除を作らない**（SPEC §3.1-4）。archive は
 *
 * - 定義を 1 バイトも消さない
 * - 投稿に入っている値も消さない（`data` JSON に残る — owner 決定 G3-(b)）
 * - overlay から外れるので、編集画面から項目が **見えなくなる**だけ
 *
 * それでも Destructive として扱う。編集画面から項目が消えるのは
 * 利用者にとって十分に大きな変化で、確認なしに起こしてよいことではない。
 */
final class ArchiveFieldGroup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'field_group.archive';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $group = $this->repository->isInstalled() ? $this->repository->find($id) : null;

        if ($group === null) {
            return $this->outcome->record($this, $context, Result::notFound('カスタムフィールドが見つかりません'),
                resourceType: 'field_group', resourceId: $id);
        }

        if ($group->status === FieldGroupStatus::Archived) {
            return $this->outcome->record($this, $context, Result::conflict('この束は既にアーカイブされています'),
                resourceType: 'field_group', resourceId: $id);
        }

        $targets = [];

        foreach ($group->locations as $location) {
            $targets[] = ($location->kind?->label() ?? $location->rawKind).' / '.$location->value;
        }

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            "カスタムフィールド「{$group->label}」をアーカイブします",
            [
                count($group->fields).' 個の項目が編集画面から見えなくなります',
                ...($targets === [] ? [] : ['割当先: '.implode(' / ', $targets)]),
                '入力済みの値は削除されません。復元すれば再び表示されます。',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'field_group', resourceId: $id, metadata: ['key' => $group->key]);
        }

        $this->writer->changeStatus($id, FieldGroupStatus::Archived, $context);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['fieldGroup' => ['id' => $id, 'label' => $group->label]]),
            resourceType: 'field_group',
            resourceId: $id,
            metadata: ['key' => $group->key, 'fieldCount' => count($group->fields)],
        );
    }
}
