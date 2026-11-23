<?php

declare(strict_types=1);

namespace App\Application\Activity;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Audit\AuditLog;
use Generator;

/**
 * 行動履歴の CSV 書き出し（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## 2 段構えにしている理由
 *
 * `execute()` が **権限と件数**だけを判定し、`rows()` が本文を少しずつ流す。
 * こうすると Application 層に HTTP（StreamedResponse）を持ち込まずに、
 * 数万行でもメモリを一定に保てる。
 *
 * ## CSV formula injection
 *
 * `=` `+` `-` `@` やタブ・改行で始まる値は、表計算ソフトが**数式として実行**する。
 * 先頭に `'` を付けて必ず文字列として開かせる。
 */
final class ExportActivity implements Operation
{
    /** 1 回の書き出しで扱う上限（超えたら期間を絞ってもらう） */
    public const ROW_LIMIT = 50000;

    /** DB から取り出す単位 */
    private const CHUNK = 500;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ActivityPresenter $presenter,
        private readonly ListActivity $list,
    ) {}

    public function operationId(): string
    {
        return 'activity.export';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(ActivityFilter $filter, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $total = $filter->apply(AuditLog::query())->count();

        if ($total > self::ROW_LIMIT) {
            return Result::conflict(
                '対象が多すぎます（'.number_format($total).' 件）。期間や条件を絞ってから書き出してください。',
                ['total' => $total, 'limit' => self::ROW_LIMIT],
            );
        }

        return Result::success(['total' => $total], ['total' => $total]);
    }

    /** @return list<string> */
    public function header(bool $includeNetwork): array
    {
        return array_values(array_filter([
            '日時', '実行者', '実行者ID', '操作', '結果', '経路', '対象種別', '対象ID', '概要',
            $includeNetwork ? 'IPアドレス' : null,
            $includeNetwork ? 'ユーザーエージェント' : null,
        ]));
    }

    /**
     * CSV の各行（ヘッダーを含まない）を少しずつ返す。
     *
     * @return Generator<int, list<string>>
     */
    public function rows(ActivityFilter $filter, bool $includeNetwork): Generator
    {
        $query = $filter->apply(AuditLog::query())->orderByDesc('created_at')->orderByDesc('id');

        foreach ($query->lazy(self::CHUNK) as $log) {
            $row = $this->presenter->present($log, $includeNetwork);

            $cells = [
                (string) ($row['occurredAt'] ?? ''),
                (string) $row['actorLabel'],
                (string) ($row['actorId'] ?? ''),
                (string) $row['operationLabel'],
                (string) $row['resultLabel'],
                (string) $row['interfaceLabel'],
                (string) ($row['resourceType'] ?? ''),
                (string) ($row['resourceId'] ?? ''),
                (string) $row['summary'],
            ];

            if ($includeNetwork) {
                $cells[] = (string) ($row['ipAddress'] ?? '');
                $cells[] = (string) ($row['userAgent'] ?? '');
            }

            yield array_map(self::neutralize(...), $cells);
        }
    }

    public function maySeeNetworkContext(OperationContext $context): bool
    {
        return $this->list->maySeeNetworkContext($context);
    }

    /** 表計算ソフトが数式として解釈しうる値を、必ず文字列として開かせる */
    public static function neutralize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
