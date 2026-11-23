<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupRepository;

/**
 * Field Group を 1 件返す（Phase 9.5-B）。編集画面が読む。
 *
 * Read なので監査しない。壊れた定義でも **返す**（隠すと直せない）。
 */
final class GetFieldGroup implements Operation
{
    public function __construct(
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupPresenter $presenter,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'field_group.get';
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

        if (! $this->repository->isInstalled()) {
            return Result::notFound('カスタムフィールドの保存先がまだ準備できていません');
        }

        $group = $this->repository->find($id);

        if ($group === null) {
            return Result::notFound('カスタムフィールドが見つかりません');
        }

        return Result::success([
            'group' => $this->presenter->detail($group),
            'contentTypes' => $this->presenter->contentTypeOptions(),
        ]);
    }
}
