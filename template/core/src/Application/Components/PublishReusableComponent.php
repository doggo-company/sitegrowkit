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
 * 再利用 component を公開する（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## ここが「影響が見えない共有編集」の本丸である
 *
 * 下書きを直しても、どのページの見た目も変わらない。**変わるのはこの瞬間**で、
 * しかも**自分が開いていないページまで**変わる。だから**必ず訊く**。
 *
 * 訊くときに答える問いは「**いま公開されている見た目がいくつ変わるか**」である。
 * だから**公開中のページ数を主に**出し、下書きにだけ在るものは分けて添える。
 * 数を大きく見せると、警告は読まれなくなる（正当なものを大げさに言わない）。
 *
 * ## 分類は Write である
 *
 * 消えるものは無い（`published_document` を差し替えるだけで、下書きも残る）。
 * **それでも確認を求める**のは、分類ではなく**この操作の都合**である（`requireAlways`）。
 */
final class PublishReusableComponent implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ReusableDocumentValidator $validator,
        private readonly ReusableReferences $references,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'component.reusable.publish';
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

        $component = ReusableComponent::query()->find($id);

        if (! $component instanceof ReusableComponent) {
            return $this->outcome->record($this, $context, Result::notFound('再利用 component が見つかりません'));
        }

        if (! $component->isActive()) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('しまわれている再利用 component は公開できません'),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        /*
         * **公開の直前にもう一度見る。** 下書きを保存したときは通っていても、
         * その後に参照先の実体が変わって（循環・上限超過）いることがある。
         */
        $errors = $this->validator->errors($component->draft_document);

        if ($errors !== []) {
            return $this->outcome->record(
                $this,
                $context,
                Result::validationError($errors),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        $references = $this->references->pagesUsing($id);
        $public = count(array_filter(
            $references,
            static fn (Reference $reference): bool => $reference->affectsPublic(),
        ));
        $draftOnly = count($references) - $public;

        $confirmation = $this->confirmations->requireAlways(
            $this->operationId(),
            $context,
            ['id' => $id],
            summary: "「{$component->label}」を公開します（公開中 {$public} ページに反映されます）",
            impact: array_filter([
                $public > 0
                    ? "公開中の {$public} ページの見た目が、この操作で変わります"
                    : '公開中のページには、まだ使われていません',
                $draftOnly > 0 ? "下書きにだけ使われているページが {$draftOnly} 件あります" : null,
            ]),
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

        $component->update([
            'published_document' => $component->draft_document,
            'published_at' => now(),
        ]);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['component' => ['id' => $component->id, 'key' => $component->key]],
                ['publicPages' => $public, 'draftPages' => $draftOnly],
            ),
            resourceType: 'reusable_component',
            resourceId: $id,
        );
    }
}
