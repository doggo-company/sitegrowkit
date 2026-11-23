<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

/**
 * コメントの状態（Phase 9.13 / STAGE 26C・SPEC §B.3・OG3）。
 *
 * ## 4 つに分けている理由
 *
 * `spam` と `trash` は**由来が違う**。前者は自動判定で落ちたもの、
 * 後者は人が捨てたものである。混ぜると「スパム判定がどれくらい当たっているか」を
 * あとから測れなくなる。
 *
 * ## 物理削除を既定にしない
 *
 * `ContactStatus` / `SubmissionStatus` と同じ作法。`trash` は消えた状態ではなく、
 * **戻せる状態**である（OG7）。
 */
enum CommentStatus: string
{
    /** 承認待ち。公開面に出ない。**既定**（OG2） */
    case Pending = 'pending';

    /** 承認済み。公開面に出る */
    case Approved = 'approved';

    /** スパム判定。公開面に出ない。拾い直せる */
    case Spam = 'spam';

    /** ゴミ箱。公開面に出ない。**物理削除ではない** */
    case Trash = 'trash';

    /** 公開面に出すか */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '承認待ち',
            self::Approved => '承認済み',
            self::Spam => 'スパム',
            self::Trash => 'ゴミ箱',
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
