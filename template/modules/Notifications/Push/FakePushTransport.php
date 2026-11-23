<?php

declare(strict_types=1);

namespace Modules\Notifications\Push;

use Modules\Notifications\Models\PushSubscription;

/**
 * テスト用 Transport。送信内容を記録し、subscription ごとに結果を差し替えられる。
 */
final class FakePushTransport implements PushTransport
{
    /** @var list<array{subscription_id: int, payload: string}> */
    public array $sent = [];

    /** @var array<int, PushResult> subscription id → 返す結果（未指定は Sent） */
    public array $results = [];

    public function __construct(private bool $isConfigured = true) {}

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function send(PushSubscription $subscription, string $payload): PushResult
    {
        $this->sent[] = ['subscription_id' => $subscription->id, 'payload' => $payload];

        return $this->results[$subscription->id] ?? PushResult::Sent;
    }
}
