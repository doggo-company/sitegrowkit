<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Models\PushSubscription;
use Modules\Notifications\NotificationType;
use Modules\Notifications\Notifier;
use Modules\Notifications\Push\FakePushTransport;
use Modules\Notifications\Push\PushResult;
use Tests\TestCase;

/**
 * 仕様 DELIVERY FAILURE / NOTIFICATION SETTINGS の検証:
 * 無効 subscription は削除して再試行しない・一時的エラーでは保持・
 * preference OFF のユーザーへ送らない・VAPID 未設定では静かにスキップ。
 */
final class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionFor(User $user, string $suffix = 'a'): PushSubscription
    {
        $endpoint = "https://push.example.test/{$user->id}/{$suffix}";

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'k',
            'auth' => 'a',
        ]);
    }

    public function test_sends_to_all_devices_of_enabled_users_and_records_last_used_at(): void
    {
        $user = User::factory()->create();
        $sub1 = $this->subscriptionFor($user, 'device-1');
        $sub2 = $this->subscriptionFor($user, 'device-2');
        $transport = new FakePushTransport;

        (new Notifier($transport))->send(NotificationType::ContactNew, '新しいお問い合わせ', '山田さんからお問い合わせが届きました', '/admin/contact/1');

        $this->assertCount(2, $transport->sent);
        $this->assertNotNull($sub1->refresh()->last_used_at);
        $this->assertNotNull($sub2->refresh()->last_used_at);

        $payload = json_decode($transport->sent[0]['payload'], true);
        $this->assertSame('新しいお問い合わせ', $payload['title']);
        $this->assertSame('contact.new', $payload['type']);
    }

    public function test_does_not_send_to_users_who_disabled_the_type(): void
    {
        $enabled = User::factory()->create();
        $disabled = User::factory()->create();
        $this->subscriptionFor($enabled);
        $this->subscriptionFor($disabled);
        NotificationPreference::query()->create(['user_id' => $disabled->id, 'type' => 'contact.new', 'enabled' => false]);
        $transport = new FakePushTransport;

        (new Notifier($transport))->send(NotificationType::ContactNew, 't', 'b');

        $this->assertCount(1, $transport->sent);
        $this->assertSame(PushSubscription::where('user_id', $enabled->id)->sole()->id, $transport->sent[0]['subscription_id']);
    }

    public function test_invalid_subscription_is_deleted_and_not_retried(): void
    {
        $user = User::factory()->create();
        $expired = $this->subscriptionFor($user, 'expired');
        $alive = $this->subscriptionFor($user, 'alive');
        $transport = new FakePushTransport;
        $transport->results[$expired->id] = PushResult::Invalid;

        (new Notifier($transport))->send(NotificationType::ContactNew, 't', 'b');

        $this->assertNull(PushSubscription::find($expired->id));
        $this->assertNotNull(PushSubscription::find($alive->id));
    }

    public function test_temporary_failure_keeps_subscription(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscriptionFor($user);
        $transport = new FakePushTransport;
        $transport->results[$sub->id] = PushResult::Failed;

        (new Notifier($transport))->send(NotificationType::ContactNew, 't', 'b');

        $this->assertNotNull(PushSubscription::find($sub->id));
        $this->assertNull($sub->refresh()->last_used_at);
    }

    public function test_unconfigured_transport_skips_silently(): void
    {
        $user = User::factory()->create();
        $this->subscriptionFor($user);
        $transport = new FakePushTransport(isConfigured: false);

        (new Notifier($transport))->send(NotificationType::ContactNew, 't', 'b');

        $this->assertCount(0, $transport->sent);
    }
}
