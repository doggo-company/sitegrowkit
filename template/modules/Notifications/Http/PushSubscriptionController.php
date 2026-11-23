<?php

declare(strict_types=1);

namespace Modules\Notifications\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Models\PushSubscription;

/**
 * Push subscription の登録・削除。認証済みユーザー本人の subscription のみ操作できる
 * （削除クエリを常に user_id で絞る — 他ユーザーの endpoint を指定しても影響しない）。
 * endpoint はログへ出さない。
 */
final class PushSubscriptionController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // 同一端末の再登録は上書き（ブラウザが同じ endpoint を再送するケース）。
        // 別ユーザーで再ログインした端末は所有者を付け替える。
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint'])],
            [
                'user_id' => (int) $request->user()->id,
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
            ],
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
        ]);

        $deleted = PushSubscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        return response()->json(['deleted' => $deleted > 0]);
    }
}
