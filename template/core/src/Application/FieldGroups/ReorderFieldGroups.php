<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;

/**
 * Field Group の並べ替え（Phase 9.5-B / ADR-0016）。
 *
 * 並び順は **同じ投稿タイプに複数の束が当たるときの項目の順序**を決める。
 *
 * ## 全件をまとめて受け取る
 *
 * 部分的な並び替えを受け取らない。欠けた束の位置が黙って変わってしまい、
 * 「触っていない束の順番が入れ替わった」が起きるため。
 * 送られた list は **現在ある束の全 ID とちょうど一致**していなければならない。
 */
final class ReorderFieldGroups implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'field_group.reorder';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /** @param  array<string, mixed>  $input */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $unknown = array_diff(array_keys($input), ['orderedIds']);

        if ($unknown !== []) {
            return $this->outcome->record($this, $context, Result::validationError(
                ['orderedIds' => ['['.implode(', ', $unknown).'] は指定できません']],
            ));
        }

        $ordered = $input['orderedIds'] ?? null;

        if (! is_array($ordered) || ! array_is_list($ordered)) {
            return $this->outcome->record($this, $context, Result::validationError(
                ['orderedIds' => ['並び順は配列で指定してください']],
            ));
        }

        /*
         * **要素を捨てない**（CORRECTION）。
         *
         * 直前の実装は非 string を `array_filter` で落としてから照合していた。
         * その結果、全 ID に加えて `null` や数値を混ぜても「ちょうど一致」と
         * 判定されて成功していた — 入力を黙って捨てないという契約に反する。
         * 混ざっていたら、その時点で拒否する。
         */
        foreach ($ordered as $index => $id) {
            if (! is_string($id) || $id === '') {
                return $this->outcome->record($this, $context, Result::validationError(
                    ['orderedIds' => ["並び順の {$index} 番目が束の ID ではありません"]],
                ));
            }
        }

        // 上の走査で全要素が非空 string だと確かめてある
        $orderedIds = $ordered;

        $ids = $orderedIds;

        $existing = array_map(
            static fn (FieldGroupRecord $group): string => $group->id,
            $this->repository->isInstalled() ? $this->repository->all() : [],
        );

        sort($ids);
        sort($existing);

        // 重複も欠落も余分も、ここで同時に落ちる（件数と中身の両方を見ている）
        if ($ids !== $existing) {
            return $this->outcome->record($this, $context, Result::validationError(
                ['orderedIds' => ['並び替えは、いま表示されている束をすべて含めて送ってください']],
            ));
        }

        $this->writer->reorder($orderedIds, $context);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['count' => count($orderedIds)]),
            resourceType: 'field_group',
            metadata: ['count' => count($orderedIds)],
        );
    }
}
