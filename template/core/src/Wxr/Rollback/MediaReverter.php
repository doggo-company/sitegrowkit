<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Contracts\MediaIntake;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use Illuminate\Support\Facades\DB;

/**
 * 取り込んだメディアを取り消す（Phase 9.14 / STAGE 27E-3・SPEC §D.3・ADR-0020）。
 *
 * ## 使われていれば残す。**判定は自分で数えない**
 *
 * 使用中かどうかを決めるのは `MediaUsageInspector` で、それを呼ぶのは
 * `MediaIntake` の実装側である。ここで数え直すと、参照の種類が増えた日に
 * **片方だけ古い数え方のまま**になる（`TermReverter` と同じ理屈）。
 *
 * その走査は id だけでなく **URL による参照**も見る（27E-3）。
 * 本文に URL が埋まった画像を「使われていない」と答える走査のままでは、
 * 取り消しが**生きている記事の画像を消す**。
 *
 * ## 口が無ければ、何もしない
 *
 * `MediaIntake` が bind されていない環境では、メディアは**そもそも入っていない**
 * （`MediaImporter` が入口で止めている）。台帳に行があるのに口が無い形は、
 * module を後から外した場合にだけ起こる。**行を残して報告する** —
 * 対象を消せないのに記録だけ消すと、取り消せない実体が残る。
 *
 * ## 実体を消すのは行を消した後
 *
 * 順序は実装側（`DeleteMedia`）が持っている。ここは 1 件ずつ渡すだけで、
 * **消す手順を書き写さない**。
 */
final class MediaReverter
{
    public function __construct(
        /** bind されていなければ `null`（実装は project 側にある） */
        private readonly ?MediaIntake $intake = null,
    ) {}

    /**
     * batch のメディアを取り消し、結果を数えて返す。
     *
     * @return array<string, int>
     */
    public function revert(string $batchId, OperationContext $context): array
    {
        $rows = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('kind', ImportKind::Media->value)
            ->where('created_by_import', true)
            ->orderBy('id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $key = $this->revertOne((string) $row->target_id, $context);

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function revertOne(string $mediaId, OperationContext $context): string
    {
        if ($this->intake === null) {
            // 対象を消せないので記録も消さない（口が戻れば続きから取り消せる）
            return 'kept_missing';
        }

        if (! DB::table('media')->where('id', (int) $mediaId)->exists()) {
            /*
             * 人が管理画面から消したか、DB を直接触った。**行を残す** —
             * 再実行が「対象が無い」と言えるようにしておく。
             */
            return 'kept_missing';
        }

        if (! $this->intake->forget($mediaId, $context)) {
            // まだ使われている（記事の本文が指している・別の欄が指している）
            return 'kept_referenced';
        }

        DB::table('import_records')
            ->where('kind', ImportKind::Media->value)
            ->where('target_id', $mediaId)
            ->delete();

        return 'reverted_media';
    }
}
