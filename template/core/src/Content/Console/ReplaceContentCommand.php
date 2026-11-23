<?php

declare(strict_types=1);

namespace AgentCms\Core\Content\Console;

use AgentCms\Core\Application\Content\ReplaceContent;
use AgentCms\Core\Application\Content\ReplaceContentInput;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use Illuminate\Console\Command;

/**
 * 本文の一括置換（P1 ⑧）— CLI の入出力 adapter。
 *
 * **判断はここに無い。** 予告・token・backup・版・取引・監査はすべて
 * `ReplaceContent`（Application Service）に在り、管理画面も同じ 1 つを通る。
 * ここは option を `ReplaceContentInput` へ写し、`Result` を終了コードと文字に写すだけ。
 *
 * ## 既定では 1 文字も変えない
 *
 * `--apply` だけでは動かない。予告が出した token を `--confirm` で返して初めて置き換わる。
 * `--apply` を書き忘れても service が token を要求するので、**守りは 2 枚**ある。
 *
 * ## CLI だけが持つ 2 つの明示フラグ
 *
 * `--limit`（触る件数を絞る）と `--allow-stale-backup`（24 時間より古い backup でも進む）は
 * サーバー管理者の明示操作として残す。管理画面はどちらも立てない — 画面には
 * 上限（黙って先頭だけ変えない）と、中身まで確かめた backup（`BackupRestorer::verify`）を課す。
 * CLI の backup 検査は従来どおり **目録と時刻**である。
 */
final class ReplaceContentCommand extends Command
{
    protected $signature = 'agentcms:content-replace
        {--type= : 投稿タイプ（必須）}
        {--field= : 置き換える項目（必須）}
        {--from= : 置き換える前の文字列（必須）}
        {--to= : 置き換えたあとの文字列（必須。空文字なら「消す」）}
        {--limit= : 触る最大件数}
        {--apply : 実際に置き換える（--confirm と一緒に使う）}
        {--confirm= : 予告が出した token}
        {--allow-stale-backup : backup が古くても進む}';

    protected $description = '投稿の本文を一括置換します（既定は予告だけで、1 文字も変えません）';

    public function handle(ReplaceContent $replace): int
    {
        $type = $this->stringOption('type');
        $field = $this->stringOption('field');
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        if ($type === null || $field === null || $from === null || $to === null) {
            $this->error('--type / --field / --from / --to はすべて必要です（--from は空にできません）。');

            return self::FAILURE;
        }

        $input = new ReplaceContentInput(
            type: $type,
            field: $field,
            from: $from,
            to: $to,
            limit: $this->intOption('limit'),
            allowStaleBackup: (bool) $this->option('allow-stale-backup'),
        );

        $apply = (bool) $this->option('apply');
        $token = $this->stringOption('confirm');
        $context = OperationContext::cli('content-replace');

        if (! $apply) {
            return $this->announce($replace->preview($input, $context));
        }

        $result = $replace->execute($input, $context->withConfirmationToken($token));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            // 合わない token で来た（あるいは無い）。**件数が変わったのかもしれない**ので、そう言う
            $this->error('その token では実行できません（対象が変わったか、期限が切れています）。');
            $this->line('いまの件数で取り直した token: --confirm='.($result->confirmation !== null ? $result->confirmation->token : ''));

            return self::FAILURE;
        }

        if (! $result->isSuccess()) {
            $this->error((string) $result->message);

            return self::FAILURE;
        }

        $this->info(($result->data['changed'] ?? 0).' 件を置き換えました。');

        return self::SUCCESS;
    }

    /** 予告を出す。**ここでは 1 文字も変えない。** */
    private function announce(Result $result): int
    {
        if (! $result->isSuccess()) {
            $this->error((string) $result->message);

            return self::FAILURE;
        }

        $matched = (int) ($result->data['matched'] ?? 0);

        if ($matched === 0) {
            $this->info('0 件です（置き換える対象がありません）。');

            return self::SUCCESS;
        }

        $this->info($matched.' 件が対象です。');

        $confirmation = $result->data['confirmation'] ?? null;

        if (is_array($confirmation) && is_string($confirmation['token'] ?? null)) {
            $this->line('置き換えるには、この token を付けて実行してください:');
            $this->line('  --apply --confirm='.$confirmation['token']);
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) ? (string) $value : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_scalar($value) && $value !== '' ? (int) $value : null;
    }
}
