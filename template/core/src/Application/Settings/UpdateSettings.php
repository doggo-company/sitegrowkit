<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Settings;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Schema\SchemaRules;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;

/**
 * サイト設定の更新（Phase 9.1B / STEP 3 — **F-A1 の重複解消**）。
 *
 * これまで同じ手順（SchemaRules → SettingsStore）が
 * `AdminSiteController::update` と `SetSiteSettingCommand` の 2 か所に書かれていた。
 * **この 1 クラスへ統合**し、Admin / CLI / MCP はすべてここを通す。
 *
 * 部分更新（1 フィールドだけ）にも対応する — CLI が単一キーを更新するため。
 * 部分更新では **渡されたキーのみ**を検証する（他フィールドの required で落とさない）。
 */
final class UpdateSettings implements Operation
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly SettingsStore $settings,
        private readonly ValidatorFactory $validators,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'settings.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $values  更新したいキーと値
     * @param  bool  $partial  true なら渡されたキーだけを検証・更新する
     */
    public function execute(array $values, OperationContext $context, bool $partial = false): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)), 'settings', 'site');
        }

        if (! $this->schemas->has('site')) {
            return $this->outcome->record($this, $context, Result::notFound('サイト設定のスキーマが見つかりません'), 'settings', 'site');
        }

        $schema = $this->schemas->get('site');
        // settings は unique 制約を持たないためテーブル名はダミーで良い
        $rules = SchemaRules::for($schema, 'settings');

        if ($partial) {
            $unknown = array_diff(array_keys($values), array_keys($rules));

            if ($unknown !== []) {
                return $this->outcome->record($this, $context, Result::validationError(
                    array_fill_keys(array_values($unknown), ['サイト設定にこのフィールドはありません']),
                ), 'settings', 'site');
            }

            $rules = array_intersect_key($rules, $values);
        }

        /*
         * **欄の名前と言い回しも schema から取る。**
         *
         * 渡さないと `front_page_id` のような内部の名前と、英語の既定文言が
         * そのまま利用者に出る（R-5 の監査 指摘 1）。lang ファイルへ欄ごとに
         * 書き写す手もあるが、label は既に schema にあるので二重管理になる。
         */
        $validator = $this->validators->make(
            $values,
            $rules,
            SchemaRules::messages($schema),
            SchemaRules::attributes($schema),
        );

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $this->outcome->record($this, $context, Result::validationError($errors), 'settings', 'site');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $this->settings->put($validated);

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['updated' => array_keys($validated)]),
            'settings',
            'site',
            ['fields' => array_keys($validated)],
        );
    }
}
