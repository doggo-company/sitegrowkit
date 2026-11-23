<?php

declare(strict_types=1);

namespace AgentCms\Core\Turnstile;

/**
 * テスト用の Turnstile 検証フェイク。
 * テストから `app()->instance(TurnstileVerifier::class, new FakeVerifier(pass: false))` で差し替える。
 */
final class FakeVerifier implements TurnstileVerifier
{
    public function __construct(public bool $pass = true) {}

    public function verify(string $token, ?string $ip = null): bool
    {
        return $this->pass;
    }
}
