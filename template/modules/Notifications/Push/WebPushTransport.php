<?php

declare(strict_types=1);

namespace Modules\Notifications\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Modules\Notifications\Models\PushSubscription;
use Throwable;

/**
 * minishlink/web-push による実送信。VAPID 秘密鍵は .env のみに置く（コード・Git 禁止）。
 * ログへ endpoint 全体を出さない（Push Service の URL は実質的な宛先秘密情報）。
 */
final class WebPushTransport implements PushTransport
{
    private ?WebPush $client = null;

    public function configured(): bool
    {
        return config('notifications.vapid.public_key') !== ''
            && config('notifications.vapid.private_key') !== '';
    }

    public function send(PushSubscription $subscription, string $payload): PushResult
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                ]),
                $payload,
            );

            if ($report->isSuccess()) {
                return PushResult::Sent;
            }
            if ($report->isSubscriptionExpired()) {
                return PushResult::Invalid;
            }

            Log::warning('Push send failed', ['subscription_id' => $subscription->id, 'reason' => $report->getReason()]);

            return PushResult::Failed;
        } catch (Throwable $e) {
            Log::warning('Push send failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            return PushResult::Failed;
        }
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush([
            'VAPID' => [
                'subject' => (string) config('notifications.vapid.subject'),
                'publicKey' => (string) config('notifications.vapid.public_key'),
                'privateKey' => (string) config('notifications.vapid.private_key'),
            ],
        ]);
    }
}
