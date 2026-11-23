<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use Illuminate\Http\Request;

/**
 * bot / 濫用の検査をまとめて行う（Phase 9.12 §F.1 / 9.13 で一般化）。
 *
 * ## 順序
 *
 * honeypot → rate limit → Turnstile。**外部通信は最後**である。
 * 手元で分かることを先に済ませ、siteverify を無駄に叩かない。
 *
 * ## 最初に弾いた理由で止める
 *
 * 全部の検査を通してから理由を集めても、応答は 1 つしか返せない。
 * **弾いた事実は数える**が、中身は残さない。
 */
final class SpamProtection
{
    /** @var list<SpamCheck> */
    private readonly array $checks;

    public function __construct(
        private readonly SpamBlocks $blocks,
        HoneypotCheck $honeypot,
        RateLimitCheck $rateLimit,
        TurnstileCheck $turnstile,
    ) {
        $this->checks = [$honeypot, $rateLimit, $turnstile];
    }

    /** 通れば `null`、弾いたらその理由 */
    public function inspect(Request $request, SpamSubject $subject): ?SpamReason
    {
        foreach ($this->checks as $check) {
            $verdict = $check->check($request, $subject);

            if ($verdict->reason !== null) {
                $this->blocks->record($subject, $verdict->reason);

                return $verdict->reason;
            }
        }

        return null;
    }
}
