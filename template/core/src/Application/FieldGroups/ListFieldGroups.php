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
 * Field Group の一覧（Phase 9.5-A / ADR-0015・SPEC §7.2）。
 *
 * 一覧の形は `FieldGroupPresenter` が持つ（一覧と編集で同じ形を返すため）。
 *
 * Read なので監査しない（`OperationAuditCoverageTest` の対象外）。
 *
 * ## 「空」と「未導入」を混同しない
 *
 * `installed: false` は **migration がまだ通っていない**という意味で、
 * 「Field Group が 0 件」とは別のことである。画面はこの 2 つを別の文言で出す。
 * DB 自体へ到達できない場合は `FieldGroupRepository` が例外を投げるので、
 * ここには来ない（黙って空にしない）。
 */
final class ListFieldGroups implements Operation
{
    public function __construct(
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupPresenter $presenter,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'field_group.list';
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

        if (! $this->repository->isInstalled()) {
            return Result::success(['groups' => [], 'installed' => false]);
        }

        return Result::success([
            'groups' => array_map($this->presenter->summary(...), $this->repository->all()),
            'installed' => true,
        ]);
    }
}
