<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content\Revisions;

use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Revisions\RevisionWriter;
use Illuminate\Support\Facades\DB;

/**
 * 履歴の一覧（Phase 9.8 / STAGE 21D）。
 *
 * **読むだけで落とさない。** 版が 1 件も無くても、定義が壊れていても、
 * 空の一覧か診断を返す。履歴を見に来た人の前で、履歴そのものが原因で
 * 画面が落ちるのでは何も分からない。
 *
 * 復元と違って `Read` である。ただし AI へは公開しない — 履歴の閲覧口を
 * 増やす判断は別途要る（この Stage は復元を Admin に留めるところまで）。
 */
final class ListContentRevisions implements Operation
{
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
    ) {}

    public function operationId(): string
    {
        return 'content.revision.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $type, int $id, OperationContext $context, int $limit = self::MAX_PER_PAGE): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        // ゴミ箱の中でも履歴は読める（戻す前に中身を確かめたい）
        $model = $this->get->findTrashed($type, $id, $context);

        if ($model instanceof Result) {
            return $model;
        }

        $rows = DB::table(RevisionWriter::TABLE)
            ->where('content_type', $type)
            ->where('content_id', $id)
            ->orderByDesc('revision')
            ->limit(max(1, min($limit, self::MAX_PER_PAGE)))
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $hydrated = RevisionWriter::hydrate($row);

            $items[] = [
                'revision' => $hydrated['revision'],
                'reason' => $hydrated['reason'],
                'kind' => $hydrated['kind'],
                'created_at' => $hydrated['created_at'],
                'created_interface' => $hydrated['created_interface'],
                'created_by' => $hydrated['created_by'],
                // その版の時点で投稿がどう見えていたか（版の行の時刻とは別物）
                'entry_updated_at' => $hydrated['snapshot']['entry_updated_at'] ?? null,
                // 読めない行は隠さず印を付ける。落としも、無かったことにもしない
                'readable' => $hydrated['snapshot'] !== [],
            ];
        }

        return Result::success(
            ['items' => $items],
            ['type' => $type, 'id' => $id, 'total' => count($items)],
        );
    }
}
