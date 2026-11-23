<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Settings;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\FrontPageChoices;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;

/** サイト設定の取得（Phase 9.1B / STEP 3） */
final class GetSettings implements Operation
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly SettingsStore $settings,
        private readonly OperationPolicy $policy,
        private readonly FrontPageChoices $frontPageChoices,
    ) {}

    public function operationId(): string
    {
        return 'settings.get';
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

        if (! $this->schemas->has('site')) {
            return Result::notFound('サイト設定のスキーマが見つかりません');
        }

        return Result::success([
            'schema' => $this->schemas->get('site')->toAdminSpec(),
            'values' => $this->settings->all(),
            /*
             * トップページに指定できるページ（R-5）。
             *
             * **schema には入れない。** 候補は「いまサイトに何が在るか」というデータで、
             * 定義ではない。schema へ焼き込むと、ページを 1 つ作るたびに定義が変わる。
             *
             * **AI にも同じものを返す。** `settings.get` は MCP から呼ばれるので、
             * ここに候補が無いと「id を推測して `settings.update` を試し、断られる」
             * しかなくなる。選べるものが分かっていれば、その往復は起きない。
             */
            'frontPageChoices' => $this->frontPageChoices->all(),
        ]);
    }
}
