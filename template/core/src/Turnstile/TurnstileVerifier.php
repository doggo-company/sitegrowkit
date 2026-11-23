<?php

declare(strict_types=1);

namespace AgentCms\Core\Turnstile;

/**
 * Extension Point: Turnstile 検証。
 * テストでは fake 実装へ差し替え可能（コンテナ bind）。
 */
interface TurnstileVerifier
{
    public function verify(string $token, ?string $ip = null): bool;
}
