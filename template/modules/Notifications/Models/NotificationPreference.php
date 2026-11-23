<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Notifications\NotificationType;

/**
 * ユーザーごとの通知 ON/OFF。行が存在しない type は NotificationType::defaultEnabled() に従う
 * （既定値から導出できる状態を全ユーザー × 全 type 分は永続化しない）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property bool $enabled
 */
final class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function isEnabledFor(int $userId, NotificationType $type): bool
    {
        $row = self::query()->where('user_id', $userId)->where('type', $type->value)->first();

        return $row === null ? $type->defaultEnabled() : $row->enabled;
    }
}
