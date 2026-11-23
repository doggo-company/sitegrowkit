<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\SessionInvalidator;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;

/**
 * ログインした瞬間に session 世代を刻む（STAGE #18A / Correction 2-2）。
 *
 * `EnsureUserIsActive` が「記録が無ければ今の世代を入れる」で済ませていた頃は、
 * ログイン直後の 1 リクエストぶんだけ **権限変更を素通りできる窓**が空いていた。
 * ここで刻むことでその窓が無くなり、middleware は
 * 「記録が無い session は信用しない」と言い切れるようになる。
 *
 * 対象は `Login` event なので、パスワード・パスキー・remember-me の
 * 自動再ログインまで **経路によらず**同じ扱いになる。
 */
final class StampSessionEpoch
{
    public function __construct(
        private readonly SessionInvalidator $sessions,
        private readonly Session $session,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->sessions->remember($this->session, $event->user);
        }
    }
}
