<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Models\PushSubscription;
use Tests\TestCase;

/**
 * 仕様 SECURITY の検証: subscription の登録・削除は認証済みユーザー本人のみ。
 * 他ユーザーの subscription は endpoint を知っていても操作できない。
 */
final class PushSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/send/abc123';

    private const SUBSCRIPTION_INPUT = [
        'endpoint' => self::ENDPOINT,
        'keys' => ['p256dh' => 'test-p256dh-key', 'auth' => 'test-auth-secret'],
    ];

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_guest_cannot_register_subscription(): void
    {
        $this->postJson('/admin/notifications/subscriptions', self::SUBSCRIPTION_INPUT)
            ->assertUnauthorized();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_authenticated_user_can_register_subscription(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->postJson('/admin/notifications/subscriptions', self::SUBSCRIPTION_INPUT)
            ->assertCreated();

        $subscription = PushSubscription::sole();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame('test-p256dh-key', $subscription->p256dh);
    }

    public function test_same_endpoint_reregistration_upserts_instead_of_duplicating(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->postJson('/admin/notifications/subscriptions', self::SUBSCRIPTION_INPUT);
        $this->actingAs($user)->postJson('/admin/notifications/subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'rotated-key', 'auth' => 'rotated-auth'],
        ])->assertCreated();

        $subscription = PushSubscription::sole();
        $this->assertSame('rotated-key', $subscription->p256dh);
    }

    public function test_same_user_can_register_multiple_devices(): void
    {
        $user = $this->admin();

        foreach (['https://push.example.test/device-1', 'https://push.example.test/device-2'] as $endpoint) {
            $this->actingAs($user)->postJson('/admin/notifications/subscriptions', [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ])->assertCreated();
        }

        $this->assertSame(2, PushSubscription::where('user_id', $user->id)->count());
    }

    public function test_user_can_delete_own_subscription(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->postJson('/admin/notifications/subscriptions', self::SUBSCRIPTION_INPUT);

        $this->actingAs($user)
            ->deleteJson('/admin/notifications/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_user_cannot_delete_other_users_subscription(): void
    {
        $owner = $this->admin();
        $attacker = $this->admin();
        $this->actingAs($owner)->postJson('/admin/notifications/subscriptions', self::SUBSCRIPTION_INPUT);

        $this->actingAs($attacker)
            ->deleteJson('/admin/notifications/subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['deleted' => false]);

        // 所有者の subscription は無傷で残る
        $this->assertSame(1, PushSubscription::where('user_id', $owner->id)->count());
    }

    public function test_invalid_subscription_payload_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/notifications/subscriptions', [
                'endpoint' => 'not-a-url',
                'keys' => ['p256dh' => 'k'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.auth']);
    }

    public function test_notification_preferences_can_be_saved_per_user(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put('/admin/notifications/preferences', [
                'preferences' => [
                    ['type' => 'contact.new', 'enabled' => false],
                    ['type' => 'contact.email_failed', 'enabled' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertFalse(NotificationPreference::query()
            ->where('user_id', $user->id)->where('type', 'contact.new')->sole()->enabled);
        $this->assertTrue(NotificationPreference::query()
            ->where('user_id', $user->id)->where('type', 'contact.email_failed')->sole()->enabled);
    }

    public function test_unknown_notification_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/notifications/preferences', [
                'preferences' => [['type' => 'evil.type', 'enabled' => true]],
            ])
            ->assertSessionHasErrors('preferences.0.type');
    }

    public function test_settings_page_renders_with_types(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk();
    }
}
