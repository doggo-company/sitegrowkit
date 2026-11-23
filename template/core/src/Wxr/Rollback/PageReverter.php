<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\PageSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * 取り込んだページを取り消す（release-20260909 / wxr-pages）。
 *
 * ## 人が触ったページは戻さない（§D.3 と同じ）
 *
 * 判定は**時刻ではなく内容**で行う。取り込んだ直後の姿（`import_records.snapshot`）と
 * 今の姿（`PageSnapshot::of()`）が文字列として一致するものだけ消す。
 * `updated_at` で見ると、取り込んだ同じ秒の編集を見逃して物理削除してしまう
 * （レビュー再現 2026-09-09）。snapshot が無い行（列を足す前の取り込み）も残す —
 * 比べられないものは消さない。
 *
 * ## 束と台帳を同じ transaction で消す
 *
 * `pages` と `page_versions` は FK で結ばれているので、版から先に消す。
 * 台帳の行も同じ transaction で消す — 分けると、その間に落ちたときに
 * 「消えたページを指す台帳の行」が残り、再実行が `LedgerTargetMissing` で止まる。
 */
final class PageReverter
{
    /**
     * @return array{counts: array<string, int>, unrevertable: list<string>}
     */
    public function revert(string $batchId): array
    {
        $counts = [];
        $unrevertable = [];

        $rows = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Page->value)
            ->where('created_by_import', true)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $pageId = (int) $row->target_id;
            $expected = $row->snapshot ?? null;

            $reverted = DB::transaction(function () use ($row, $pageId, $expected): ?bool {
                // 掴む。掴めなければ既に消えている
                $held = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();

                if ($held === null) {
                    return null;
                }

                // **掴んだ後に**今の姿を取る。掴む前に取ると、その隙間の編集を通り抜ける
                if (! is_string($expected) || $expected === '' || PageSnapshot::of($pageId) !== $expected) {
                    return false;
                }

                DB::table('page_versions')->where('page_id', $pageId)->delete();
                DB::table('pages')->where('id', $pageId)->delete();
                DB::table('import_records')->where('id', (int) $row->id)->delete();

                return true;
            });

            if ($reverted === null) {
                // 行を残す。再実行が `LedgerTargetMissing` として止め、人が決められる
                $counts['kept_missing'] = ($counts['kept_missing'] ?? 0) + 1;
            } elseif ($reverted === false) {
                // 人が後から触った（または比べられない）。台帳の行も残す
                $counts['kept_touched'] = ($counts['kept_touched'] ?? 0) + 1;
                $unrevertable[] = (string) $row->external_id;
            } else {
                $counts['reverted_page'] = ($counts['reverted_page'] ?? 0) + 1;
            }
        }

        return ['counts' => $counts, 'unrevertable' => $unrevertable];
    }
}
