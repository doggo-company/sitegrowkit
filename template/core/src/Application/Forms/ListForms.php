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

/**
 * フォームの一覧（Phase 9.12 / STAGE 25B・SPEC §E.1）。
 *
 * 受信件数も一緒に返す。**「作ったけれど 1 件も来ていない」と
 * 「来ているが気づいていない」は別の話**で、一覧で見分けられる必要がある。
 */
final class ListForms implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly FormFields $fields,
    ) {}

    public function operationId(): string
    {
        return 'form.list';
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

        $forms = Form::query()->withCount('submissions')->orderBy('key')->get();

        $items = $forms
            ->map(fn (Form $form): array => FormPresenter::form(
                $form,
                $this->fields->problemFor($form->field_group_id),
                (int) $form->getAttribute('submissions_count'),
            ))
            ->values()
            ->all();

        return Result::success(['forms' => $items], ['count' => count($items)]);
    }
}
