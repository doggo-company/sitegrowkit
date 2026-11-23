<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * 監査記録（Phase 9.1B / STEP 8 — F-A4 の解消）。
 *
 * **記録するのは mutation のみ**（READ は量が増え個人情報も増えるため既定で記録しない）。
 * metadata には **パスワード・トークン・メール本文・フォームの機微値・secret を入れない**。
 */
interface AuditRecorder
{
    /** @param  array<string, mixed>  $metadata */
    public function record(
        string $operationId,
        OperationClassification $classification,
        OperationContext $context,
        Result $result,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
    ): void;
}
