<?php

declare(strict_types=1);

namespace AgentCms\Core\Ordering;

use Illuminate\Database\Schema\Blueprint;

/**
 * 手で決めた掲載順を持つ列（P1 ②）。
 *
 * **列の名前と形を書くのはここだけ。** migration も型の生成も、
 * 「どんな列か」を自分で書かない（`TrashColumns` / `WxrStatusColumn` と同じ作法）。
 * 3 か所に散ると、生成した型と既存の型で形が食い違う。
 *
 * ## なぜ `sort_order` か
 *
 * 既に `taxonomy_terms` / `field_groups` / `menu_items` がこの名前を使っている。
 * `order` は SQL の予約語なので使わない。**同じ意味には同じ名前**を当てる。
 *
 * ## なぜ既定が 0 か
 *
 * **列を足しただけで並びが変わってはいけない。** 全行が同じ値なら第二キー
 * （公開日の新しい順）がそのまま効くので、**いままでと同じ並びのまま**になる。
 * 0 は「まだ手で並べていない」の意味でもあり、**新しい投稿が先頭に入る**理由でもある
 * （`ContentOrder` を参照）。
 *
 * ## field として宣言しない
 *
 * 宣言しなければ編集フォームの書き込み対象にならないので、
 * **人が投稿を編集しても掲載順が消えない**。名前は `ContentSchema::RESERVED_COLUMN_KEYS`
 * で予約してあり、手書きの schema でも Content Type Builder でも取れない。
 */
final class SortOrderColumn
{
    public const NAME = 'sort_order';

    /**
     * `unsignedInteger` に index。
     *
     * index を張るのは、**一覧の読みが必ずこの列で並べる**ためである
     * （並べ替えを 1 度もしていないサイトでも、第一キーとして読まれる）。
     */
    public static function define(Blueprint $table): void
    {
        $table->unsignedInteger(self::NAME)->default(0)->index();
    }
}
