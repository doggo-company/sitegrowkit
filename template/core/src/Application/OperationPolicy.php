<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * 操作単位の認可（Phase 9.1B / STEP 5 — F-A3 の解消）。
 *
 * これまで認可は route middleware（auth・verified）しか無く、
 * API / MCP を足すと認可が自然には効かなかった。
 * **判定は Application 層で行い、Admin と MCP で同じ結果になる**ようにする。
 *
 * Phase 9.1B では role 管理を追加しない。既定の実装は
 * 「認証済み管理者はすべての操作を許可 / 未認証の agent は read のみ」。
 * 将来 role / scope を足すときは、この実装だけを差し替える。
 */
interface OperationPolicy
{
    public function allows(string $operationId, OperationClassification $classification, OperationContext $context): bool;

    /** 拒否時に「何が足りないか」を返す（MCP へ必要権限を伝えるため） */
    public function denialReason(string $operationId, OperationContext $context): string;
}
