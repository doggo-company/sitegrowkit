<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use Illuminate\Database\Schema\Blueprint;

/**
 * ゴミ箱の列（Phase 9.8 / STAGE 21B・SPEC §B・§C.2、Owner Decision OG1 = (b)）。
 *
 * **列の名前と形を書くのはここだけ。** migration も generator も、
 * 「どんな列か」を自分で書かない。3 か所に散ると、生成した型と既存の型で
 * 形が食い違い、後から突き合わせられなくなる。
 *
 * 保管の状態（`active` / `trashed`）は **`status` field とは別の軸**である。
 * `status` は利用者が編集フォームで選ぶ**公開の状態**で、こちらは**保管の状態**。
 * 1 つの enum に 2 つの軸を入れない（SPEC §B）。
 */
final class TrashColumns
{
    /** ゴミ箱に入れた時刻。`null` なら active */
    public const DELETED_AT = 'deleted_at';

    /** ゴミ箱に入れた利用者。退会などで消えても投稿は残るので nullable */
    public const TRASHED_BY = 'trashed_by';

    /** 楽観ロックの版（OG11）。既存行は 0 から始まる */
    public const LOCK_VERSION = 'lock_version';

    /** @var list<string> */
    public const ALL = [self::DELETED_AT, self::TRASHED_BY, self::LOCK_VERSION];

    /**
     * 新しく作るテーブルへ列を足す（`ContentTypeGenerator` が出す migration と同じ形）。
     *
     * `deleted_at` に index を張るのは、**既定の read が毎回この列で絞る**ためである
     * （global scope が `deleted_at is null` を付ける）。
     */
    public static function define(Blueprint $table): void
    {
        $table->timestamp(self::DELETED_AT)->nullable()->index();
        $table->unsignedBigInteger(self::TRASHED_BY)->nullable();
        $table->unsignedBigInteger(self::LOCK_VERSION)->default(0);
    }
}
