<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use RuntimeException;

/**
 * その投稿タイプはまだゴミ箱に対応していない（Phase 9.8 / STAGE 21B・SPEC §C.4.1.1）。
 *
 * **`Indeterminate` とは別物である。** こちらは「列が無いと**確認できた**」状態で、
 * 通常の読み取りは何ごともなく続く（trashed の行が存在し得ないため）。
 * 止まるのは**ゴミ箱そのものを扱おうとしたとき**だけ — ゴミ箱一覧を開く、
 * ゴミ箱へ入れる、戻す。
 *
 * 黙って 0 件を返さないのが要点である。「ゴミ箱は空だった」と
 * 「この型にゴミ箱はまだ無い」は、利用者にとってまったく違う。
 */
final class TrashNotSupported extends RuntimeException
{
    public function __construct(public readonly string $contentType)
    {
        parent::__construct("投稿タイプ [{$contentType}] はまだゴミ箱に対応していません");
    }

    /** 管理者・利用者へ見せてよい説明 */
    public function safeMessage(): string
    {
        return (string) TrashReadiness::Unmigrated->message();
    }
}
