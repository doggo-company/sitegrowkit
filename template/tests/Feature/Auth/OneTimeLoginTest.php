<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\OneTimeLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * セットアップ直後に「管理画面を開く」を押したら、ログイン済みで開く（UX 一周 3 周目 N1）。
 *
 * パスワードを打たずに入れる口なので、**入れてはいけない場合を先に**並べる。
 */
final class OneTimeLoginTest extends TestCase
{
    use RefreshDatabase;

    private function local(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_ローカルでは_1_回だけ入れる(): void
    {
        $this->local();
        $user = User::factory()->create();
        $token = OneTimeLogin::issue($user);

        $this->assertNotNull($token);

        $this->get('/login/once/'.$token)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        // 2 回目は入れない
        $this->get('/login/once/'.$token)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_ローカル以外では口ごと無い(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->create();

        $this->assertNull(OneTimeLogin::issue($user));
        $this->get('/login/once/'.str_repeat('a', 64))->assertNotFound();
        $this->assertGuest();
    }

    public function test_ローカルで発行しても_本番になったら使えない(): void
    {
        $this->local();
        $token = (string) OneTimeLogin::issue(User::factory()->create());

        $this->app['env'] = 'production';

        $this->get('/login/once/'.$token)->assertNotFound();
        $this->assertGuest();
    }

    public function test_期限が切れたら入れない(): void
    {
        $this->local();
        $token = (string) OneTimeLogin::issue(User::factory()->create(), 60);

        $this->travel(61)->seconds();

        $this->get('/login/once/'.$token)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_でたらめな_token_では入れない(): void
    {
        $this->local();
        User::factory()->create();

        $this->get('/login/once/'.str_repeat('x', 64))->assertRedirect(route('login'));
        $this->get('/login/once/short')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_無効化された人と_2_段階認証の人は入れない(): void
    {
        $this->local();
        $inactive = User::factory()->create();
        $inactive->forceFill(['is_active' => false])->save();
        $twoFactor = User::factory()->create();
        $twoFactor->forceFill(['two_factor_confirmed_at' => now()])->save();

        foreach ([$inactive, $twoFactor] as $user) {
            $token = (string) OneTimeLogin::issue($user);
            $this->get('/login/once/'.$token)->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_保存するのは_token_のハッシュだけ(): void
    {
        $this->local();
        $token = (string) OneTimeLogin::issue(User::factory()->create());

        $this->assertNull(cache()->get('agentcms:one-time-login:'.$token));
        $this->assertNotNull(cache()->get('agentcms:one-time-login:'.hash('sha256', $token)));
    }

    public function test_コマンドはリンクの_1_行だけを出す(): void
    {
        $this->local();
        $user = User::factory()->create();
        config(['agentcms.admin.email' => $user->email]);

        $this->assertSame(0, Artisan::call('agentcms:login-link'));
        $output = trim(Artisan::output());

        $this->assertMatchesRegularExpression('#^https?://[^\s]+/login/once/[A-Za-z0-9]{64}$#', $output);

        $token = substr($output, -64);
        $this->get('/login/once/'.$token)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_コマンドはローカル以外では何も出さずに失敗する(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->create();
        config(['agentcms.admin.email' => $user->email]);

        $this->assertSame(1, Artisan::call('agentcms:login-link'));
        $this->assertStringNotContainsString('/login/once/', Artisan::output());
    }
}
