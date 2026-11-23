<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * Phase 9.1B の既定ポリシー（role 管理は追加しない — owner 指示）。
 *
 * - Admin / API: 認証済み利用者（actorId あり）はすべての操作を許可
 * - CLI / System: すべて許可（実行者はサーバ管理者）
 * - MCP / WebMCP（agent）: 許可するが、**破壊的操作は確認必須**
 *   （確認の強制は `ConfirmationGate` 側で行う。ここでは経路を落とさない）
 *
 * 将来 role / scope を導入する場合は本クラスのみを差し替える。
 */
final class DefaultOperationPolicy implements OperationPolicy
{
    public function allows(string $operationId, OperationClassification $classification, OperationContext $context): bool
    {
        return match ($context->interface) {
            OperationInterface::Admin, OperationInterface::Api => $context->actorId !== null,
            OperationInterface::Cli, OperationInterface::System => true,
            OperationInterface::Mcp, OperationInterface::WebMcp => true,
        };
    }

    public function denialReason(string $operationId, OperationContext $context): string
    {
        if ($context->actorId === null) {
            return "この操作にはログインが必要です（{$operationId}）";
        }

        return "この操作を実行する権限がありません（{$operationId}）";
    }
}
