<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use AgentCms\Core\Turnstile\FakeVerifier;
use AgentCms\Core\Turnstile\TurnstileVerifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Contact\Models\ContactSubmission;
use Modules\Notifications\Models\PushSubscription;
use Modules\Notifications\Push\FakePushTransport;
use Modules\Notifications\Push\PushResult;
use Modules\Notifications\Push\PushTransport;
use RuntimeException;
use Tests\TestCase;

/**
 * 仕様 PHASE 3 NOTIFICATION EVENTS の検証:
 * Email と Push は独立 — どちらの失敗も Contact 保存と互いの実行を妨げない。
 * メール失敗時は「メール通知失敗」Push が送られる（submission 1 件につき 1 回のみ）。
 */
final class ContactPushTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_INPUT = [
        'name' => '山田太郎',
        'email' => 'taro@example.com',
        'subject' => 'estimate',
        'message' => 'お見積もりをお願いします。',
        'cf-turnstile-response' => 'dummy-token',
    ];

    private FakePushTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(TurnstileVerifier::class, new FakeVerifier(pass: true));
        $this->transport = new FakePushTransport;
        $this->app->instance(PushTransport::class, $this->transport);
    }

    private function createSubscribedAdmin(): PushSubscription
    {
        $user = User::factory()->create();

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => "https://push.example.test/{$user->id}",
            'endpoint_hash' => PushSubscription::hashEndpoint("https://push.example.test/{$user->id}"),
            'p256dh' => 'k',
            'auth' => 'a',
        ]);
    }

    public function test_new_contact_sends_push_with_minimal_payload(): void
    {
        Mail::fake();
        $this->createSubscribedAdmin();

        $this->post('/contact', self::VALID_INPUT)->assertSessionHas('contact_success');

        $this->assertCount(1, $this->transport->sent);
        $payload = json_decode($this->transport->sent[0]['payload'], true);
        $this->assertSame('contact.new', $payload['type']);
        $this->assertSame('新しいお問い合わせ', $payload['title']);
        $this->assertSame('山田太郎さんからお問い合わせが届きました', $payload['body']);
        $this->assertStringContainsString('/admin/contact/', $payload['url']);

        // Privacy: 本文全文・メールアドレスを payload に含めない（ロック画面表示前提）
        $this->assertStringNotContainsString('お見積もり', $this->transport->sent[0]['payload']);
        $this->assertStringNotContainsString('taro@example.com', $this->transport->sent[0]['payload']);
    }

    public function test_email_failure_still_sends_push_and_records_failure_push(): void
    {
        // Email 失敗 → Contact は保存され、新規 Push + メール失敗 Push の 2 通が送られる
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        $this->createSubscribedAdmin();

        $this->post('/contact', self::VALID_INPUT)->assertSessionHas('contact_success');

        $submission = ContactSubmission::sole();
        $this->assertSame('SMTP down', $submission->admin_notification_error);

        $this->assertCount(2, $this->transport->sent);
        $types = array_map(fn (array $s) => json_decode($s['payload'], true)['type'], $this->transport->sent);
        $this->assertSame(['contact.new', 'contact.email_failed'], $types);
    }

    public function test_push_failure_does_not_lose_contact_or_block_response(): void
    {
        Mail::fake();
        $sub = $this->createSubscribedAdmin();
        $this->transport->results[$sub->id] = PushResult::Failed;

        $this->post('/contact', self::VALID_INPUT)
            ->assertRedirect('/contact')
            ->assertSessionHas('contact_success');

        $submission = ContactSubmission::sole();
        $this->assertSame('山田太郎', $submission->name);
        $this->assertNotNull($submission->admin_notified_at);
    }

    public function test_push_transport_exception_does_not_lose_contact(): void
    {
        Mail::fake();
        $this->createSubscribedAdmin();
        $throwing = new class implements PushTransport
        {
            public function configured(): bool
            {
                return true;
            }

            public function send(PushSubscription $subscription, string $payload): PushResult
            {
                throw new RuntimeException('push service unreachable');
            }
        };
        $this->app->instance(PushTransport::class, $throwing);

        $this->post('/contact', self::VALID_INPUT)->assertSessionHas('contact_success');

        $this->assertSame(1, ContactSubmission::count());
    }

    public function test_expired_subscription_is_removed_during_contact_push(): void
    {
        Mail::fake();
        $sub = $this->createSubscribedAdmin();
        $this->transport->results[$sub->id] = PushResult::Invalid;

        $this->post('/contact', self::VALID_INPUT)->assertSessionHas('contact_success');

        $this->assertSame(0, PushSubscription::count());
        $this->assertSame(1, ContactSubmission::count());
    }
}
