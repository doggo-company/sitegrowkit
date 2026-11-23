<?php

declare(strict_types=1);

namespace Modules\Notifications\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\NotificationType;
use Modules\Notifications\Push\PushTransport;

/**
 * 通知設定画面。type 一覧は NotificationType が単一情報源
 * （画面・保存・送信判定のすべてが enum を参照し、ここに if を足さない）。
 */
final class NotificationSettingsController
{
    public function show(Request $request, PushTransport $transport): Response
    {
        $userId = (int) $request->user()->id;

        $groups = [];
        foreach (NotificationType::visibleTypes() as $type) {
            $groups[$type->group()][] = [
                'type' => $type->value,
                'label' => $type->label(),
                'enabled' => NotificationPreference::isEnabledFor($userId, $type),
            ];
        }

        return Inertia::render('admin/notifications/Settings', [
            'groups' => collect($groups)->map(fn ($items, $group) => ['group' => $group, 'items' => $items])->values(),
            'vapidPublicKey' => (string) config('notifications.vapid.public_key'),
            'pushConfigured' => $transport->configured(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', Rule::in(array_column(NotificationType::visibleTypes(), 'value'))],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($validated['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => (int) $request->user()->id, 'type' => $preference['type']],
                ['enabled' => $preference['enabled']],
            );
        }

        return back()->with('success', '通知設定を保存しました');
    }
}
