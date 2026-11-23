<?php

declare(strict_types=1);

namespace Modules\Contact\Models;

/** 対応の進行状態（通知成否は別軸 — ADR-0005） */
enum ContactStatus: string
{
    case Received = 'received';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Received => '未対応',
            self::InProgress => '対応中',
            self::Completed => '対応済み',
            self::Spam => 'スパム',
        };
    }
}
