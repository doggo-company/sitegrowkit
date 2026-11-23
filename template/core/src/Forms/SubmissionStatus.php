<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

/**
 * 受信の対応状態（Phase 9.12 / STAGE 25D・SPEC §D.2）。
 *
 * **既存の問い合わせ（`ContactStatus`）と同じ語彙を使う。** 片方が「対応済み」で
 * もう片方が「完了」だと、同じ仕事を 2 つの言葉で覚えることになる。
 *
 * 通知の成否は別の軸である（`admin_notified_at` / `autoreply_sent_at`）。
 * 「メールが送れたか」と「人が対応したか」を 1 つの enum に混ぜない。
 */
enum SubmissionStatus: string
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

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $s): array => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
