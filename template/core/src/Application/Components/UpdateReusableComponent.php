<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

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
 * 再利用 component の下書きを直す（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## ここでは確認を求めない
 *
 * 直すのは `draft_document` だけで、**どのページの見た目も変わらない**。
 * 「影響が見えない共有編集」の本丸は **`publish` の瞬間**である。
 * 変わらない操作で確認を求めると、**確認そのものが読まれなくなる**。
 *
 * ## 代わりに「下書きにだけ出る数」を返す
 *
 * 何件のページがこれを参照しているかは、直している人が知りたいことである。
 * ただし**公開の数と混ぜない** — 混ぜると「もう公開ページが変わった」と読める。
 */
final class UpdateReusableComponent implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ReusableDocumentValidator $validator,
        private readonly ReusableReferences $references,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'component.reusable.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $input  label / document のうち送ったものだけ
     */
    public function execute(string $id, array $input, OperationContext $context): Result
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
            // しまったものを直せると、「使われていないはず」の前提が崩れる
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('しまわれている再利用 component は直せません'),
                resourceType: 'reusable_component',
                resourceId: $id,
            );
        }

        $changes = [];

        if (array_key_exists('label', $input)) {
            $label = is_string($input['label']) ? trim($input['label']) : '';

            if ($label === '') {
                return $this->outcome->record($this, $context, Result::validationError([
                    'label' => ['名前を入力してください'],
                ]));
            }

            $changes['label'] = $label;
        }

        if (array_key_exists('document', $input)) {
            if (! is_array($input['document'])) {
                return $this->outcome->record($this, $context, Result::validationError([
                    'document' => ['中身の形式が不正です'],
                ]));
            }

            // **id はサーバーが採番する。** 既にあるものは残す（選択が飛ばないように）
            $document = ReusableSubtree::withIds($input['document']);
            $errors = $this->validator->errors($document);

            if ($errors !== []) {
                return $this->outcome->record($this, $context, Result::validationError($errors));
            }

            $changes['draft_document'] = $document;
        }

        if ($changes === []) {
            return $this->outcome->record($this, $context, Result::validationError([
                'document' => ['変更する内容がありません'],
            ]));
        }

        $component->update($changes);

        $references = $this->references->pagesUsing($id);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['component' => ['id' => $component->id, 'key' => $component->key]],
                [
                    /*
                     * **下書きにだけ出る数**である。公開に出るのは `publish` を通してからで、
                     * ここで公開の数を返すと「もう変わった」と読める。
                     */
                    'draftPages' => count($references),
                    'publicPagesPending' => count(array_filter(
                        $references,
                        static fn (Reference $reference): bool => $reference->affectsPublic(),
                    )),
                ],
            ),
            resourceType: 'reusable_component',
            resourceId: $id,
        );
    }
}
