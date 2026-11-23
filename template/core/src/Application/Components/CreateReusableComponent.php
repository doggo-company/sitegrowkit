<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\NodeId;
use Illuminate\Database\QueryException;

/**
 * 再利用 component を作る（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 作った時点では、どのページも変わらない
 *
 * 作るのは `draft_document` だけである。**公開に出るのは `publish` を通してから**なので、
 * ここで確認を求める理由が無い（求めると、何も起きない操作で確認が出る）。
 *
 * ## 中身は registered component だけ
 *
 * `DocumentValidator` が同じ allowlist で見る。再利用 component を
 * **registry を回避する経路にしない**（§4.5 の制約）。
 */
final class CreateReusableComponent implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ReusableDocumentValidator $validator,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'component.reusable.create';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $input  key / label / document
     */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        $document = is_array($input['document'] ?? null) ? $input['document'] : null;

        $errors = [];

        if (! NodeId::isValidKey($key)) {
            $errors['key'][] = 'キーの形式が不正です（英小文字・数字・ハイフン）';
        }

        if ($label === '') {
            $errors['label'][] = '名前を入力してください';
        }

        if ($document === null) {
            $errors['document'][] = '中身がありません';
        } else {
            // **id はサーバーが採番する**（画面に採番させない — `ReusableSubtree`）
            $document = ReusableSubtree::withIds($document);
            $errors = array_merge($errors, $this->validator->errors($document));
        }

        if ($errors !== []) {
            return $this->outcome->record($this, $context, Result::validationError($errors));
        }

        try {
            $component = ReusableComponent::query()->create([
                'key' => $key,
                'label' => $label,
                'draft_document' => $document,
            ]);
        } catch (QueryException) {
            // key は unique。**衝突を「作れました」に化けさせない**
            return $this->outcome->record($this, $context, Result::validationError([
                'key' => ["キー [{$key}] は既に使われています"],
            ]));
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['component' => ['id' => $component->id, 'key' => $component->key]]),
            resourceType: 'reusable_component',
            resourceId: $component->id,
        );
    }
}
