<?php

declare(strict_types=1);

namespace Modules\Notifications\Push;

use Modules\Notifications\Models\PushSubscription;

/**
 * Web Push 送信の Transport 境界。実送信（VAPID / Push Service への HTTP）と
 * アプリケーションロジックを分離し、テストでは FakePushTransport に差し替える。
 * ブラウザへの実 Push は CI / E2E で安定して再現できないため、この境界より先は
 * ローカル実機確認（docs/benchmarks/phase-3.md の Manual Acceptance）で検証する。
 */
interface PushTransport
{
    /** VAPID キーが設定済みで送信可能か */
    public function configured(): bool;

    /** @param string $payload JSON 文字列（暗号化前の平文はロック画面表示前提の最小情報のみ） */
    public function send(PushSubscription $subscription, string $payload): PushResult;
}
