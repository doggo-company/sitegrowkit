<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupLocation;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupStatus;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * Field Group の復元（Phase 9.5-B / ADR-0016）。
 *
 * 状態を `published` に戻すだけで、定義には触らない。
 * 復元先は published 固定（draft / publish の運用は Phase 2 — owner 決定 G5）。
 *
 * ## 復元は「衝突するかもしれない」操作である
 *
 * アーカイブしている間に、同じ key の項目が別の束やファイル schema へ
 * 増えているかもしれない。そのまま戻すと **先に読まれたほうだけが効く**
 * 状態になり、利用者からは「戻したのに出ない」に見える。
 * だから戻す前に衝突を調べ、あれば拒否する。
 */
final class RestoreFieldGroup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
        private readonly SchemaRegistry $schemas,
    ) {}

    public function operationId(): string
    {
        return 'field_group.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
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

        if ($group->status !== FieldGroupStatus::Archived) {
            return $this->outcome->record($this, $context, Result::conflict('この束はアーカイブされていません'),
                resourceType: 'field_group', resourceId: $id);
        }

        $conflicts = $this->conflicts($id, $group->locations, array_map(
            static fn (Field $f): string => $f->key,
            $group->fields,
        ));

        if ($conflicts !== []) {
            return $this->outcome->record($this, $context, Result::conflict(
                '復元できません。項目のキーが他と重複しています（'.implode(' / ', $conflicts).'）',
            ), resourceType: 'field_group', resourceId: $id);
        }

        $this->writer->changeStatus($id, FieldGroupStatus::Published, $context);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['fieldGroup' => ['id' => $id, 'label' => $group->label]]),
            resourceType: 'field_group',
            resourceId: $id,
            metadata: ['key' => $group->key, 'fieldCount' => count($group->fields)],
        );
    }

    /**
     * @param  list<FieldGroupLocation>  $locations
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function conflicts(string $id, array $locations, array $keys): array
    {
        $conflicts = [];

        foreach ($locations as $location) {
            $target = $location->kind?->value === 'content_type' ? $location->value : null;

            if ($target === null) {
                continue;
            }

            $schema = $this->schemas->declared()[$target] ?? null;

            if ($schema instanceof ContentSchema) {
                $conflicts = [...$conflicts, ...array_intersect($keys, $schema->fieldKeys())];
            }

            foreach ($this->repository->all() as $other) {
                if ($other->id === $id || ! $other->contributesFields()) {
                    continue;
                }

                foreach ($other->locations as $otherLocation) {
                    if (! $otherLocation->matchesContentType($target)) {
                        continue;
                    }

                    $conflicts = [...$conflicts, ...array_intersect(
                        $keys,
                        array_map(static fn (Field $f): string => $f->key, $other->fields),
                    )];
                }
            }
        }

        return array_values(array_unique($conflicts));
    }
}
