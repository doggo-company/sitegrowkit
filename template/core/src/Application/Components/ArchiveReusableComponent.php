<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\Reference;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Components\ReusableReferences;

/**
 * 再利用 component をしまう（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 参照が 1 つでも残っていれば断る
 *
 * ここの問いは「**消してよいか**」である。だから
 * **下書きでもゴミ箱でも、参照は参照**として数える。`publish` の影響数とは
 * **向きが逆**で、同じ走査に違う filter を掛けている（§4.5 の表）。
 *
 * 断るときは**どのページが参照しているかを返す**。「消せません」だけでは直せない。
 *
 * ## 物理削除はしない
 *
 * `archived_at` を立てるだけである（`taxonomy_terms` と同じ作法・OG8 —
 * backup ができるまで消す道を作らない）。**参照側に外部キーは張れない**
 * （参照は document の JSON の中）ので、RESTRICT はここで行う。
 */
final class ArchiveReusableComponent implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ReusableReferences $references,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'component.reusable.archive';
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

        $component = ReusableComponent::query()->find($id);

        if (! $component instanceof ReusableComponent) {
            return $this->outcome->record($this, $context, Result::notFound('再利用 component が見つかりません'));
        }

        $references = $this->references->pagesUsing($id);

        if ($references !== []) {
            /*
             * **どこから参照されているかを返す。** 「消せません」だけでは、
             * 何を直せばよいか分からない（`ContentReferences` と同じ作法）。
             */
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('まだ使われているのでしまえません')
                    ->withMeta(['references' => array_map(
                        static fn (Reference $reference): array => [
                            'pageId' => $reference->pageId,
                            'title' => $reference->title,
                            'inPublished' => $reference->inPublished,
                            'inDraft' => $reference->inDraft,
                            'trashed' => $reference->trashed,
                        ],
                        $references,
                    )]),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        if (! $component->isActive()) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('既にしまわれています'),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            summary: "「{$component->label}」をしまいます",
            impact: [
                'どのページからも使われていないことを確認しました',
                'しまったものは一覧に出なくなります（中身は残ります）',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record(
                $this,
                $context,
                Result::confirmationRequired($confirmation),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        $component->update(['archived_at' => now()]);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['component' => ['id' => $component->id, 'key' => $component->key]]),
            resourceType: 'reusable_component',
            resourceId: $id,
        );
    }
}
