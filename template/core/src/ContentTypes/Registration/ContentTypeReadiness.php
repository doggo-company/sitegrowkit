<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

/**
 * `content_types` を読めるか（Phase 9.7 / SPEC §J.4）。
 *
 * **3 値である。** 「読めた / 読めない」の 2 値にすると、
 * 「表がまだ無い（migration 前）」と「DB へ届かない（障害）」が同じ扱いになり、
 * 画面に出せる説明も変わらなくなる。
 */
enum ContentTypeReadiness
{
    /** 表があり、読めた */
    case Ready;

    /** 表がまだ無い（migration 前）。**異常ではない** */
    case Unmigrated;

    /** 読もうとして失敗した（DB へ届かない / 権限が無い / 例外） */
    case Indeterminate;

    /**
     * 書き込みを許してよいか。
     *
     * **`Ready` 以外はすべて拒否**する。設定が読めない状態で
     * 「archived かどうか分からないまま書く」を許さない。
     */
    public function allowsWrites(): bool
    {
        return $this === self::Ready;
    }

    /** 画面に出す短い説明（原因だけを出さない — SPEC §J.8） */
    public function message(): ?string
    {
        return match ($this) {
            self::Ready => null,
            self::Unmigrated => '投稿タイプの設定はまだ利用できません（migration を実行してください）。',
            self::Indeterminate => '投稿タイプの設定を読めませんでした。データベースの状態を確認してください。',
        };
    }
}
