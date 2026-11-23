<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

/**
 * 投稿タイプの**運用状態**（Phase 9.7 / SPEC §F.1）。
 *
 * 利用者が動かす軸である。「作成が途中で落ちた」のようなシステム側の途中経過は
 * ここに入れない（`ContentTypeLifecycle` が持つ）。1 つの enum に混ぜると、
 * 読むたびにどちらを優先するか決めることになる。
 */
enum ContentTypeStatus: string
{
    /** 日常運用に出す */
    case Active = 'active';

    /** 利用者が無効にした。**何も消えていない**ので復元できる */
    case Archived = 'archived';

    /**
     * 保存された値を読む。**未知の値は `Archived` として扱う**（fail-closed / SPEC §J.4）。
     *
     * 未知の値を `Active` に倒すと、意味の分からない状態の型へ書けてしまう。
     * 「読めるが書けない」側へ倒すほうが安全である。
     */
    public static function fromStorage(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Archived;
    }

    /** 保存された値が既知か（診断用。未知なら一覧に「不明な状態」と出す） */
    public static function recognizes(string $raw): bool
    {
        return self::tryFrom($raw) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
