<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * 操作の危険度（Phase 9.1B / STEP 6）。
 *
 * `Destructive` と `ExternalEffect` は **人間の確認を必要とし得る**。
 * AI（MCP）からの実行では確認なしに実行させない。
 */
enum OperationClassification: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
    case ExternalEffect = 'external_effect';

    /** 監査へ記録すべきか（READ は量が多く個人情報も増えるため既定で記録しない） */
    public function shouldAudit(): bool
    {
        return $this !== self::Read;
    }

    /** 既定で人間の確認を要するか */
    public function requiresConfirmation(): bool
    {
        return $this === self::Destructive || $this === self::ExternalEffect;
    }
}
