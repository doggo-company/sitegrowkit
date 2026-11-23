<?php

declare(strict_types=1);

namespace Modules\Notifications;

/**
 * 通知種別。新しい通知を追加するときは case を増やし、label/group/defaultEnabled に
 * 1 行ずつ追加するだけでよい（送信経路・設定画面・preference 判定はすべてこの enum を参照する）。
 * 将来の例: backup.failed / deploy.completed / deploy.failed / security.warning / update.available / review.request
 * 将来ユーザー種別（クライアント / 制作者）で表示を変える場合は visibleTypes() の絞り込みを拡張する。
 */
enum NotificationType: string
{
    case ContactNew = 'contact.new';
    case ContactEmailFailed = 'contact.email_failed';
    case CommentNew = 'comment.new';

    public function label(): string
    {
        return match ($this) {
            self::ContactNew => '新規お問い合わせ',
            self::ContactEmailFailed => 'メール通知失敗',
            self::CommentNew => '新着コメント',
        };
    }

    /** 設定画面のグルーピング（将来: system / deploy / security を追加） */
    public function group(): string
    {
        return match ($this) {
            self::ContactNew, self::ContactEmailFailed => 'お問い合わせ',
            self::CommentNew => 'コメント',
        };
    }

    /** preference 行が無いユーザーへの既定値 */
    public function defaultEnabled(): bool
    {
        return true;
    }

    /**
     * 設定画面に表示する type 一覧。現状は全 case。
     *
     * @return list<self>
     */
    public static function visibleTypes(): array
    {
        return self::cases();
    }
}
