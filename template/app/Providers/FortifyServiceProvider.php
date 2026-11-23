<?php

namespace App\Providers;

/* @chisel-registration */

use App\Actions\Fortify\CreateNewUser;
/* @end-chisel-registration */
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PasswordResetResponse::class, \App\Http\Responses\PasswordResetResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        $this->refuseInactiveUsers();
        /* @chisel-registration */
        Fortify::createUsersUsing(CreateNewUser::class);
        /* @end-chisel-registration */
    }

    /**
     * 無効化された利用者はログインさせない（STAGE #18A / SPEC §1A.9）。
     *
     * `EnsureUserIsActive` は「入ったあと」を止めるが、**入口そのものも閉じる**。
     * 資格情報が正しいことを確かめてから状態を見るので、
     * 「そのメールアドレスは存在するか」を推測させずに、
     * 本人にだけ具体的な理由を返せる。
     */
    private function refuseInactiveUsers(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', (string) $request->input('email'))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => 'このアカウントは無効化されています。管理者にお問い合わせください。',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            // 招待されて初めて設定する人には「再設定」と言わない（UX 一周 23 周目 #3）。
            // 有効なリンクを持っている人にだけ分かる（アドレスだけでは招待中かを漏らさない）
            'firstTime' => self::isFirstPasswordSetup($request),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        /* @chisel-email-verification */
        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));
        /* @end-chisel-email-verification */

        /* @chisel-registration */
        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));
        /* @end-chisel-registration */

        /* @chisel-2fa */
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));
        /* @end-chisel-2fa */

        /* @chisel-password-confirmation */
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
        /* @end-chisel-password-confirmation */
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        /* @chisel-2fa */
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
        /* @end-chisel-2fa */

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        /* @chisel-passkeys */
        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
        /* @end-chisel-passkeys */
    }

    /** 招待中（まだ一度もログインしていない）の人が、有効なリンクで開いたか */
    private static function isFirstPasswordSetup(Request $request): bool
    {
        $email = $request->query('email');
        $token = $request->route('token');

        if (! is_string($email) || ! is_string($token) || $email === '' || $token === '') {
            return false;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->isPendingInvitation()) {
            return false;
        }

        $broker = \Illuminate\Support\Facades\Password::broker();

        return $broker instanceof PasswordBroker && $broker->tokenExists($user, $token);
    }
}
