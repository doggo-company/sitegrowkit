<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * メールアドレスが未確認のままログインしたら、確認のメールを送る（UX 一周 23 周目 #2）。
 *
 * 招待された人がパスワードを設定してログインすると「お送りしたメールのリンクを開いて」と出るのに、
 * 確認のメールはどこからも送られていなかった（「確認メールを再送する」を押して初めて届いた）。
 * 招待は「メールで送る」と「URL を画面で渡す」の 2 通りがあり、後者はアドレスの持ち主を確かめていない
 * ので、招待だけで確認済みにはしない。代わりに、未確認のログインのたびに確認メールを送る。
 *
 * 続けてログインし直しても何通も送らないよう、1 人あたり {@see self::THROTTLE_MINUTES} 分に 1 通まで。
 * 送れなくてもログインは止めない（画面の「確認メールを再送する」で送り直せる）。
 */
final class SendVerificationOnLogin
{
    public const THROTTLE_MINUTES = 10;

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->hasVerifiedEmail()) {
            return;
        }

        if (! Cache::add('verify-mail-on-login:'.$user->getKey(), true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            // アドレスも本文も残さない。何が起きたかだけ
            Log::warning('確認メールをログイン時に送れませんでした', ['user_id' => $user->getKey(), 'error' => $e::class]);
        }
    }
}
