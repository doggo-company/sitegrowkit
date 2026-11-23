<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SessionInvalidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 無効化・権限変更された利用者を締め出す（STAGE #18A / Correction 3）。
 *
 * 毎リクエストで 2 つを確かめる。
 *
 * | 見るもの | 目的 |
 * | --- | --- |
 * | `is_active` | 無効化された利用者を入れない |
 * | `session_epoch` | **session driver に依存せず**、権限が動いた session を弾く |
 *
 * 世代番号は初回の認証済みリクエストで session へ記録する。
 * 以降、`users.session_epoch` が進んでいれば不一致になり、
 * file / redis / cookie など行を消せない driver でもログアウトできる。
 */
final class EnsureUserIsActive
{
    public function __construct(private readonly SessionInvalidator $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->reject(
                $request,
                'このアカウントは無効化されています。管理者にお問い合わせください。',
                invalidateOthers: true,
            );
        }

        $known = $request->session()->get(SessionInvalidator::SESSION_KEY);

        /*
         * **記録の無い session は信用しない**（Correction 2-2）。
         *
         * 以前はここで「無ければ今の世代を入れる」としていたが、それだと
         * ログイン直後の 1 リクエストぶん、権限変更を素通りできる窓が残る
         * （変更後の世代を初期値として受け入れてしまうため）。
         * 世代は `StampSessionEpoch` が **ログインの瞬間に**刻むので、
         * 正規の経路で入った session がここへ null で来ることはない。
         */
        if ($known === null || (int) $known !== (int) $user->session_epoch) {
            return $this->reject(
                $request,
                '権限が変更されたため、ログインし直してください。',
                invalidateOthers: false,
            );
        }

        return $next($request);
    }

    private function reject(Request $request, string $message, bool $invalidateOthers): Response
    {
        $user = $request->user();

        if ($invalidateOthers && $user instanceof User) {
            $this->sessions->forgetUser((int) $user->getKey());
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
