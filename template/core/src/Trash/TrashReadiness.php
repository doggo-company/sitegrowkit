<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

/**
 * その投稿タイプがゴミ箱に対応しているか（Phase 9.8 / STAGE 21B・SPEC §C.4.1）。
 *
 * **3 値である。** 「対応している / していない」の 2 値にすると、
 * **判定できなかった**場合が「対応していない」に倒れる。対応していない型は
 * 「trashed の行は存在し得ない」ので全件を出してよいが、判定できない型は
 * **trashed が混ざっているかどうかも分からない**。同じ扱いにすると、
 * ゴミ箱に入れたはずの投稿を公開してしまう。それは情報漏洩である。
 */
enum TrashReadiness: string
{
    /** 列があり、ゴミ箱として動く */
    case Ready = 'ready';

    /** 列が**無いと確認できた**（migration 前）。**異常ではない** */
    case Unmigrated = 'known-unmigrated';

    /** 確かめようとして失敗した（DB へ届かない / 権限が無い / 例外） */
    case Indeterminate = 'indeterminate';

    /**
     * ゴミ箱への書き込み（trash / restore / purge）を許してよいか。
     *
     * **`Ready` 以外はすべて拒否**する。列があるか分からないまま書かない。
     */
    public function allowsWrites(): bool
    {
        return $this === self::Ready;
    }

    /**
     * 通常の read を続けてよいか。
     *
     * `Unmigrated` は**続けてよい** — 列が無い以上 trashed の行は作れないので、
     * 全件が active である。`Indeterminate` だけが read を止める（SPEC §C.4.1.2）。
     */
    public function allowsReads(): bool
    {
        return $this !== self::Indeterminate;
    }

    /**
     * 管理画面に出す説明。**原因の詳細は出さない**（SPEC §C.4.1.2 / §G.2）。
     *
     * 例外文・SQL・接続情報・パスをここへ載せない。管理者に見せるのは
     * 「何が起きていて、次に何をすればよいか」だけである。
     */
    public function message(): ?string
    {
        return match ($this) {
            self::Ready => null,
            self::Unmigrated => 'この投稿タイプはまだゴミ箱に対応していません（migration を実行してください）。',
            self::Indeterminate => 'この投稿タイプがゴミ箱に対応しているか確認できませんでした。データベースの状態を確認してください。',
        };
    }
}
