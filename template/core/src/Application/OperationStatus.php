<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * Application Service の結果種別（Phase 9.1B / STEP 1）。
 *
 * **HTTP status も Inertia の RedirectResponse も Application 層へ持ち込まない。**
 * 各 interface（Admin / API / MCP）への変換は adapter の責務。
 */
enum OperationStatus: string
{
    case Success = 'success';
    case ValidationError = 'validation_error';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Conflict = 'conflict';
    case ConfirmationRequired = 'confirmation_required';

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
