<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\FieldGroups;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\QueryException;

/**
 * Field Group の作成（Phase 9.5-B / ADR-0016）。
 *
 * 作成は **Write**。既存データを 1 バイトも変えず、項目を足すだけである
 * （足した項目は `data` JSON へ入るので migration も要らない — owner 決定 G2-(a)）。
 *
 * 作成した束は即 `published`。draft / publish の運用は Phase 2（owner 決定 G5）。
 *
 * 入力の検証・拒否は `FieldGroupDraft` が一元的に行う。ここでキーを拾い直さない。
 */
final class CreateFieldGroup implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly FieldGroupRepository $repository,
        private readonly FieldGroupWriter $writer,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeResolver $types,
    ) {}

    public function operationId(): string
    {
        return 'field_group.create';
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

        if (! $this->repository->isInstalled()) {
            return $this->outcome->record($this, $context, Result::conflict(
                'カスタムフィールドの保存先がまだ準備できていません',
            ));
        }

        $draft = new FieldGroupDraft($input, $this->schemas, $this->repository, $this->types);

        if ($draft->failed()) {
            return $this->outcome->record($this, $context, Result::validationError(
                $draft->errors(), '入力内容を確認してください',
            ));
        }

        try {
            $id = $this->writer->create($draft->key, $draft->label, $draft->fields, $draft->locations, $context);
        } catch (QueryException $e) {
            // 同じ key を同時に作られた場合など。生の例外を利用者へ返さない
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                '同時に別の変更があったため作成できませんでした。もう一度お試しください。',
            ));
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['fieldGroup' => ['id' => $id, 'key' => $draft->key, 'label' => $draft->label]]),
            resourceType: 'field_group',
            resourceId: $id,
            // **値や選択肢は載せない**。何を作ったかの要約だけ
            metadata: [
                'key' => $draft->key,
                'fieldCount' => count($draft->fields),
                'locationCount' => count($draft->locations),
            ],
        );
    }
}
