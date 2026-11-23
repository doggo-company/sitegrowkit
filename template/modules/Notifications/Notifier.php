<?php

declare(strict_types=1);

namespace Modules\Notifications;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Models\PushSubscription;
use Modules\Notifications\Push\PushResult;
use Modules\Notifications\Push\PushTransport;
use Throwable;

/**
 * 通知の入口。呼び出し元（Contact pipeline 等）の処理を決して失敗させない —
 * どんな例外もここで止める（問い合わせデータ最優先の原則）。
 *
 * - preference で type を無効にしたユーザーには送らない
 * - 無効 subscription（404/410）は削除し再試行しない
 * - payload はロック画面表示前提: 本文全文・個人情報を含めない
 */
final class Notifier
{
    public function __construct(private readonly PushTransport $transport) {}

    public function send(NotificationType $type, string $title, string $body, ?string $url = null): void
    {
        try {
            if (! $this->transport->configured()) {
                return;
            }

            $payload = json_encode(
                ['type' => $type->value, 'title' => $title, 'body' => $body, 'url' => $url],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            foreach ($this->recipients($type) as $subscription) {
                match ($this->transport->send($subscription, $payload)) {
                    PushResult::Sent => $subscription->forceFill(['last_used_at' => now()])->save(),
                    PushResult::Invalid => $subscription->delete(),
                    PushResult::Failed => null, // 一時的エラー: subscription は保持、この場では再試行しない
                };
            }
        } catch (Throwable $e) {
            Log::error('Push dispatch failed', ['type' => $type->value, 'error' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, PushSubscription> */
    private function recipients(NotificationType $type): mixed
    {
        $overrides = NotificationPreference::query()
            ->where('type', $type->value)
            ->where('enabled', ! $type->defaultEnabled())
            ->pluck('user_id');

        return PushSubscription::query()
            ->when(
                $type->defaultEnabled(),
                fn ($q) => $q->whereNotIn('user_id', $overrides),
                fn ($q) => $q->whereIn('user_id', $overrides),
            )
            ->get();
    }
}
