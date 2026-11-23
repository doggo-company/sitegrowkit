<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

/**
 * 1 つの検査の結果（Phase 9.12 §F / 9.13 で一般化）。
 *
 * `bool` を返さないのは、**何で弾いたかで応答が変わる**からである。
 */
final class SpamVerdict
{
    private function __construct(public readonly ?SpamReason $reason) {}

    public static function pass(): self
    {
        return new self(null);
    }

    public static function block(SpamReason $reason): self
    {
        return new self($reason);
    }

    public function blocked(): bool
    {
        return $this->reason !== null;
    }
}
