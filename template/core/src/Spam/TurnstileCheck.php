<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use AgentCms\Core\Turnstile\TurnstileVerifier;
use Illuminate\Http\Request;

/**
 * Turnstile（Phase 9.12 §F.1 / 9.13 で一般化）。
 *
 * **鍵を設定してあるときだけ必須**にする（既存 Contact と同じ判断）。
 * 鍵を入れていない project で書き込み口が死ぬ方が害が大きい。
 * 設定してあるのに siteverify が落ちた場合は**通さない** — そこを
 * 「外部が落ちたら素通り」にすると、対策として意味が無くなる。
 */
final class TurnstileCheck implements SpamCheck
{
    public function __construct(private readonly TurnstileVerifier $verifier) {}

    public function check(Request $request, SpamSubject $subject): SpamVerdict
    {
        if ((string) config('agentcms.turnstile.secret_key', '') === '') {
            return SpamVerdict::pass();
        }

        $token = (string) $request->input('cf-turnstile-response', '');

        return $token !== '' && $this->verifier->verify($token, $request->ip())
            ? SpamVerdict::pass()
            : SpamVerdict::block(SpamReason::Bot);
    }
}
