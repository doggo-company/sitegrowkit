<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use Illuminate\Support\Facades\DB;

/**
 * 所有していない台帳の行を片付ける（Phase 9.14 / STAGE 27E-2・SPEC §D.3）。
 *
 * ## 対象には触れず、行だけ消す
 *
 * `reuse` と alias の行（`created_by_import = false`）が記録しているのは
 * 「**この取り込みがその対象を使った**」という事実だけである。
 * 取り込みを取り消せばその事実は無くなるので、行は消える。
 * 対象は元から在ったものなので、1 行も触らない。
 *
 * ## メディアも同じ規則で掃く（27E-3 で除外を外した）
 *
 * 27E-2 の間は `media` を除外していた。取り消しの口がまだ無く、行だけ消すと
 * **対象のファイルが残ったまま台帳から消える**（取り消せない添付ができる）ためである。
 * `MediaReverter` が入ったのでその理由は無くなった。所有していない
 * メディアの行 — 使い回した添付 — は、他の種類と同じく**対象に触れず行だけ**消える。
 *
 * ## 最後に呼ぶ
 *
 * `ContentReverter` と `TermReverter` と `MediaReverter` が終わってから呼ぶ。
 * 先に消すと、alias の行が指す代表の id を辿れなくなる。
 */
final class LedgerSweeper
{
    /** @return array<string, int> */
    public function sweep(string $batchId): array
    {
        $dropped = DB::table('import_records')
            ->where('batch_id', $batchId)
            ->where('created_by_import', false)
            ->delete();

        return $dropped > 0 ? ['dropped_records' => $dropped] : [];
    }
}
