<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Settings\UpdateSettings;
use Illuminate\Console\Command;

/**
 * サイト設定を 1 項目だけ更新する（Setup Wizard など非対話経路から使う）。
 *
 * Phase 9.1B: **検証と保存の手順を自前で持たない**。
 * 管理画面と同じ `UpdateSettings` を呼ぶ（F-A1 の重複解消）。
 */
final class SetSiteSettingCommand extends Command
{
    protected $signature = 'agentcms:site-setting {key : content/schemas/site.json のフィールド key} {value}';

    protected $description = 'Update a single site setting through the schema-validated settings store';

    public function handle(UpdateSettings $updateSettings): int
    {
        /** @var string $key */
        $key = $this->argument('key');
        /** @var string $value */
        $value = $this->argument('value');

        $result = $updateSettings->execute(
            [$key => $value],
            OperationContext::cli('agentcms:site-setting'),
            partial: true,
        );

        if (! $result->isSuccess()) {
            foreach ($result->errors as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            if ($result->errors === []) {
                $this->components->error($result->message ?? 'サイト設定を更新できませんでした');
            }

            return self::FAILURE;
        }

        $this->components->info("サイト設定 [{$key}] を更新しました");

        return self::SUCCESS;
    }
}
