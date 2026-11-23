<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use Database\Seeders\ContactFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class UxReleaseRound24OtherTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_is_told_to_ask_owner_instead_of_opening_settings(): void
    {
        $this->seed(ContactFormSeeder::class);
        app()->forgetScopedInstances();
        $user = User::factory()->editor()->create();
        $steps = $this->actingAs($user)->get('/dashboard')->inertiaProps('firstSteps.steps');
        foreach (['site', 'menu', 'inbox'] as $key) {
            $step = collect($steps)->firstWhere('key', $key);
            $this->assertSame('', $step['href'], $key);
            $this->assertStringContainsString('オーナーに頼んでください', $step['description']);
        }
    }

    public function test_first_password_setup_success_is_not_called_reset(): void
    {
        $user = User::factory()->editor()->create(['invited_at' => now(), 'last_login_at' => null]);
        $this->post(route('password.update'), $this->passwordInput($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'パスワードを設定しました。');
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('status', 'パスワードを設定しました。'));
    }

    public function test_existing_user_password_reset_keeps_reset_message(): void
    {
        $user = User::factory()->create(['invited_at' => now()->subDay(), 'last_login_at' => now()]);
        $this->post(route('password.update'), $this->passwordInput($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'パスワードを再設定しました。');
    }

    public function test_inner_rate_limit_keeps_contact_input_on_a_japanese_429_screen(): void
    {
        $this->prepareContact();
        config(['agentcms.forms.rate_limit_per_minute' => 1]);
        $this->post('/forms/contact', $this->contactInput('一通目'))->assertRedirect();
        $this->assertRateLimitedInput('/forms/contact');
        $this->assertSame(1, FormSubmission::query()->count());
    }

    public function test_outer_rate_limit_keeps_contact_input_on_a_japanese_429_screen(): void
    {
        $this->prepareContact();
        // The honeypot consumes the route throttle while bypassing the inner limit.
        for ($i = 0; $i < 30; $i++) {
            $this->post('/forms/contact', $this->contactInput('bot') + ['contact-url' => 'bot'])->assertRedirect();
        }
        $this->assertRateLimitedInput('/forms/contact');
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_default_form_rate_limit_keeps_input_and_japanese_message(): void
    {
        $this->prepareContact();
        app(SettingsStore::class)->put(['contact_sample_view_off' => true]);
        config(['agentcms.forms.rate_limit_per_minute' => 1]);
        $this->post('/forms/contact', $this->contactInput('一通目'))->assertRedirect();
        $this->assertRateLimitedInput('/forms/contact');
    }

    public function test_confirmation_route_throttle_keeps_input_and_japanese_message(): void
    {
        $this->prepareContact();
        Form::query()->where('key', 'contact')->update(['confirm_step' => true]);
        for ($i = 0; $i < 30; $i++) {
            $this->post('/forms/contact/confirm', $this->contactInput('相談'))->assertOk();
        }
        $this->assertRateLimitedInput('/forms/contact/confirm');
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_legacy_contact_route_throttle_keeps_input_and_japanese_message(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/contact', [])->assertRedirect();
        }
        $this->assertRateLimitedInput('/contact');
    }

    private function assertRateLimitedInput(string $url): void
    {
        $response = $this->post($url, $this->contactInput('消したくない相談 <script>alert(1)</script>'));
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('少し待ってからもう一度送ってください')
            ->assertSee('name="name"', false)
            ->assertSee('value="山田花子"', false)
            ->assertSee('value="hanako@example.test"', false)
            ->assertSee('消したくない相談 &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Too Many Requests');
    }

    private function prepareContact(): void
    {
        Mail::fake();
        config(['agentcms.turnstile.secret_key' => '', 'agentcms.turnstile.site_key' => '']);
        $this->seed(ContactFormSeeder::class);
        app()->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private function passwordInput(User $user): array
    {
        return [
            'token' => Password::broker()->createToken($user), 'email' => $user->email,
            'password' => 'A-valid-password-for-test-30', 'password_confirmation' => 'A-valid-password-for-test-30',
        ];
    }

    /** @return array<string, mixed> */
    private function contactInput(string $message): array
    {
        return ['name' => '山田花子', 'email' => 'hanako@example.test', 'subject' => 'ご相談',
            'message' => $message, 'privacy' => true];
    }
}
