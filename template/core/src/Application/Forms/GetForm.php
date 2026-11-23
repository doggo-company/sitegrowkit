<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormPresenter;
use AgentCms\Core\Schema\Field;

/**
 * フォーム 1 本と、その入力項目（Phase 9.12 / STAGE 25B・SPEC §E.1）。
 *
 * 項目は Field Group から引く（OG1）。ここでは**定義を組み立て直さない**。
 */
final class GetForm implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly FormFields $fields,
    ) {}

    public function operationId(): string
    {
        return 'form.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $key, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $form = self::find($key);

        if ($form === null) {
            return Result::notFound("フォーム [{$key}] は存在しません");
        }

        return Result::success([
            'form' => FormPresenter::form(
                $form,
                $this->fields->problemFor($form->field_group_id),
                (int) $form->submissions()->count(),
            ),
            'fields' => array_map(
                static fn (Field $field): array => [
                    'key' => $field->key,
                    'type' => $field->type,
                    'label' => $field->label,
                    'required' => $field->required,
                    'help' => $field->help,
                    'choices' => $field->choices,
                    'max' => $field->max,
                ],
                $this->fields->for($form),
            ),
        ]);
    }

    public static function find(string $key): ?Form
    {
        return Form::query()->where('key', $key)->first();
    }
}
