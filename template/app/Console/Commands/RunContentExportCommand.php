<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ContentExport;
use App\Support\ContentExportRuns;
use Illuminate\Console\Command;

/** 管理画面で予約した書き出しだけを実行する内部コマンド。 */
final class RunContentExportCommand extends Command
{
    protected $signature = 'agentcms:content-export-run {id}';

    protected $description = '予約した内容書き出しを実行する';

    public function handle(ContentExportRuns $runs, ContentExport $export): int
    {
        $id = $this->argument('id');

        return is_string($id) && $runs->run($id, $export) ? self::SUCCESS : self::FAILURE;
    }
}
