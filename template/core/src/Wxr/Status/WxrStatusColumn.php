<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Status;

use Illuminate\Database\Schema\Blueprint;

/**
 * 取り込み元の公開状態を残す列（Phase 9.14 / STAGE 27C・SPEC §C.8.3）。
 *
 * **列の名前と形を書くのはここだけ。** migration も型の生成も検査も、
 * 「どんな列か」を自分で書かない。3 か所に散ると、生成した型と既存の型で
 * 形が食い違い、後から突き合わせられなくなる（9.8 の `TrashColumns` と同じ作法）。
 *
 * ## なぜ投稿自身の行に持つのか
 *
 * 台帳（`import_records`）に持つと、取り消しで行が消えたとき
 * **投稿はゴミ箱に残るのに出自だけ消える**。
 * `wp_meta`（§C.7）に持つと、受け皿の無い型で元の状態ごと落ちる。
 * 投稿と同じ寿命が、この 3 つの中で最も長い。
 *
 * ## field として宣言しない
 *
 * 宣言しなければ `ContentValues::split()` の書き込み対象にならないので、
 * **編集フォームからは触れず、人が投稿を編集しても消えない**。
 * ただしそれは「誰も同じ名前の field を作らない」限りの話なので、
 * 名前は Content Type Builder と手書きの schema の両方で予約する。
 */
final class WxrStatusColumn
{
    /**
     * WXR に書かれていた `wp:status` をそのまま入れる。取り込んでいない行は `null`。
     *
     * `wp_` を付けるのは、これが**こちらの概念ではなく外から来た値**だからである。
     * §C.7 の `wp_meta` と同じ流儀で、中立な名前にすると
     * 値の語彙が WordPress のものだと読めなくなる。
     */
    public const NAME = 'wp_status';

    /** `wp_posts.post_status` の桁に合わせる */
    public const LENGTH = 20;

    public static function define(Blueprint $table): void
    {
        $table->string(self::NAME, self::LENGTH)->nullable();
    }
}
