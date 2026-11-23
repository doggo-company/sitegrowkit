<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 束が「触られた」かを決める（Phase 9.14 / STAGE 27E-2・SPEC §D.3）。
 *
 * ## 束は 1 つの単位である
 *
 * 記事と、その版・割り当て・その記事宛のコメント・受付設定は 1 つの束である。
 * **判定はここ 1 か所に置く。** 消す側と数える側が別々に判定すると、
 * 「触られていないと数えて、消すときには触られている」が起こり得る。
 *
 * ## 投稿の行を動かさない人の操作がある
 *
 * (c) 人が移行後に書いたコメントも、(d) 人が扉を開けた行為も、投稿の行を動かさない。
 * `updated_at` だけを見る判定は、**その 2 つを見逃す**。
 *
 * ## 台帳の行をそのまま受け取らない
 *
 * 宛先は `BundleTarget` で受ける。素の行を受けると、判定の中で列名を綴ることになり、
 * **台帳の形の変更がここまで届く**。呼ぶのは `ContentReverter` だけである。
 */
final class BundleJudge
{
    /** 束が触られた理由（報告の内訳の鍵。**識別子の形**しか使わない） */
    public const POST = 'post';

    public const OWNED_COMMENT = 'comment';

    public const HUMAN_COMMENT = 'human_comment';

    public const SETTING = 'setting';

    /**
     * 触られたなら理由、触られていなければ `null`。
     *
     * **最初に当たった理由を 1 つだけ返す。** 2 つ当たっても人は同じ判断（残す）を
     * するので、内訳が細かくなるだけで直す先は増えない。
     */
    public function judge(Model $post, BundleTarget $target): ?string
    {
        $type = $target->contentType;
        $id = $target->contentId;
        $importedAt = $target->importedAt;

        // (a) 投稿の行が動いた。版と割り当ては投稿の行を動かさずには変えられない
        if ($this->postMoved($post, $importedAt)) {
            return self::POST;
        }

        $comments = DB::table('content_comments')
            ->where('content_type', $type)
            ->where('content_id', $id)
            ->get();

        $owned = $this->ownedCommentIds($type, $id);

        foreach ($comments as $comment) {
            if (! isset($owned[(string) $comment->id])) {
                // (c) 人が移行後に書いた。**投稿の行は動いていない**
                return self::HUMAN_COMMENT;
            }

            if (Carbon::parse((string) $comment->updated_at)->greaterThan($owned[(string) $comment->id])) {
                // (b) 取り込んだコメントを人が承認・編集した
                return self::OWNED_COMMENT;
            }
        }

        return $this->settingMoved($type, $id, $importedAt) ? self::SETTING : null;
    }

    /**
     * (a) — 投稿の行が動いたか。
     *
     * ゴミ箱に在るのも「人が入れた」なので動いた側である。
     */
    private function postMoved(Model $post, Carbon $importedAt): bool
    {
        if ($post->getAttribute(TrashColumns::DELETED_AT) !== null) {
            return true;
        }

        $updated = $post->getAttribute('updated_at');

        return $updated !== null && Carbon::parse($updated)->greaterThan($importedAt);
    }

    /**
     * この取り込みが作ったコメントの id => その行を書いた時刻。
     *
     * **台帳が持っている。** 対象側の列で「取り込みが作ったか」を見分けようとすると、
     * 人が同じ形のコメントを書いたときに区別できない。
     *
     * @return array<string, Carbon>
     */
    private function ownedCommentIds(string $type, int $contentId): array
    {
        $ids = DB::table('content_comments')
            ->where('content_type', $type)
            ->where('content_id', $contentId)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        if ($ids === []) {
            return [];
        }

        $owned = [];

        $rows = DB::table('import_records')
            ->where('kind', ImportKind::Comment->value)
            ->where('created_by_import', true)
            ->whereIn('target_id', $ids)
            ->get();

        foreach ($rows as $row) {
            $owned[(string) $row->target_id] = Carbon::parse((string) $row->created_at);
        }

        return $owned;
    }

    /**
     * (d) — 受付設定の行が、取り込みが残した形と違うか。
     *
     * ## 行が無いことは、判定に使えない
     *
     * 取り込みが行を書くのは **`closed` のときだけ**（§C.4.3）で、
     * その事実はどこにも記録していない（27D-5c — 鍵が組で単独の id が無い）。
     * `rollback` は WXR も読まないので、取り消しの時点では
     *
     * - 「取り込みも書いていない」（正常。**WordPress の記事はほとんどが `open`**）
     * - 「取り込みが書いたが、人が『型に従う』で消した」（守るべき）
     *
     * を**区別できない**。ここで「行が無い = 消えている = 触られた」と読むと、
     * open だった記事の束がすべて触られた扱いになり、**取り消しがほぼ何もできなくなる**。
     * (d) が守ろうとしたものより、その損の方がはるかに大きい。
     *
     * ## だから、行が在るときだけ判定する
     *
     * **`is_open = true` は取り込みが書かない形**なので、人が「開く」を押した証拠になる。
     * 人の操作としては「開く」の方がずっと多い。
     *
     * 捕まらないのは「『型に従う』で消した」1 通りだけで、その場合に束が消えても
     * **復元手段は WXR そのもの**である（`--i-have-a-backup` が手元にあることを確かめている）。
     */
    private function settingMoved(string $type, int $contentId, Carbon $importedAt): bool
    {
        $row = DB::table(CommentGate::SETTINGS_TABLE)
            ->where('content_type', $type)
            ->where('content_id', $contentId)
            ->first();

        if ($row === null) {
            return false;
        }

        if ((bool) $row->is_open) {
            // 取り込みは `false` しか書かない。`true` は人が押した形である
            return true;
        }

        return $row->updated_at !== null
            && Carbon::parse((string) $row->updated_at)->greaterThan($importedAt);
    }
}
