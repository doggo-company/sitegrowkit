<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 同じ相手からの連続送信（Phase 9.12 §F.3 / 9.13 で一般化）。
 *
 * ## 生の IP を鍵にしない
 *
 * 鍵は `sha256(APP_KEY + ip)`。cache に生の IP が残ると、
 * 「保存していない」と言いながら別の場所に置いていることになる。
 *
 * ## どこまでを「同じ相手」とみなすかは呼ぶ側が決める
 *
 * `SpamSubject::$id` を鍵へ混ぜる。フォームは 1 本ずつ数えたい
 * （1 つのフォームへの連打で別のフォームが送れなくなるのは行き過ぎ）が、
 * コメントは記事ごとに数えても意味が無い（荒らしは別の記事へも書く）。
 * 前者は form の id を、後者は投稿タイプの id を渡す。
 */
final class RateLimitCheck implements SpamCheck
{
    public function check(Request $request, SpamSubject $subject): SpamVerdict
    {
        if ($subject->ratePerMinute <= 0) {
            return SpamVerdict::pass();
        }

        $key = "spam:{$subject->kind}:{$subject->subjectId()}:".hash(
            'sha256',
            (string) config('app.key').'|'.(string) $request->ip(),
        );

        if (RateLimiter::tooManyAttempts($key, $subject->ratePerMinute)) {
            return SpamVerdict::block(SpamReason::RateLimit);
        }

        RateLimiter::hit($key, 60);

        return SpamVerdict::pass();
    }
}
