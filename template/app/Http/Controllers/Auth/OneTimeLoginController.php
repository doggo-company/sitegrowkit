<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\OneTimeLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 1 回きりのログインリンクを受け取る（`App\Support\OneTimeLogin`）。
 *
 * ローカル以外では **404**（口が在ることも教えない）。
 * 使えないリンクはいつものログイン画面へ戻し、理由を一言出す。
 */
final class OneTimeLoginController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        abort_unless(OneTimeLogin::available(), 404);

        $user = OneTimeLogin::consume($token);

        if ($user === null) {
            return redirect()->route('login')->with(
                'status',
                'このリンクは使用済みか、期限が切れています。セットアップ画面に出ているメールアドレスとパスワードでログインしてください。',
            );
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to((string) config('fortify.home', '/dashboard'))
            ->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
