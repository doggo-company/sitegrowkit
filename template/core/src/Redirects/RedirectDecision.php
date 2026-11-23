<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * 「どこへ、どの status で、何段辿って送るか」（Phase 9.10 / STAGE 23B）。
 *
 * シミュレータ（SPEC §F.3）と実際の転送で **同じものを返す**。
 * 見せた結果と起きることがずれないのは、2 つが同じ値を見ているからである。
 */
final readonly class RedirectDecision
{
    public function __construct(
        public RedirectRule $rule,
        public string $target,
        /** 何段辿ったか（1 なら直接） */
        public int $hops,
    ) {}

    public function status(): RedirectStatus
    {
        return $this->rule->status();
    }
}
