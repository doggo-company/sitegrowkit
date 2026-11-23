<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Trash\TrashScope;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 取り込んだ記事の束を取り消す（Phase 9.14 / STAGE 27E-2・SPEC §D.3・§I.1）。
 *
 * ## 束ごと物理削除する。ゴミ箱は経由しない
 *
 * ゴミ箱へ入れると綴りが解放されず（OG5）、purge の口も無い（OG8）ので、
 * **取り消してやり直す道が塞がる**（F-10）。復元手段は取り込みに使った
 * WXR ファイルそのもので、`--i-have-a-backup` がそれが手元にあることを確かめている。
 *
 * ## purge の口を作ったわけではない（OG8 との区別）
 *
 * 消せるのは**自分の batch が作った、触られていない束**だけで、対象の表は
 * `RollbackWxrTest::ALLOWED` に固定してある。共通の削除サービスは作らない。
 *
 * ## 消すのは「実際に戻した対象」の台帳の行だけ
 *
 * batch の行をまとめて消すと、「触られていたので戻さなかった」対象の記録まで消え、
 * 再実行が `absent` と読んで**生きていて人が編集した投稿の複製を増やす**（§I.1）。
 *
 * ## 掴んでから、もう一度判定する
 *
 * 判定と削除の間に人が触れる隙がある。行を掴んだうえで**同じ transaction の中で
 * 判定し直す**。そこで触られていれば `kept_conflict` として残す。
 *
 * ## `final` にしない
 *
 * 競合は本番では作れないので、テストが `beforeDelete()` で注入する。
 * 注入点が無いと「競合のときに残す」を**一度も動かさずに**緑にできてしまう。
 */
class ContentReverter
{
    public function __construct(
        private readonly ContentTypeResolver $types,
        private readonly BundleJudge $judge,
    ) {}

    /**
     * batch の記事の束を取り消し、結果を数えて返す。
     *
     * @return array{counts: array<string, int>, unrevertable: list<string>}
     */
    public function revert(string $batchId): array
    {
        $counts = [];
        $unrevertable = [];

        $this->revertBundles($batchId, $counts, $unrevertable);
        $this->revertLooseComments($batchId, $counts);

        return ['counts' => $counts, 'unrevertable' => $unrevertable];
    }

    /**
     * テストが**取り消しの最中の人の操作**を注入する点。本番では何もしない。
     *
     * 行を掴んだ後・判定し直す前に呼ぶ。ここより前に置くと掴む前の話になり、
     * 後に置くと判定を通り抜けてしまう。
     */
    protected function beforeDelete(string $type, int $id): void {}

    /**
     * 記事の束（投稿・版・割り当て・受付設定・その記事宛のコメント）。
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $unrevertable
     */
    private function revertBundles(string $batchId, array &$counts, array &$unrevertable): void
    {
        /*
         * **kind と所有権で絞る。** `created_by_import = false` の行（元から在った対象を
         * 使い回した記録）は、こちらが作っていないので対象を戻さない。
         * その行の始末は `LedgerSweeper` が最後に行う。
         */
        $rows = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Content->value)
            ->where('created_by_import', true)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $target = new BundleTarget(
                (string) $row->target_type,
                (int) $row->target_id,
                Carbon::parse((string) $row->created_at),
            );

            $model = $this->find($target);

            if (! $model instanceof Model) {
                /*
                 * **行を残す。** 完全削除の経路はアプリに無い（OG8）ので、ここへ来るのは
                 * DB を直接触ったか型ごと消えた場合だけ。残せば再実行が
                 * `LedgerTargetMissing` として止め、人が決められる。
                 */
                $this->count($counts, 'kept_missing');

                continue;
            }

            if ($this->judge->judge($model, $target) !== null) {
                // 人が後から触ったものは戻さない（§D.3）。台帳の行も残す
                $this->count($counts, 'kept_touched');

                continue;
            }

            if ($this->deleteBundle($target)) {
                $this->count($counts, 'reverted_content');
            } else {
                /*
                 * 掴んでから判定し直したら触られていた。**例外にして全体を止めない**（§A.3）。
                 * 台帳の行は消さない — 戻せていない。
                 */
                $this->count($counts, 'kept_conflict');
                $unrevertable[] = (string) $row->external_id;
            }
        }
    }

    /**
     * `skip_existing` の記事に付けた所有コメント（§D.3 の表）。
     *
     * 記事はこちらの物ではないので、**コメント単体で判定する**。
     * 束が残った記事のコメントはここへ来ない — 束として 1 度数えてあるものを
     * コメントの数で二度数えると、内訳が実態より重く見える。
     *
     * ## 子から先に見る（F-12）
     *
     * 台帳の行の順は `CommentTree::creationOrder()`（1 段目が先）なので、
     * **降順に見れば返信が先**になる。`TermReverter` と同じ理屈で、順の正本を
     * 2 つ持たない。
     *
     * ## 残っている子が在れば、親は残す
     *
     * 人が書いた返信（所有していない）でも、こちらが入れた返信を人が
     * 承認して残した場合でも、**親を消せば返信が孤児になる**。
     * どちらも「この会話に人が手を入れた」なので `kept_touched` にする。
     *
     * @param  array<string, int>  $counts
     */
    private function revertLooseComments(string $batchId, array &$counts): void
    {
        $rows = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Comment->value)
            ->where('created_by_import', true)
            // **返信から先に**（`creationOrder()` の逆順 — F-12）
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // まだ残っている束（= 戻さなかった記事）。そのコメントには触らない
        $kept = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Content->value)
            ->where('created_by_import', true)
            ->get()
            ->map(static fn (object $row): string => $row->target_type."\0".$row->target_id)
            ->flip();

        foreach ($rows as $row) {
            $comment = DB::table('content_comments')->where('id', (int) $row->target_id)->first();

            if ($comment === null) {
                $this->count($counts, 'kept_missing');

                continue;
            }

            if ($kept->has((string) $comment->content_type."\0".(string) $comment->content_id)) {
                continue;
            }

            $importedAt = Carbon::parse((string) $row->created_at);

            if ($comment->updated_at !== null && Carbon::parse((string) $comment->updated_at)->greaterThan($importedAt)) {
                // 人が承認・編集した（§D.3 の (b) と同じ基準）
                $this->count($counts, 'kept_touched');

                continue;
            }

            if (DB::table('content_comments')->where('parent_id', (int) $comment->id)->exists()) {
                /*
                 * **返信が残っている親は消さない**（F-12）。返信が孤児になる。
                 * 子は先に見ているので、ここに残っているのは
                 * 「人が書いた返信」か「触られていたので残した返信」だけである。
                 */
                $this->count($counts, 'kept_touched');

                continue;
            }

            DB::transaction(function () use ($row): void {
                DB::table('content_comments')->where('id', (int) $row->target_id)->delete();
                DB::table('import_records')->where('id', (int) $row->id)->delete();
            });

            $this->count($counts, 'reverted_comment');
        }
    }

    /**
     * 束を丸ごと消す。**掴んで、判定し直して、同じ transaction で消す。**
     *
     * 分けると、その間に落ちたときに「対象は消えたのに台帳が `exact` を返す」
     * 記事が残り、再実行が `skip_existing` と読んで入れ直せなくなる。
     */
    private function deleteBundle(BundleTarget $target): bool
    {
        $table = $this->types->newModel($target->contentType)->getTable();

        return (bool) DB::transaction(function () use ($target, $table): bool {
            // 掴む。掴めなければ既に消えている
            $held = DB::table($table)->where('id', $target->contentId)->lockForUpdate()->first();

            if ($held === null) {
                return false;
            }

            $this->beforeDelete($target->contentType, $target->contentId);

            $model = $this->find($target);

            if (! $model instanceof Model || $this->judge->judge($model, $target) !== null) {
                return false;
            }

            /*
             * **コメントの行を先に読む。** 消した後では、その台帳の行を引く鍵が無くなる。
             * ここに残っているのは機械が入れたコメントだけである — 人が書いた
             * コメントが 1 件でもあれば (c) で束ごと残っている。
             */
            $commentIds = DB::table('content_comments')
                ->where('content_type', $target->contentType)
                ->where('content_id', $target->contentId)
                ->pluck('id')
                ->map(intval(...))
                ->all();

            if ($commentIds !== []) {
                /*
                 * **返信から先に消す**（F-12）。1 文でまとめて消すと、行をどの順で
                 * 消すかは DB が決めるので `fk_comment_parent` に当たり得る。
                 * `PruneCommentsCommand::deleteByStatus()` が同じ対処をしている。
                 *
                 * 2 段で足りるのは、9.13 が**返信を 1 段までに畳む**（OG4 —
                 * `CommentTree`）ためである。入れ子を深くする日が来たら、
                 * ここは**葉から順に辿る形へ書き換える**こと。
                 */
                $scoped = fn (): Builder => DB::table('content_comments')
                    ->whereIn('id', $commentIds);

                $scoped()->whereNotNull('parent_id')->delete();
                $scoped()->whereNull('parent_id')->delete();

                /*
                 * **batch で絞らない。** 消えた行を指す台帳の記録が残ると、
                 * 次の取り込みがその id を `exact` と読み、無い行を使おうとする。
                 */
                DB::table('import_records')
                    ->where('kind', ImportKind::Comment->value)
                    ->whereIn('target_id', array_map(strval(...), $commentIds))
                    ->delete();
            }

            DB::table(RevisionWriter::TABLE)
                ->where('content_type', $target->contentType)
                ->where('content_id', $target->contentId)
                ->delete();

            // **primary key で消す**（隙間ロックを作らないため）。
            // `where(content_type, content_id)` で消すと `idx_assignment_entry` の
            // 範囲走査になり、**X,GAP が無関係な content_id へ届く**（実測 1205）。
            // **依存**: id を集めてから消すまでの間に行が足されると消し漏らす。
            // ここは直前で **Content Entry 行を `lockForUpdate()` 済み**の同一
            // transaction なので、その窓で誰も足せない。**この lock を外すと静かに壊れる。**
            $assignments = DB::table('taxonomy_term_assignments')
                ->where('content_type', $target->contentType)
                ->where('content_id', $target->contentId)
                ->pluck('id')
                ->all();

            if ($assignments !== []) {
                DB::table('taxonomy_term_assignments')->whereIn('id', $assignments)->delete();
            }

            DB::table(CommentGate::SETTINGS_TABLE)
                ->where('content_type', $target->contentType)
                ->where('content_id', $target->contentId)
                ->delete();

            DB::table($table)->where('id', $target->contentId)->delete();

            DB::table('import_records')
                ->where('kind', ImportKind::Content->value)
                ->where('target_type', $target->contentType)
                ->where('target_id', (string) $target->contentId)
                ->delete();

            return true;
        });
    }

    private function find(BundleTarget $target): ?Model
    {
        if (! $this->types->knows($target->contentType)) {
            // 型ごと消えている。対象は確かめられないので「見つからない」側
            return null;
        }

        return $this->types->modelClass($target->contentType)::query()
            ->withoutGlobalScope(TrashScope::NAME)
            ->whereKey($target->contentId)
            ->first();
    }

    /** @param  array<string, int>  $counts */
    private function count(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
}
