<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormStatus;
use AgentCms\Core\Spam\HoneypotCheck;

/** 独立ページとページ内配置で、項目・送信先・迷惑送信対策を共用する。 */
final class PublicFormInput
{
    public function __construct(private readonly FormFields $fields) {}

    /** @return array<string, mixed>|null */
    public function embedded(string $key, string $nodeId, bool $preview): ?array
    {
        $form = Form::query()->where('key', $key)->first();
        if ($form === null || ! $form->status()->isVisible() || $this->fields->for($form) === []) {
            return null;
        }

        return $this->data($form) + ['idPrefix' => 'embed-'.$nodeId.'-', 'preview' => $preview, 'embedded' => true];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function data(Form $form, array $values = []): array
    {
        return [
            'form' => $form,
            'fields' => $this->fields->for($form),
            'values' => $values,
            'closed' => $form->status() === FormStatus::Closed,
            'action' => $form->confirm_step ? route('forms.confirm', ['key' => $form->key]) : route('forms.store', ['key' => $form->key]),
            'submitLabel' => $form->confirm_step ? '確認画面へ' : $form->submit_label,
            'honeypot' => HoneypotCheck::FIELD,
            'turnstileSiteKey' => (string) config('agentcms.turnstile.site_key', ''),
        ];
    }
}
