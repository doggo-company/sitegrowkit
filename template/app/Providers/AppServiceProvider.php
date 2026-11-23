<?php

namespace App\Providers;

use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Menus\RouteAvailability;
use AgentCms\Core\Pages\Page;
use App\Listeners\RecordAuthenticationActivity;
use App\Listeners\RememberPageImageAlt;
use App\Listeners\SendVerificationOnLogin;
use App\Listeners\StampSessionEpoch;
use App\Support\BundledRouteAvailability;
use App\Support\RoleOperationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Modules\Notifications\Push\PushTransport;
use Modules\Notifications\Push\WebPushTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Web Push の Transport 境界（テストは FakePushTransport に差し替える）
        $this->app->singleton(PushTransport::class, WebPushTransport::class);

        /*
         * 認可をロール対応へ差し替える（STAGE #18A）。
         *
         * core の `OperationPolicy` 境界はそのままで、実装だけを project 側にする。
         * Admin / API / MCP のすべてがこの 1 つを通るので、
         * 画面で隠したかどうかに関係なく同じ判定になる。
         */
        $this->app->singleton(OperationPolicy::class, RoleOperationPolicy::class);

        // メニューの組み込みページも、同梱ページの出し分け（非公開など）に従わせる
        $this->app->bind(RouteAvailability::class, BundledRouteAvailability::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Page::updated([app(RememberPageImageAlt::class), 'handle']);
        $this->configureDefaults();
        $this->configureStagingMailSafety();
        $this->configureAuthenticationActivity();
    }

    /**
     * 認証イベントを行動履歴へ残す（STAGE #18A）。
     *
     * ログインの成功・失敗・ログアウト・パスワード再設定は、
     * 事故調査でいちばん必要になる記録なので Application Service とは別に拾う。
     */
    protected function configureAuthenticationActivity(): void
    {
        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'handleLogin']);

        /*
         * ログインの瞬間に session 世代を刻む（Correction 2-2）。
         * middleware の後追いに任せると、ログイン直後の 1 リクエストだけ
         * 権限変更を素通りできる窓が空く。
         */
        Event::listen(Login::class, StampSessionEpoch::class);
        // 未確認のアドレスでログインしたら確認メールを送る（招待された人に届いていなかった・UX 一周 23 周目 #2）
        Event::listen(Login::class, SendVerificationOnLogin::class);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'handleFailed']);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [RecordAuthenticationActivity::class, 'handlePasswordReset']);

        // アカウント保護の設定変更（Correction 5）。秘密そのものは記録しない
        Event::listen(PasswordUpdatedViaController::class, [RecordAuthenticationActivity::class, 'handlePasswordUpdated']);
        Event::listen(TwoFactorAuthenticationEnabled::class, [RecordAuthenticationActivity::class, 'handleTwoFactorEnabled']);
        Event::listen(TwoFactorAuthenticationConfirmed::class, [RecordAuthenticationActivity::class, 'handleTwoFactorConfirmed']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [RecordAuthenticationActivity::class, 'handleTwoFactorDisabled']);
        Event::listen(RecoveryCodesGenerated::class, [RecordAuthenticationActivity::class, 'handleRecoveryCodesGenerated']);
        Event::listen(PasskeyRegistered::class, [RecordAuthenticationActivity::class, 'handlePasskeyRegistered']);
        Event::listen(PasskeyDeleted::class, [RecordAuthenticationActivity::class, 'handlePasskeyDeleted']);
    }

    /**
     * staging では実顧客へメールを送らない（自動返信含め全メールを安全な宛先へ）。
     * リダイレクト先が決められない場合は送信自体を log mailer へ落とす。
     */
    protected function configureStagingMailSafety(): void
    {
        if (! $this->app->environment('staging')) {
            return;
        }

        $redirectTo = (string) (config('agentcms.mail.staging_redirect_to')
            ?: config('agentcms.mail.admin_to'));

        if ($redirectTo !== '' && $redirectTo !== 'admin@example.test') {
            Mail::alwaysTo($redirectTo);

            return;
        }

        Config::set('mail.default', 'log');
        Log::warning('staging: MAIL_STAGING_REDIRECT_TO 未設定のためメールは log 出力のみ');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // 本番・staging は HTTPS 強制（docs/SECURITY.md）
        if ($this->app->isProduction() || $this->app->environment('staging')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
