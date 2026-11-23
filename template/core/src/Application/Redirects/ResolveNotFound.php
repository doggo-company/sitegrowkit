<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\NotFoundLog;
use AgentCms\Core\Redirects\RedirectRule;
use Illuminate\Support\Facades\DB;

/**
 * 404 の記録からルールを作る（Phase 9.10 / STAGE 23C・SPEC §E.1・§H.2）。
 *
 * **これが 9.10 の本命の導線である。** ゴミ箱へ入れた投稿や、
 * 外部サイトに残った古いリンクは、どこへ転送すべきかを CMS が知らない。
 * だが「どの URL が踏まれているか」は分かる。人が転送先だけ決めればよい。
 *
 * ## 印は保存が通ってから付ける
 *
 * ルールの作成と `resolved_at` は同じ transaction で行う。
 * 先に印を付けると、保存に失敗したときに**直したことになっている記録**が残る。
 */
final class ResolveNotFound implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly SaveRedirect $save,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'not_found.resolve';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $id, string $target, OperationContext $context, int $statusCode = 301): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $log = NotFoundLog::query()->find($id);

        if ($log === null) {
            return Result::notFound("404 の記録（ID: {$id}）は見つかりません");
        }

        $result = DB::transaction(function () use ($log, $target, $statusCode, $context): Result {
            $saved = $this->save->execute(
                [
                    'match_type' => 'exact',
                    'source_path' => $log->path,
                    'target_url' => $target,
                    'status_code' => $statusCode,
                    'is_enabled' => true,
                    'keep_query' => true,
                    // どうやってできたルールかを残す
                    'created_reason' => RedirectRule::REASON_NOT_FOUND,
                ],
                $context,
                null,
                LockVersionInput::of(0),
            );

            if (! $saved->isSuccess()) {
                return $saved;
            }

            // 保存が通ってから印を付ける
            NotFoundLog::query()->whereKey($log->getKey())->update(['resolved_at' => now()]);

            return $saved;
        });

        return $this->outcome->record(
            $this,
            $context,
            $result,
            resourceType: 'not_found',
            resourceId: (string) $id,
        );
    }
}
